<?php

namespace Rhapsody\Core\Modules;

/**
 * Checks a module's actual stored settings against the `settings_schema`
 * declared in its manifest, so a missing or wrong-type config value is
 * caught at boot time instead of failing silently deep inside the module's
 * own code later.
 *
 * Deliberately permissive on anything the schema doesn't tell us to check:
 * a settings value stored by the module's own code (via SettingsFacade::set())
 * could already be correctly typed, or could be a raw string from an admin
 * form that hasn't cast it yet — this validator accepts numeric/boolean
 * strings for "number"/"boolean" fields rather than rejecting them, since
 * being overly strict here would turn "config is technically a string"
 * into its own false-positive failure. An unrecognised `type` value is
 * skipped entirely rather than treated as an error.
 */
final class ModuleSettingsValidator
{
    /**
     * @param array<int, array<string, mixed>> $schema   ModuleManifest::$settingsSchema
     * @param array<string, mixed>             $settings Currently stored settings (SettingsFacade::all())
     * @return string[] Human-readable validation errors; empty means valid.
     */
    public static function validate(array $schema, array $settings): array
    {
        $errors = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue; // malformed schema entry — not this validator's job to catch
            }

            $type     = $field['type'] ?? 'string';
            $required = (bool) ($field['required'] ?? false);
            $default  = $field['default'] ?? null;

            $hasStoredValue = array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '';
            $value          = $hasStoredValue ? $settings[$key] : $default;

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = "missing required setting \"{$key}\"";
                }
                continue; // nothing stored/defaulted — no type to check
            }

            $typeError = self::checkType($key, $type, $value, $field['options'] ?? null);
            if ($typeError !== null) {
                $errors[] = $typeError;
            }
        }

        return $errors;
    }

    private static function checkType(string $key, string $type, mixed $value, ?array $options): ?string
    {
        switch ($type) {
            case 'string':
                return is_string($value)
                    ? null
                    : "setting \"{$key}\" must be a string, got " . get_debug_type($value);

            case 'number':
                return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                    ? null
                    : "setting \"{$key}\" must be a number, got " . get_debug_type($value);

            case 'boolean':
                if (is_bool($value)) {
                    return null;
                }
                if (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                    return null;
                }
                return "setting \"{$key}\" must be a boolean, got " . get_debug_type($value);

            case 'select':
                $allowed = self::allowedValues($options);
                if ($allowed === null) {
                    return null; // no options declared — nothing to validate against
                }
                foreach ($allowed as $choice) {
                    if ((string) $choice === (string) $value) {
                        return null;
                    }
                }
                return "setting \"{$key}\" must be one of [" . implode(', ', $allowed) . '], got "' . $value . '"';

            default:
                return null; // unrecognised type — permissive by design, see class docblock
        }
    }

    /** @return array<int, scalar>|null */
    private static function allowedValues(?array $options): ?array
    {
        if ($options === null || $options === []) {
            return null;
        }

        $values = [];
        foreach ($options as $option) {
            // Options can be plain scalars ["a", "b"] or {value,label} pairs
            // for a dropdown UI — support both without assuming either.
            if (is_array($option) && array_key_exists('value', $option)) {
                $values[] = $option['value'];
            } elseif (is_scalar($option)) {
                $values[] = $option;
            }
        }

        return $values === [] ? null : $values;
    }
}
