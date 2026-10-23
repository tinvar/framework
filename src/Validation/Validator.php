<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Http\NotFoundException;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * Builds a FormRequest from raw input, or throws with every error at once.
 *
 * The declared type does the first half of the work: input arrives as strings
 * and is coerced to what the property says it is, refusing anything that does
 * not convert. Rule attributes then add what the type cannot express.
 *
 * Only declared properties are read, so an unexpected key in the payload is
 * inert -- there is no mass assignment to guard against.
 */
final class Validator
{
    /** Matches the JSON depth the spec caps action and query endpoints at. */
    public const int MAX_ARRAY_DEPTH = 32;

    /** @var array<class-string, list<FieldPlan>> */
    private static array $plans = [];

    public function __construct(private readonly ?Orm $orm = null) {}

    /**
     * @template T of FormRequest
     * @param class-string<T>      $class
     * @param array<string, mixed> $input
     * @param Model|null           $subject the record being edited, resolved
     *        and authorized by the router -- rules that need it read it here
     *        rather than from the payload
     * @return T
     */
    public function validate(string $class, array $input, ?Model $subject = null): FormRequest
    {
        $context = new ValidationContext($input, $this->orm, $subject);

        /** @var array<string, list<string>> $errors */
        $errors = [];
        $arguments = [];

        foreach (self::planFor($class) as $field) {
            $name = $field->name;
            $present = \array_key_exists($name, $input);
            $raw = $present ? $input[$name] : null;
            $empty = $raw === '' || $raw === null;

            if (!$present) {
                if ($field->hasDefault) {
                    $arguments[$name] = $field->default;
                    continue;
                }
                if ($field->nullable) {
                    $arguments[$name] = null;
                    continue;
                }
                $errors[$name][] = "{$name} is required.";
                continue;
            }

            if ($empty) {
                // An explicit empty value clears a nullable field. Falling back
                // to the default here would make a field with a non-null
                // default impossible to clear from an edit form.
                if ($field->nullable) {
                    $arguments[$name] = null;
                    continue;
                }
                if ($field->hasDefault) {
                    $arguments[$name] = $field->default;
                    continue;
                }
                $errors[$name][] = "{$name} is required.";
                continue;
            }

            try {
                $value = $this->coerce($raw, $field);
            } catch (CoercionFailed $failure) {
                $errors[$name][] = $failure->getMessage();
                continue;
            }

            foreach ($field->rules as $rule) {
                $message = $rule->check($value, $name, $context);
                if ($message !== null) {
                    $errors[$name][] = $message;
                }
            }

            $arguments[$name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var T */
        return new $class(...$arguments);
    }

    /** True when the input would validate, without building anything. */
    public function passes(string $class, array $input, ?Model $subject = null): bool
    {
        try {
            $this->validate($class, $input, $subject);

            return true;
        } catch (ValidationException) {
            return false;
        } catch (NotFoundException) {
            // "Would this input validate?" An id that resolves to nothing is a
            // no, not an exception for the caller to handle.
            return false;
        }
    }

    /**
     * Validates one field, for Precognition's `validate('email')`.
     *
     * Only that field is checked. Running the whole request to answer about
     * one would fire unrelated required errors on a half-filled form, issue
     * every other field's Exists and Unique queries on each keystroke, and let
     * a 404 from a different model-typed field escape.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function errorsFor(string $class, array $input, string $field, ?Model $subject = null): array
    {
        $plan = null;
        foreach (self::planFor($class) as $candidate) {
            if ($candidate->name === $field) {
                $plan = $candidate;
                break;
            }
        }

        if ($plan === null) {
            // A typo in validate('emai') would otherwise return no errors and
            // read as "this field is fine".
            throw new \LogicException("{$class} declares no field named '{$field}'.");
        }

        $context = new ValidationContext($input, $this->orm, $subject);

        try {
            $errors = $this->checkField($plan, $input, $context);
        } catch (NotFoundException) {
            return ["{$field} does not match an existing record."];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function checkField(FieldPlan $field, array $input, ValidationContext $context): array
    {
        $name = $field->name;
        $present = \array_key_exists($name, $input);
        $raw = $present ? $input[$name] : null;

        if (!$present || $raw === '' || $raw === null) {
            return ($field->hasDefault || $field->nullable) ? [] : ["{$name} is required."];
        }

        try {
            $value = $this->coerce($raw, $field);
        } catch (CoercionFailed $failure) {
            return [$failure->getMessage()];
        }

        $errors = [];
        foreach ($field->rules as $rule) {
            $message = $rule->check($value, $name, $context);
            if ($message !== null) {
                $errors[] = $message;
            }
        }

        return $errors;
    }

    private function coerce(mixed $raw, FieldPlan $field): mixed
    {
        // A model-typed field is resolved, not validated: "Resolve model
        // arguments, and model-typed fields inside Data and form requests,
        // scoped to the tenant. A missing or foreign ID is a 404."
        if ($field->modelClass !== null) {
            if (!\is_string($raw) && !\is_int($raw)) {
                throw new CoercionFailed("{$field->name} must be an identifier.");
            }
            $orm = $this->orm ?? throw new \LogicException(
                "{$field->name} is model-typed, but the validator was built without an Orm."
            );

            return $orm->find($field->modelClass, (string) $raw)
                ?? throw NotFoundException::model($field->modelClass, (string) $raw);
        }

        if ($field->enumClass !== null) {
            $case = $field->enumClass::tryFrom(\is_int($raw) ? $raw : (string) $raw);

            return $case ?? throw new CoercionFailed("{$field->name} is not a recognised value.");
        }

        return match ($field->kind) {
            // Not is_scalar: a bool would become '' or '1', and '' then passes
            // a required string as empty.
            'string' => \is_string($raw) || \is_int($raw) || \is_float($raw)
                ? (string) $raw
                : throw new CoercionFailed("{$field->name} must be text."),
            'int' => self::toInt($raw, $field->name),
            'float' => self::toFloat($raw, $field->name),
            'bool' => self::toBool($raw, $field->name),
            'array' => self::toArray($raw, $field->name),
        };
    }

    private static function toInt(mixed $raw, string $field): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        // Deliberately strict: '12abc', '1e3' and ' 12' are not integers.
        if (\is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        throw new CoercionFailed("{$field} must be a whole number.");
    }

    /** As strict as toInt: '1e3' and ' 12' are not numbers a form should send. */
    private static function toFloat(mixed $raw, string $field): float
    {
        if (\is_int($raw) || \is_float($raw)) {
            return (float) $raw;
        }
        if (\is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1) {
            return (float) $raw;
        }

        throw new CoercionFailed("{$field} must be a number.");
    }

    /**
     * Arrays are bounded here as well as at the endpoint.
     *
     * The spec caps request bodies and JSON depth at the endpoint, which is a
     * later phase; until then nothing else stops a deeply nested payload from
     * reaching a rule that counts only its top level.
     */
    private static function toArray(mixed $raw, string $field): array
    {
        if (!\is_array($raw)) {
            throw new CoercionFailed("{$field} must be a list.");
        }
        if (self::depthOf($raw) > self::MAX_ARRAY_DEPTH) {
            throw new CoercionFailed("{$field} is nested too deeply.");
        }

        return $raw;
    }

    /** @param array<array-key, mixed> $value */
    private static function depthOf(array $value, int $depth = 1): int
    {
        $deepest = $depth;

        foreach ($value as $item) {
            if (\is_array($item)) {
                $deepest = max($deepest, self::depthOf($item, $depth + 1));
                if ($deepest > self::MAX_ARRAY_DEPTH) {
                    return $deepest;
                }
            }
        }

        return $deepest;
    }

    private static function toBool(mixed $raw, string $field): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }

        return match (\is_string($raw) ? strtolower($raw) : $raw) {
            '1', 'true', 'on', 'yes', 1 => true,
            '0', 'false', 'off', 'no', 0 => false,
            default => throw new CoercionFailed("{$field} must be true or false."),
        };
    }

    /**
     * @param class-string<FormRequest> $class
     * @return list<FieldPlan>
     */
    private static function planFor(string $class): array
    {
        if (isset(self::$plans[$class])) {
            return self::$plans[$class];
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return self::$plans[$class] = [];
        }

        $plan = [];
        foreach ($constructor->getParameters() as $parameter) {
            $plan[] = FieldPlan::of($parameter, $class);
        }

        return self::$plans[$class] = $plan;
    }
}
