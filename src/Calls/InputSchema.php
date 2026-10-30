<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Validation\FieldPlan;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/**
 * An operation's inputs as a JSON Schema object: what a model is told a
 * tool takes, so it can fill the arguments in.
 *
 * A description of the validation, never a replacement for it. The
 * arguments a call sends are held to the operation's inputs and then
 * validated as a form post is (CallRunner), whatever the caller made of
 * this. So it says what is cheap to say -- the type, a backed enum's
 * values, an #[In] list, #[Min] and #[Max] -- and leaves the rest to the
 * validator's messages, which a refused call carries back.
 */
final class InputSchema
{
    /** @return array<string, mixed> */
    public static function of(Operation $operation): array
    {
        $properties = [];
        $required = [];
        foreach ($operation->inputs as $name => $field) {
            $properties[$name] = self::field($field);
            if ($operation->requires($name)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            // {} where there are none: an empty PHP array would be a JSON list.
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function field(FieldPlan $field): array
    {
        $schema = match ($field->kind) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            'enum' => self::enum($field),
            'model' => [
                'type' => 'string',
                'description' => 'The id of a ' . self::shortName((string) $field->modelClass) . '.',
            ],
            // Operations refuses an action taking one.
            'upload' => throw new \LogicException('An upload is no input a call can send.'),
        };

        foreach ($field->rules as $rule) {
            if ($rule instanceof In) {
                $schema['enum'] = $rule->allowed;
            } elseif ($rule instanceof Min) {
                $schema[$field->kind === 'string' ? 'minLength' : ($field->kind === 'array' ? 'minItems' : 'minimum')] = $rule->min;
            } elseif ($rule instanceof Max) {
                $schema[$field->kind === 'string' ? 'maxLength' : ($field->kind === 'array' ? 'maxItems' : 'maximum')] = $rule->max;
            }
        }

        if ($field->nullable && isset($schema['type']) && \is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function enum(FieldPlan $field): array
    {
        $class = $field->enumClass;
        if ($class === null) {
            return [];
        }
        $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $class::cases());

        return [
            'type' => \is_int($values[0] ?? null) ? 'integer' : 'string',
            'enum' => $values,
        ];
    }

    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
