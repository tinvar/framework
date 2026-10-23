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

            if (!$present || $raw === '' || $raw === null) {
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
        }
    }

    /**
     * Validates one field, for Precognition's `validate('email')`.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function errorsFor(string $class, array $input, string $field, ?Model $subject = null): array
    {
        try {
            $this->validate($class, $input, $subject);
        } catch (ValidationException $e) {
            return $e->for($field);
        }

        return [];
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

        return match ($field->type) {
            'string' => \is_scalar($raw)
                ? (string) $raw
                : throw new CoercionFailed("{$field->name} must be text."),
            'int' => self::toInt($raw, $field->name),
            'float' => is_numeric($raw)
                ? (float) $raw
                : throw new CoercionFailed("{$field->name} must be a number."),
            'bool' => self::toBool($raw, $field->name),
            'array' => \is_array($raw)
                ? $raw
                : throw new CoercionFailed("{$field->name} must be a list."),
            default => $raw,
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
            $plan[] = FieldPlan::of($parameter);
        }

        return self::$plans[$class] = $plan;
    }
}
