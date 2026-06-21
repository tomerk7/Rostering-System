<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\DB;
use PDO;

/**
 * A small validator that reproduces validation behavior and messages
 * for the rule subset the worker endpoints use. Rules are an ordered map of
 * `field => [tokens]`; `field` may contain a single `*` wildcard for arrays.
 *
 * Behaviors:
 * - a failing `required` short-circuits the remaining rules for that field;
 * - `exists`/`unique` are skipped if the field already has an error;
 * - error order follows rule-definition order (wildcards expand per index);
 * - the top-level message is the first error plus "(and N more error[s])";
 * - custom messages keyed `rule` or `field.rule` override the defaults.
 */
final class Validator
{
    /** @var array<string, list<string>> field => messages */
    private array $errors = [];

    /** @var list<string> all messages, in order (for the summary message) */
    private array $flat = [];

    /** @var array<string, string> custom message overrides (rule or field.rule) */
    private array $customMessages = [];

    private PDO $pdo;

    /**
     * @param  array<string, mixed>  $data
     * @param  PDO|null  $pdo
     */
    public function __construct(
        private readonly array $data,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo ?? DB::connect();
    }

    /**
     * Run the rules; throw ValidationException on any failure. Returns the input.
     *
     * @param  array<string, list<string>>  $rules
     * @param  array<string, string>  $messages  custom overrides (rule or field.rule)
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = []): array
    {
        $errors = $this->collect($rules, $messages);

        if ($errors !== []) {
            $remaining = count($this->flat) - 1;
            $suffix = $remaining > 0
                ? ' (and ' . $remaining . ' more ' . ($remaining === 1 ? 'error' : 'errors') . ')'
                : '';

            throw new ValidationException($this->flat[0] . $suffix, $errors);
        }

        return $this->data;
    }

    /**
     * Run the rules and return the field => messages map (empty when valid),
     * without throwing. Used by the CSV importer to collect per-row errors.
     *
     * @param  array<string, list<string>>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, list<string>>
     */
    public function collect(array $rules, array $messages = []): array
    {
        $this->customMessages = $messages;
        $this->errors = [];
        $this->flat = [];

        foreach ($rules as $field => $ruleset) {
            if (str_contains($field, '*')) {
                $this->validateWildcard($field, $ruleset);
            } else {
                $this->validateField($field, $ruleset, humanize: true);
            }
        }

        return $this->errors;
    }

    /**
     * Expand a `prefix.*.suffix` rule across the indices present in the data.
     *
     * @param string $field
     * @param list<string> $ruleset
     * @return void
     */
    private function validateWildcard(string $field, array $ruleset): void
    {
        [$prefix, $suffix] = explode('.*.', $field, 2);
        $array = $this->data[$prefix] ?? null;

        if (! is_array($array)) {
            return; // the parent field's own array/required rule reports this.
        }

        foreach (array_keys($array) as $index) {
            $this->validateField("{$prefix}.{$index}.{$suffix}", $ruleset, humanize: false);
        }
    }

    /**
     * Validate a single field.
     *
     * @param string $field
     * @param list<string> $ruleset
     * @param bool $humanize
     * @return void
     */
    private function validateField(string $field, array $ruleset, bool $humanize): void
    {
        [$present, $value] = $this->valueAt($field);
        $type = $this->sizeType($ruleset);
        $required = in_array('required', $ruleset, true);
        $empty = ! $present || $value === null || $value === '' || $value === [];

        if ($empty) {
            if ($required) {
                $this->fail($field, $humanize, 'required', 'The :attribute field is required.');
            }

            return;
        }

        foreach ($ruleset as $rule) {
            if ($rule === 'required') {
                continue;
            }

            [$name, $params] = $this->parseRule($rule);

            // Skip exists/unique once the field already has an error.
            if (($name === 'exists' || $name === 'unique') && isset($this->errors[$field])) {
                continue;
            }

            $this->applyRule($field, $humanize, $name, $params, $value, $type);
        }
    }

    /**
     * Apply a single rule to a field.
     *
     * @param string $field
     * @param bool $humanize
     * @param string $name
     * @param list<string> $params
     * @param mixed $value
     * @param string $type
     * @return void
     */
    private function applyRule(string $field, bool $humanize, string $name, array $params, mixed $value, string $type): void
    {
        switch ($name) {
            case 'string':
                if (! is_string($value)) {
                    $this->fail($field, $humanize, 'string', 'The :attribute field must be a string.');
                }
                break;
            case 'integer':
                if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    $this->fail($field, $humanize, 'integer', 'The :attribute field must be an integer.');
                }
                break;
            case 'numeric':
                if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                    $this->fail($field, $humanize, 'numeric', 'The :attribute field must be a number.');
                }
                break;
            case 'boolean':
                if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    $this->fail($field, $humanize, 'boolean', 'The :attribute field must be true or false.');
                }
                break;
            case 'array':
                if (! is_array($value)) {
                    $this->fail($field, $humanize, 'array', 'The :attribute field must be an array.');
                }
                break;
            case 'in':
                if (! in_array((string) $value, $params, true)) {
                    $this->fail($field, $humanize, 'in', 'The selected :attribute is invalid.');
                }
                break;
            case 'max':
                if ($this->size($value, $type === 'numeric') > (float) $params[0]) {
                    $this->fail($field, $humanize, 'max', $this->sizeMessage($type, 'max', $params));
                }
                break;
            case 'min':
                if ($this->size($value, $type === 'numeric') < (float) $params[0]) {
                    $this->fail($field, $humanize, 'min', $this->sizeMessage($type, 'min', $params));
                }
                break;
            case 'between':
                $size = $this->size($value, $type === 'numeric');
                if ($size < (float) $params[0] || $size > (float) $params[1]) {
                    $this->fail($field, $humanize, 'between', $this->sizeMessage($type, 'between', $params));
                }
                break;
            case 'gte':
                [, $other] = $this->valueAt($params[0]);
                if (! is_numeric($value) || ! is_numeric($other) || $value < $other) {
                    $this->fail($field, $humanize, 'gte', 'The :attribute field must be greater than or equal to :value.', [':value' => $this->num($other)]);
                }
                break;
            case 'exists':
                if (! $this->existsInTable($params[0], $params[1], $value)) {
                    $this->fail($field, $humanize, 'exists', 'The selected :attribute is invalid.');
                }
                break;
            case 'unique':
                if ($this->existsInTable($params[0], $params[1], $value)) {
                    $this->fail($field, $humanize, 'unique', 'The :attribute has already been taken.');
                }
                break;
            case 'israeli_id':
                if (! is_string($value) || ! preg_match('/^\d{9}$/', $value)) {
                    $this->fail($field, $humanize, 'israeli_id', 'The :attribute must be exactly 9 digits.');
                } elseif (preg_match('/^0+$/', $value)) {
                    // All-zeros is a 9-digit string but never a real ID number.
                    $this->fail($field, $humanize, 'israeli_id', 'The :attribute is not a valid ID number.');
                }
                break;
            case 'date_format':
                if (! $this->matchesDateFormat($value, $params[0])) {
                    $this->fail($field, $humanize, 'date_format', 'The :attribute field must match the format :format.', [':format' => $params[0]]);
                }
                break;
            case 'after_or_equal':
                // Pass when the referenced field is absent/empty; only a
                // present, earlier date fails.
                [, $other] = $this->valueAt($params[0]);
                if ($other !== null && $other !== '') {
                    $left = strtotime((string) $value);
                    $right = strtotime((string) $other);
                    if ($left === false || $right === false || $left < $right) {
                        $this->fail($field, $humanize, 'after_or_equal', 'The :attribute field must be a date after or equal to :date.', [':date' => str_replace('_', ' ', $params[0])]);
                    }
                }
                break;
            case 'size':
                if ($this->size($value, $type === 'numeric') != (float) $params[0]) {
                    $this->fail($field, $humanize, 'size', $this->sizeMessage($type, 'size', $params));
                }
                break;
            case 'date':
                if ((! is_string($value) && ! is_numeric($value)) || strtotime((string) $value) === false) {
                    $this->fail($field, $humanize, 'date', 'The :attribute field must be a valid date.');
                }
                break;
        }
    }

    /**
     * Pick the size-message variant for the field's type.
     *
     * @param string $type
     * @param string $rule
     * @param list<string> $params
     * @return string
     */
    private function sizeMessage(string $type, string $rule, array $params): string
    {
        $messages = [
            'string' => [
                'max' => 'The :attribute field must not be greater than {a} characters.',
                'min' => 'The :attribute field must be at least {a} characters.',
                'between' => 'The :attribute field must be between {a} and {b} characters.',
                'size' => 'The :attribute field must be {a} characters.',
            ],
            'numeric' => [
                'max' => 'The :attribute field must not be greater than {a}.',
                'min' => 'The :attribute field must be at least {a}.',
                'between' => 'The :attribute field must be between {a} and {b}.',
                'size' => 'The :attribute field must be {a}.',
            ],
            'array' => [
                'max' => 'The :attribute field must not have more than {a} items.',
                'min' => 'The :attribute field must have at least {a} items.',
                'between' => 'The :attribute field must have between {a} and {b} items.',
                'size' => 'The :attribute field must contain {a} items.',
            ],
        ];

        return strtr($messages[$type][$rule], ['{a}' => $params[0], '{b}' => $params[1] ?? '']);
    }

    /**
     * Whether the value strictly matches the given date format (
     * date_format: parse with the exact format, reject warnings/errors, and
     * require a lossless round-trip).
     *
     * @param mixed $value
     * @param string $format
     * @return bool
     */
    private function matchesDateFormat(mixed $value, string $format): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = \DateTime::createFromFormat('!' . $format, $value);
        $errors = \DateTime::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }

        return $date !== false && $date->format($format) === $value;
    }

    /**
     * Field "size" the way it is computed: from the value's actual type, not
     * the declared rule type (e.g. a non-array value falls back to its length).
     *
     * @param mixed $value
     * @param bool $hasNumericRule
     * @return float
     */
    private function size(mixed $value, bool $hasNumericRule): float
    {
        if ($hasNumericRule && is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }

        return (float) mb_strlen((string) $value);
    }

    /**
     * Pick the size-message variant for the field's type.
     *
     * @param list<string> $ruleset
     * @return string
     */
    private function sizeType(array $ruleset): string
    {
        if (in_array('array', $ruleset, true)) {
            return 'array';
        }
        if (in_array('numeric', $ruleset, true) || in_array('integer', $ruleset, true)) {
            return 'numeric';
        }

        return 'string';
    }

    /**
     * Resolve a dotted path against the data. Returns [present, value].
     *
     * @param string $path
     * @return array{0: bool, 1: mixed}
     */
    private function valueAt(string $path): array
    {
        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return [false, null];
            }
        }

        return [true, $value];
    }

    /**
     * Parse a rule into its name and parameters.
     *
     * @param string $rule
     * @return array{0: string, 1: list<string>}
     */
    private function parseRule(string $rule): array
    {
        if (! str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$name, $args] = explode(':', $rule, 2);

        return [$name, explode(',', $args)];
    }

    /**
     * Check if a value exists in a table column.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @return bool
     */
    private function existsInTable(string $table, string $column, mixed $value): bool
    {
        // Identifiers are hard-coded in our rules, never user input; guard anyway.
        if (! preg_match('/^[a-z_]+$/i', $table) || ! preg_match('/^[a-z_]+$/i', $column)) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$value]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record a failure, resolving a custom message (field.rule, then rule) over
     * the default and substituting :attribute (+ any extra replacements).
     *
     * @param string $field
     * @param bool $humanize
     * @param string $rule
     * @param string $default
     * @param array<string, string> $extra
     */
    private function fail(string $field, bool $humanize, string $rule, string $default, array $extra = []): void
    {
        $display = $humanize ? str_replace('_', ' ', $field) : $field;
        $template = $this->customMessages["{$field}.{$rule}"] ?? $this->customMessages[$rule] ?? $default;
        $message = strtr($template, [':attribute' => $display] + $extra);

        $this->errors[$field][] = $message;
        $this->flat[] = $message;
    }

    /**
     * Format a number for display.
     *
     * @param mixed $value
     * @return string
     */
    private function num(mixed $value): string
    {
        return is_float($value) ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
    }
}
