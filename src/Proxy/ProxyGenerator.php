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

        $namespace      = $this->getProxyNamespace($targetClass);
        $shortClassName = $this->getProxyShortName($targetClass);

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic() || $method->isFinal() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            // Skip if the method name is 'initialize' to avoid conflict with our own lazy initialize
            if ($method->getName() === 'initialize') {
                continue;
            }

            $visibility = $method->isProtected() ? 'protected' : 'public';

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

            $isAbstract = $method->isAbstract() || $isInterface;

            if ($isAbstract) {
                if ($isVoid) {
                    $body = '$this->wrappedObject->' . $methodName . '(' . $argList . ');';
                } else {
                    $body = 'return $this->wrappedObject->' . $methodName . '(' . $argList . ');';
                }
            } else {
                if ($isVoid) {
                    $body = 'parent::' . $methodName . '(' . $argList . ');';
                } else {
                    $body = 'return parent::' . $methodName . '(' . $argList . ');';
                }
            }

            $methods[] = <<<METHOD
    {$visibility} function {$methodName}({$paramsStr}){$returnStr}
    {
        \$this->lazyInitialize();
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

namespace {$namespace};

use Rhapsody\Core\Proxy\LazyProxyInterface;

class {$shortClassName} {$extends} {$implements}
{
    private ?\Closure \$initializer = null;
    private ?object \$wrappedObject = null;

    public function __construct(\Closure \$initializer)
    {
        \$this->initializer = \$initializer;
    }

    private function lazyInitialize(): void
    {
        if (\$this->wrappedObject === null && \$this->initializer !== null) {
            \$initializer = \$this->initializer;
            \$initializer(\$this->wrappedObject, \$this, 'lazyInitialize', [], \$this->initializer);
            \$this->initializer = null;
        }
    }

    public function getWrappedObject(): ?object
    {
        \$this->lazyInitialize();
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

        $filePath = $this->cacheDir . '/' . str_replace('\\', '_', $targetClass) . '.php';
        file_put_contents($filePath, $code);

        try {
            require_once $filePath;
        } catch (\ParseError $e) {
            $debugPath = $this->cacheDir . '/' . str_replace('\\', '_', $targetClass) . '.debug.php';
            file_put_contents($debugPath, $code);
            throw new \Exception("Parse error in generated proxy file: {$filePath}\n" . $e->getMessage() . "\nDebug code written to: {$debugPath}", 0, $e);
        }

        return $namespace . '\\' . $shortClassName;
    }

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

    private function formatSingleType(ReflectionType $type): string
    {
        if (! ($type instanceof ReflectionNamedType)) {
            return $type->__toString();
        }

        $name       = $type->getName();
        $isNullable = $type->allowsNull();

        if (in_array($name, ['static', 'self', 'parent'], true)) {
            return ($isNullable ? '?' : '') . $name;
        }

        if ($name === 'mixed') {
            return 'mixed';
        }

        $typeString = $type->isBuiltin() ? $name : '\\' . $name;

        if ($isNullable && $name !== 'null') {
            return '?' . $typeString;
        }

        return $typeString;
    }

    private function formatReturnType(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        $typeStr = $this->formatType($type);
        $typeStr = rtrim($typeStr);
        return ': ' . $typeStr;
    }

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

    private function getProxyNamespace(string $targetClass): string
    {
        return 'Rhapsody\\Core\\Proxy\\Generated\\' . str_replace('\\', '_', trim($targetClass, '\\'));
    }

    private function getProxyShortName(string $targetClass): string
    {
        $parts = explode('\\', $targetClass);
        return end($parts) . 'Proxy';
    }

    private function buildArgList(ReflectionMethod $method): string
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $args[] = '$' . $param->getName();
        }
        return implode(', ', $args);
    }
}
