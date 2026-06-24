<?php
namespace Rhapsody\Core;

use Doctrine\ORM\EntityManager;
use Rhapsody\Core\Helpers\Recaptcha;

class Validator
{
    /** @var array<string, array<int, string>> */
    protected array $errors = [];
    protected EntityManager $em;

    /**
     * Inject the EntityManager
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Check if a value is unique in a database table.
     * @param string $field The field name (e.g., 'email')
     * @param mixed $value The value to check (e.g., 'test@example.com')
     * @param ?string $param The Entity name (e.g., 'User')
     * @param array<string, mixed> $data
     */
    protected function validateUnique(string $field, mixed $value, ?string $param, array $data): void
    {
        if (empty($value) || empty($param)) {
            return;
        }

        // Assumes $param is the simple class name, e.g., "User"
        // and all entities are in the App\Entities namespace.
        $entityClass = "App\\Entities\\" . $param;

        try {
            $repository = $this->em->getRepository($entityClass);
            $result     = $repository->findOneBy([$field => $value]);

            if ($result) {
                $this->errors[$field][] = "The {$field} is already associated with another account.";
            }
        } catch (\Exception $e) {
            error_log("Validator Error: " . $e->getMessage());
            $this->errors[$field][] = "There was an error checking if the {$field} is unique.";
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            $value      = $data[$field] ?? null;

            foreach ($rulesArray as $rule) {
                $ruleName  = $rule;
                $ruleParam = null;

                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                }

                $methodName = 'validate' . ucfirst($ruleName);
                if (method_exists($this, $methodName)) {
                    // @phpstan-ignore-next-line
                    $this->$methodName($field, $value, $ruleParam, $data);
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param string $field
     * @param mixed $value
     */
    protected function validateRequired(string $field, mixed $value): void
    {
        if (empty($value) || (is_array($value) && empty($value['tmp_name']))) {
            $this->errors[$field][] = "The {$field} field is required.";
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     */
    protected function validateEmail(string $field, ?string $value): void
    {
        if (! empty($value) && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "The {$field} must be a valid email address.";
        }
    }

    /**
     * FIX: Changed `int $value` to `mixed $value` to prevent TypeErrors on string validation.
     * @param string $field
     * @param mixed $value
     * @param ?string $param
     */
    protected function validateMin(string $field, mixed $value, ?string $param): void
    {
        if (($value === null || $value === '') || empty($param)) {
            return;
        }

        $paramValue = (int) $param;

        if (is_numeric($value)) {
            if ((float) $value < $paramValue) {
                $this->errors[$field][] = "The {$field} must be at least {$param}.";
            }
        } else {
            if (strlen(trim((string) $value)) < $paramValue) {
                $this->errors[$field][] = "The {$field} must be at least {$param} characters.";
            }
        }
    }

    /**
     * FIX: Changed `int $value` to `mixed $value` to prevent TypeErrors on string validation.
     * @param string $field
     * @param mixed $value
     * @param ?string $param
     */
    protected function validateMax(string $field, mixed $value, ?string $param): void
    {
        if (($value === null || $value === '') || empty($param)) {
            return;
        }

        $paramValue = (int) $param;

        if (is_numeric($value)) {
            if ((float) $value > $paramValue) {
                $this->errors[$field][] = "The {$field} must not be greater than {$param}.";
            }
        } else {
            if (strlen(trim((string) $value)) > $paramValue) {
                $this->errors[$field][] = "The {$field} must not exceed {$param} characters.";
            }
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     */
    protected function validateUrl(string $field, ?string $value): void
    {
        if (! empty($value) && ! filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = "The {$field} must be a valid URL.";
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     * @param ?string $param
     */
    protected function validateDateFormat(string $field, ?string $value, ?string $param): void
    {
        if (empty($param) || empty($value)) {
            return;
        }

        $date = \DateTime::createFromFormat($param, $value);
        if ($date === false || $date->format($param) !== $value) {
            $this->errors[$field][] = "The {$field} must be a valid date with the format: {$param}.";
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     */
    protected function validateNumeric(string $field, mixed $value): void
    {
        if (! empty($value) && ! is_numeric($value)) {
            $this->errors[$field][] = "The {$field} must only contain numbers.";
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     */
    protected function validateAlpha(string $field, ?string $value): void
    {
        if (! empty($value) && ! ctype_alpha($value)) {
            $this->errors[$field][] = "The {$field} must only contain letters.";
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     */
    protected function validateAlphaNum(string $field, ?string $value): void
    {
        if (! empty($value) && ! ctype_alnum($value)) {
            $this->errors[$field][] = "The {$field} must only contain letters and numbers.";
        }
    }

    /**
     * @param string $field
     * @param ?string $value
     * @param ?string $param
     * @param array $data
     */
    protected function validateConfirmed(string $field, ?string $value, ?string $param, array $data): void
    {
        $confirmationField = $field . '_confirmation';
        if ($value !== ($data[$confirmationField] ?? null)) {
            $this->errors[$field][] = "The {$field} confirmation does not match.";
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param ?string $param
     */
    protected function validateIn(string $field, mixed $value, ?string $param): void
    {
        if (empty($param)) {
            return;
        }

        $allowedValues = explode(',', $param);
        if (! empty($value) && ! in_array($value, $allowedValues)) {
            $this->errors[$field][] = "The selected {$field} is invalid. Allowed values are: " . implode(', ', $allowedValues) . ".";
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param ?string $param
     */
    protected function validateNotIn(string $field, mixed $value, ?string $param): void
    {
        if (empty($param)) {
            return;
        }

        $disallowedValues = explode(',', $param);
        if (! empty($value) && in_array($value, $disallowedValues)) {
            $this->errors[$field][] = "The value for {$field} is not allowed.";
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     */
    protected function validateImage(string $field, mixed $value): void
    {
        if (! empty($value) && is_array($value) && ! empty($value['tmp_name'])) {
            if ($value['error'] !== UPLOAD_ERR_OK || ! getimagesize($value['tmp_name'])) {
                $this->errors[$field][] = "The {$field} must be a valid image file.";
            }
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param ?string $param
     */
    protected function validateMimes(string $field, mixed $value, ?string $param): void
    {
        if (empty($param) || ! is_array($value) || empty($value['tmp_name'])) {
            return;
        }

        $allowedMimes = explode(',', $param);
        $fileMimeType = mime_content_type($value['tmp_name']);

        $allowedMimeTypes = [];
        foreach ($allowedMimes as $ext) {
            $allowedMimeTypes[] = match (strtolower(trim($ext))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'   => 'image/png',
                'gif'   => 'image/gif',
                'webp'  => 'image/webp',
                'pdf'   => 'application/pdf',
                'doc'   => 'application/msword',
                'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/octet-stream'
            };
        }

        if (! in_array($fileMimeType, $allowedMimeTypes)) {
            $this->errors[$field][] = "The file type for {$field} is invalid. Allowed types are: {$param}.";
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     */
    protected function validateRecaptcha(string $field, mixed $value): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        if (! Recaptcha::verify((string) $value, $ipAddress)) {
            $this->errors[$field][] = "Please verify that you are not a robot.";
        }
    }
}
