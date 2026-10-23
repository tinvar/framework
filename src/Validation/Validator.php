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
     * @param array<string, Model> $resolved models the caller already resolved
     *        and authorized; a model-typed field named here is taken from this
     *        map rather than looked up again, so validation cannot substitute
     *        a different row for the one that was checked
     * @param array<string, Model> $subjects every resolved model, so a rule
     *        that exempts "the row being edited" can pick the one of its own
     *        class rather than whichever came first
     * @return T
     */
    public function validate(
        string $class,
        array $input,
        ?Model $subject = null,
        array $resolved = [],
        array $subjects = [],
    ): FormRequest {
        $context = new ValidationContext($input, $this->orm, $subject, $subjects);

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
                $value = \array_key_exists($name, $resolved) && $field->modelClass !== null
                    ? $resolved[$name]
                    : $this->coerce($raw, $field);
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

    /**
     * True when the input would validate, without building anything.
     *
     * @param class-string<FormRequest> $class
     * @param array<string, mixed>      $input
     */
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
     * @param class-string<FormRequest> $class
     * @param array<string, mixed>      $input
     * @param array<string, Model>      $resolved
     * @param array<string, Model>      $subjects
     * @return list<string>
     */
    public function errorsFor(
        string $class,
        array $input,
        string $field,
        ?Model $subject = null,
        array $resolved = [],
        array $subjects = [],
    ): array {
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

        $context = new ValidationContext($input, $this->orm, $subject, $subjects);

        try {
            $errors = $this->checkField($plan, $input, $context, $resolved);
        } catch (NotFoundException) {
            return ["{$field} does not match an existing record."];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, Model>  $resolved
     * @return list<string>
     */
    private function checkField(
        FieldPlan $field,
        array $input,
        ValidationContext $context,
        array $resolved = [],
    ): array {
        $name = $field->name;
        $present = \array_key_exists($name, $input);
        $raw = $present ? $input[$name] : null;

        if (!$present || $raw === '' || $raw === null) {
            return ($field->hasDefault || $field->nullable) ? [] : ["{$name} is required."];
        }

        try {
            $value = \array_key_exists($name, $resolved) && $field->modelClass !== null
                ? $resolved[$name]
                : $this->coerce($raw, $field);
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

    /**
     * Coerces one value against a parameter's declared type.
     *
     * Action parameters get the same careful conversions as form-request
     * fields, so a client sending `name[]=x` gets a 422 rather than a
     * TypeError from deep inside the call.
     */
    public function coerceParameter(\ReflectionParameter $parameter, string $owner, mixed $raw): mixed
    {
        $plan = FieldPlan::of($parameter, $owner);

        try {
            return $this->coerce($raw, $plan);
        } catch (CoercionFailed $failure) {
            throw new ValidationException([$plan->name => [$failure->getMessage()]]);
        }
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
                "{$field->name} is model-typed, but the validator was built without an Orm.",
            );

            return $orm->find($field->modelClass, (string) $raw)
                ?? throw NotFoundException::model($field->modelClass, (string) $raw);
        }

        if ($field->enumClass !== null) {
            return self::toEnum($raw, $field->enumClass, $field->name);
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
            // Handled above by their class; reaching here is a plan built wrong.
            'enum', 'model' => throw new \LogicException("{$field->name}: a {$field->kind} field without its class."),
        };
    }

    private static function toInt(mixed $raw, string $field): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        // Deliberately strict: '12abc', '1e3' and ' 12' are not integers.
        if (\is_string($raw) && preg_match('/^-?\d+\z/', $raw) === 1) {
            return (int) $raw;
        }

        throw new CoercionFailed("{$field} must be a whole number.");
    }

    /** As strict as toInt: '1e3' and ' 12' are not numbers a form should send. */
    private static function toFloat(mixed $raw, string $field): float
    {
        $value = match (true) {
            \is_int($raw), \is_float($raw) => (float) $raw,
            \is_string($raw) && preg_match('/^-?\d+(\.\d+)?\z/', $raw) === 1 => (float) $raw,
            default => throw new CoercionFailed("{$field} must be a number."),
        };

        // Four hundred digits pass the pattern and come out as INF, as does
        // 1e400 in JSON. INF clears every Min, -INF every Max, and neither is
        // a value anything downstream of a form expects.
        if (!is_finite($value)) {
            throw new CoercionFailed("{$field} must be a finite number.");
        }

        return $value;
    }

    /**
     * Converted by the enum's backing type, before tryFrom() sees it: under
     * strict types an int-backed enum's tryFrom() throws a TypeError on a
     * string, which is what every form and query string sends.
     *
     * @param class-string<\BackedEnum> $enum
     */
    private static function toEnum(mixed $raw, string $enum, string $field): \BackedEnum
    {
        $intBacked = (new \ReflectionEnum($enum))->getBackingType()?->getName() === 'int';

        // Only a value that is already the backing type, or spells it exactly,
        // is converted. An array or a bool was cast to a string first, so an
        // array became 'Array' with a warning and true became '1'.
        $value = match (true) {
            $intBacked && \is_int($raw) => $raw,
            $intBacked && \is_string($raw) && preg_match('/^-?\d+\z/', $raw) === 1 => (int) $raw,
            !$intBacked && \is_string($raw) => $raw,
            !$intBacked && \is_int($raw) => (string) $raw,
            default => null,
        };

        $case = $value === null ? null : $enum::tryFrom($value);

        return $case ?? throw new CoercionFailed("{$field} is not a recognised value.");
    }

    /**
     * Arrays are bounded here as well as at the endpoint.
     *
     * The spec caps request bodies and JSON depth at the endpoint, which is a
     * later phase; until then nothing else stops a deeply nested payload from
     * reaching a rule that counts only its top level.
     */
    /** @return array<array-key, mixed> */
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

        $reflection = new \ReflectionClass($class);

        $plan = [];
        $planned = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $property = $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;
            $plan[] = FieldPlan::of($parameter, $class, $property);
            $planned[$name] = true;
        }

        // A rule on a property no parameter fills has no input to judge, so it
        // would read as enforced while passing everything.
        foreach ($reflection->getProperties() as $property) {
            if (isset($planned[$property->getName()])) {
                continue;
            }
            foreach ($property->getAttributes() as $attribute) {
                if (FieldPlan::isRule($attribute)) {
                    throw new \LogicException(
                        "{$class}::\${$property->getName()} has a rule, but no constructor parameter of that "
                        . 'name fills it, so the rule has no input to check. Name the parameter after the '
                        . 'property, or move the rule onto the parameter that sets it.',
                    );
                }
            }
        }

        return self::$plans[$class] = $plan;
    }
}
