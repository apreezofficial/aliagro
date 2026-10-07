<?php

namespace App\Core;

/**
 * Subset of Laravel's validator covering every rule the API uses, with the
 * same default English messages so error payloads stay identical.
 *
 *   Validator::validate($request->all(), ['email' => 'required|email|unique:users,email']);
 *
 * Throws ValidationException (422) or returns the validated subset of the input.
 */
final class Validator
{
    /** Rules that run even when the field is absent. */
    private const IMPLICIT = ['required'];

    private array $errors = [];

    private function __construct(private array $data, private array $rules) {}

    public static function validate(array $data, array $rules): array
    {
        $v = new self($data, $rules);
        $v->run();
        if ($v->errors) {
            throw new ValidationException($v->errors);
        }
        return $v->validated();
    }

    private function run(): void
    {
        foreach ($this->rules as $pattern => $ruleSpec) {
            $rules = is_array($ruleSpec) ? $ruleSpec : explode('|', $ruleSpec);
            $rules = array_values(array_filter(array_map('trim', $rules), fn($r) => $r !== ''));

            foreach (Arr::expand($this->data, $pattern) as $attribute) {
                $this->validateAttribute($attribute, $rules);
            }
        }
    }

    private function validateAttribute(string $attribute, array $rules): void
    {
        $present  = !str_contains($attribute, '*') && Arr::has($this->data, $attribute);
        $value    = $present ? Arr::get($this->data, $attribute) : null;
        $names    = array_map(fn($r) => explode(':', $r, 2)[0], $rules);
        $nullable = in_array('nullable', $names, true);

        if (!$present && in_array('sometimes', $names, true)) {
            return;
        }
        if ($present && $value === null && $nullable) {
            return;
        }

        $numeric = (bool) array_intersect($names, ['numeric', 'integer']);

        foreach ($rules as $rule) {
            [$name, $paramString] = array_pad(explode(':', $rule, 2), 2, '');
            $params = $paramString === '' ? [] : explode(',', $paramString);

            if (in_array($name, ['nullable', 'sometimes', 'bail'], true)) {
                continue;
            }
            // Non-implicit rules are skipped for absent fields.
            if (!$present && !in_array($name, self::IMPLICIT, true)) {
                continue;
            }

            $error = $this->check($attribute, $value, $name, $params, $numeric);
            if ($error !== null) {
                foreach ((array) $error as $message) {
                    $this->errors[$attribute][] = $message;
                }
                // A failed "required" stops further rules on that field.
                if (in_array($name, self::IMPLICIT, true)) {
                    return;
                }
            }
        }
    }

    /** @return string|string[]|null error message(s), or null when the rule passes */
    private function check(string $attr, mixed $value, string $rule, array $p, bool $numeric): string|array|null
    {
        $label = str_replace('_', ' ', $attr);

        switch ($rule) {
            case 'required':
                $blank = $value === null || $value === '' || $value === []
                    || ($value instanceof UploadedFile && $value->error !== UPLOAD_ERR_OK);
                return $blank ? "The {$label} field is required." : null;

            case 'string':
                return is_string($value) ? null : "The {$label} field must be a string.";

            case 'numeric':
                return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                    ? null : "The {$label} field must be a number.";

            case 'integer':
                return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))
                    ? null : "The {$label} field must be an integer.";

            case 'boolean':
                return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)
                    ? null : "The {$label} field must be true or false.";

            case 'array':
                return is_array($value) ? null : "The {$label} field must be an array.";

            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? null : "The {$label} field must be a valid email address.";

            case 'date':
                return is_string($value) && strtotime($value) !== false
                    ? null : "The {$label} field must be a valid date.";

            case 'in':
                return in_array((string) $value, $p, true) || in_array($value, $p, true)
                    ? null : "The selected {$label} is invalid.";

            case 'confirmed':
                return $value === Arr::get($this->data, $attr . '_confirmation')
                    ? null : "The {$label} field confirmation does not match.";

            case 'unique':
                [$table, $column] = [$p[0], $p[1] ?? $attr];
                return DB::value("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?", [$value]) > 0
                    ? "The {$label} has already been taken." : null;

            case 'exists':
                [$table, $column] = [$p[0], $p[1] ?? $attr];
                return DB::value("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?", [$value]) > 0
                    ? null : "The selected {$label} is invalid.";

            case 'min':
            case 'max':
                return $this->checkSize($label, $value, $rule, (float) $p[0], $numeric);

            case 'between':
                $size = $this->size($value, $numeric);
                return $size >= (float) $p[0] && $size <= (float) $p[1]
                    ? null : "The {$label} field must be between {$p[0]} and {$p[1]}.";

            case 'lt':
                $other = Arr::has($this->data, $p[0]) ? Arr::get($this->data, $p[0]) : $p[0];
                return is_numeric($value) && is_numeric($other) && (float) $value < (float) $other
                    ? null : "The {$label} field must be less than " . str_replace('_', ' ', $p[0]) . '.';

            case 'after':
                $other = Arr::has($this->data, $p[0]) ? Arr::get($this->data, $p[0]) : $p[0];
                $a = is_string($value) ? strtotime($value) : false;
                $b = is_string($other) ? strtotime($other) : false;
                return $a !== false && $b !== false && $a > $b
                    ? null : "The {$label} field must be a date after " . str_replace('_', ' ', $p[0]) . '.';

            case 'image':
                if (!$value instanceof UploadedFile || !$value->isValid()) {
                    return "The {$label} failed to upload.";
                }
                return $value->isImage() ? null : "The {$label} field must be an image.";

            case 'mimes':
                if (!$value instanceof UploadedFile || !$value->isValid()) {
                    return "The {$label} failed to upload.";
                }
                $allowed = array_map('strtolower', $p);
                if (in_array('jpg', $allowed, true) || in_array('jpeg', $allowed, true)) {
                    $allowed = array_merge($allowed, ['jpg', 'jpeg']);
                }
                return array_intersect($value->guessedExtensions(), $allowed)
                    ? null : "The {$label} field must be a file of type: " . implode(', ', $p) . '.';

            case 'strong_password':
                $errors = [];
                if (!is_string($value) || mb_strlen($value) < 8) {
                    $errors[] = "The {$label} field must be at least 8 characters.";
                }
                if (!is_string($value) || !preg_match('/\p{Lu}/u', $value) || !preg_match('/\p{Ll}/u', $value)) {
                    $errors[] = "The {$label} field must contain at least one uppercase and one lowercase letter.";
                }
                if (!is_string($value) || !preg_match('/\d/', $value)) {
                    $errors[] = "The {$label} field must contain at least one number.";
                }
                return $errors ?: null;
        }

        throw new \LogicException("Unsupported validation rule [{$rule}]");
    }

    private function size(mixed $value, bool $numeric): float
    {
        return match (true) {
            $numeric && is_numeric($value)  => (float) $value,
            $value instanceof UploadedFile  => $value->sizeInKb(),
            is_array($value)                => count($value),
            default                         => mb_strlen((string) $value),
        };
    }

    private function checkSize(string $label, mixed $value, string $rule, float $limit, bool $numeric): ?string
    {
        $size = $this->size($value, $numeric);
        $ok   = $rule === 'min' ? $size >= $limit : $size <= $limit;
        if ($ok) {
            return null;
        }

        $n    = rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.');
        $kind = match (true) {
            $numeric && is_numeric($value) => 'numeric',
            $value instanceof UploadedFile => 'file',
            is_array($value)               => 'array',
            default                        => 'string',
        };

        return match ([$rule, $kind]) {
            ['min', 'numeric'] => "The {$label} field must be at least {$n}.",
            ['max', 'numeric'] => "The {$label} field must not be greater than {$n}.",
            ['min', 'file']    => "The {$label} field must be at least {$n} kilobytes.",
            ['max', 'file']    => "The {$label} field must not be greater than {$n} kilobytes.",
            ['min', 'array']   => "The {$label} field must have at least {$n} items.",
            ['max', 'array']   => "The {$label} field must not have more than {$n} items.",
            ['min', 'string']  => "The {$label} field must be at least {$n} characters.",
            default            => "The {$label} field must not be greater than {$n} characters.",
        };
    }

    /** Input restricted to the validated attributes; booleans normalised to 0/1. */
    private function validated(): array
    {
        $out = [];
        foreach ($this->rules as $pattern => $ruleSpec) {
            $rules = is_array($ruleSpec) ? $ruleSpec : explode('|', $ruleSpec);
            $isBool = in_array('boolean', $rules, true);
            foreach (Arr::expand($this->data, $pattern) as $path) {
                if (str_contains($path, '*') || !Arr::has($this->data, $path)) {
                    continue;
                }
                $value = Arr::get($this->data, $path);
                if ($isBool && $value !== null) {
                    $value = in_array($value, [true, 1, '1', 'true'], true) ? 1 : 0;
                }
                Arr::set($out, $path, $value);
            }
        }
        return $out;
    }
}
