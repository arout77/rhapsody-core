<?php
namespace Rhapsody\Core\Proxy;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

class ProxyGenerator
{
    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = $cacheDir;
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }

    public function generate(string $targetClass): string
    {
        $reflection = new ReflectionClass($targetClass);

        if ($reflection->isFinal()) {
            throw new \Exception("Cannot proxy final class: {$targetClass}");
        }

        $isInterface = $reflection->isInterface();
        $extends     = '';
        $implements  = '';

        if ($isInterface) {
            $implements = 'implements \\' . $targetClass . ', \\Rhapsody\\Core\\Proxy\\LazyProxyInterface';
        } else {
            $extends    = 'extends \\' . $targetClass;
            $implements = 'implements \\Rhapsody\\Core\\Proxy\\LazyProxyInterface';
        }

        // Determine namespace and short name
        if (str_contains($targetClass, 'class@anonymous')) {
            $baseNamespace  = 'Rhapsody\\Core\\Proxy\\Generated\\Anonymous';
            $shortClassName = 'AnonymousProxy';
        } else {
            $baseNamespace  = 'Rhapsody\\Core\\Proxy\\Generated\\' . str_replace('\\', '_', trim($targetClass, '\\'));
            $shortClassName = $this->getProxyShortName($targetClass);
        }

        $fullClassName = $baseNamespace . '\\' . $shortClassName;

        // Build methods
        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic() || $method->isFinal() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $visibility = $method->isProtected() ? 'protected' : 'public';

            // Build parameter list
            $params = [];
            foreach ($method->getParameters() as $param) {
                $type     = $param->getType();
                $typeStr  = $this->formatType($type);
                $paramStr = $typeStr . '$' . $param->getName();
                if ($param->isDefaultValueAvailable()) {
                    $default   = var_export($param->getDefaultValue(), true);
                    $paramStr .= ' = ' . $default;
                }
                $params[] = $paramStr;
            }
            $paramsStr = implode(', ', $params);

            // Return type
            $returnType  = $method->getReturnType();
            $returnStr   = $this->formatReturnType($returnType);

            $isVoid = false;
            if ($returnType) {
                $typeName = $this->getTypeName($returnType);
                if ($typeName === 'void') {
                    $isVoid = true;
                }
            }

            $methodName = $method->getName();
            $argList    = $this->buildArgList($method);

            // Always delegate to wrapped object
            if ($isVoid) {
                $body = '$this->wrappedObject->' . $methodName . '(' . $argList . ');';
            } else {
                $body = 'return $this->wrappedObject->' . $methodName . '(' . $argList . ');';
            }

            $methods[] = <<<METHOD
    {$visibility} function {$methodName}({$paramsStr}){$returnStr}
    {
        \$this->initialize();
        {$body}
    }
METHOD;
        }

        $methodsCode = implode("\n\n", $methods);
        if (! empty($methodsCode)) {
            $methodsCode = "\n" . $methodsCode . "\n";
        }

        $code = <<<PHP
<?php

namespace {$baseNamespace};

use Rhapsody\Core\Proxy\LazyProxyInterface;

class {$shortClassName} {$extends} {$implements}
{
    private ?\Closure \$initializer = null;
    private ?object \$wrappedObject = null;

    public function __construct(\Closure \$initializer)
    {
        \$this->initializer = \$initializer;
    }

    private function initialize(): void
    {
        if (\$this->wrappedObject === null && \$this->initializer !== null) {
            \$initializer = \$this->initializer;
            \$initializer(\$this->wrappedObject, \$this, 'initialize', [], \$this->initializer);
            \$this->initializer = null;
        }
    }

    public function getWrappedObject(): ?object
    {
        \$this->initialize();
        return \$this->wrappedObject;
    }

    public function setInitializer(?\Closure \$initializer): void
    {
        \$this->initializer = \$initializer;
    }

    public function getInitializer(): ?\Closure
    {
        return \$this->initializer;
    }

    public function isProxyInitialized(): bool
    {
        return \$this->wrappedObject !== null;
    }
{$methodsCode}
}
PHP;

        // Write to file (with hash-based filename to avoid invalid characters)
        $hash         = md5($targetClass);
        $safeFilename = $hash . '_' . $shortClassName . '.php';
        $filePath     = $this->cacheDir . '/' . $safeFilename;

        file_put_contents($filePath, $code);
        require_once $filePath;

        return $fullClassName;
    }

    /**
     * Format a type for use in a parameter or return type declaration.
     */
    private function formatType(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $subType) {
                $parts[] = $this->formatSingleType($subType);
            }
            return implode('|', $parts) . ' ';
        }

        return $this->formatSingleType($type) . ' ';
    }

    /**
     * Format a single type (named, with proper nullability and pseudo‑type handling).
     */
    private function formatSingleType(ReflectionType $type): string
    {
        if (! ($type instanceof ReflectionNamedType)) {
            return $type->__toString();
        }

        $name       = $type->getName();
        $isNullable = $type->allowsNull();

        // Pseudo‑types: static, self, parent – must NOT be prefixed with backslash.
        if (in_array($name, ['static', 'self', 'parent'], true)) {
            return ($isNullable ? '?' : '') . $name;
        }

        // mixed cannot be marked nullable
        if ($name === 'mixed') {
            return 'mixed';
        }

        // For built‑ins, use the name as‑is; for classes, add backslash.
        $typeString = $type->isBuiltin() ? $name : '\\' . $name;

        // Nullable only if allowed and type is not null (which is handled separately)
        if ($isNullable && $name !== 'null') {
            return '?' . $typeString;
        }

        return $typeString;
    }

    /**
     * Format a return type declaration.
     */
    private function formatReturnType(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        $typeStr = $this->formatType($type);
        $typeStr = rtrim($typeStr);
        return ': ' . $typeStr;
    }

    /**
     * Get the type name as a string for comparison (handles union/intersection).
     */
    private function getTypeName(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return $type->__toString();
        }
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }
        return $type->__toString();
    }

    /**
     * Get the short class name for the proxy.
     */
    private function getProxyShortName(string $targetClass): string
    {
        $parts = explode('\\', $targetClass);
        $last  = end($parts);
        // If it's an anonymous class, use a generic name
        if (str_contains($last, 'class@anonymous')) {
            return 'AnonymousProxy';
        }
        return $last . 'Proxy';
    }

    /**
     * Build an argument list for a method call.
     */
    private function buildArgList(ReflectionMethod $method): string
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $args[] = '$' . $param->getName();
        }
        return implode(', ', $args);
    }
}
