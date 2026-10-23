<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Orm\Model;

/**
 * One form-request field, reflected once.
 *
 * Planning is where authoring mistakes are caught: a type the validator cannot
 * coerce, a rule attached to a type it cannot judge, a variadic parameter. All
 * of these would otherwise surface as a TypeError or as a rule that silently
 * passes everything -- and a declared-but-inert constraint reads as enforced,
 * which is the worse failure.
 */
final readonly class FieldPlan
{
    /** Kinds the validator knows how to produce and rules can be written against. */
    public const array KINDS = ['string', 'int', 'float', 'bool', 'array', 'enum', 'model'];

    /**
     * @param 'string'|'int'|'float'|'bool'|'array'|'enum'|'model' $kind
     * @param list<Rule>                     $rules
     * @param class-string<Model>|null       $modelClass
     * @param class-string<\BackedEnum>|null $enumClass
     */
    public function __construct(
        public string $name,
        public string $kind,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public array $rules,
        public ?string $modelClass = null,
        public ?string $enumClass = null,
    ) {}

    /**
     * @param \ReflectionProperty|null $property the property this parameter
     *        fills, when the constructor assigns it rather than promoting it.
     *        Every rule targets properties too, so a rule written there reads
     *        as enforced and has to be.
     */
    public static function of(
        \ReflectionParameter $parameter,
        string $owner,
        ?\ReflectionProperty $property = null,
    ): self {
        $name = $parameter->getName();
        $where = "{$owner}::\${$name}";

        if ($parameter->isVariadic()) {
            throw new \LogicException(
                "{$where} is variadic. A form request field maps to one named value, and a variadic "
                . 'parameter cannot receive one.',
            );
        }
        if ($parameter->isPassedByReference()) {
            throw new \LogicException("{$where} is by reference, which a form request field cannot be.");
        }

        [$kind, $modelClass, $enumClass] = self::kindOf($parameter, $where);

        // PHP copies an attribute on a promoted parameter onto its property as
        // well, so reading both would apply every such rule twice.
        $attributes = $parameter->getAttributes();
        if ($property !== null && !$parameter->isPromoted()) {
            $attributes = [...$attributes, ...$property->getAttributes()];
        }

        $rules = [];
        foreach ($attributes as $attribute) {
            // Checked before instantiating: an unrelated attribute's
            // constructor has no business running inside validation.
            if (!self::isRule($attribute)) {
                continue;
            }

            /** @var Rule $rule */
            $rule = $attribute->newInstance();
            $applies = $rule->appliesTo();

            // Judged against the parameter's kind, not the property's: the
            // value a rule sees is the one coerced for the parameter.
            if ($applies !== [] && !\in_array($kind, $applies, true)) {
                throw new \LogicException(
                    "{$where} is {$kind}, which " . $rule::class . ' cannot check (it applies to '
                    . implode(', ', $applies) . '). A rule that cannot judge a field would pass everything, or '
                    . 'refuse everything.',
                );
            }

            $rules[] = $rule;
        }

        return new self(
            name: $name,
            kind: $kind,
            nullable: $parameter->getType()?->allowsNull() ?? true,
            hasDefault: $parameter->isDefaultValueAvailable(),
            default: $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            rules: $rules,
            modelClass: $modelClass,
            enumClass: $enumClass,
        );
    }

    /** @param \ReflectionAttribute<object> $attribute */
    public static function isRule(\ReflectionAttribute $attribute): bool
    {
        return is_subclass_of($attribute->getName(), Rule::class);
    }

    /** @return array{'string'|'int'|'float'|'bool'|'array'|'enum'|'model', class-string<Model>|null, class-string<\BackedEnum>|null} */
    private static function kindOf(\ReflectionParameter $parameter, string $where): array
    {
        $type = $parameter->getType();

        if ($type === null) {
            throw new \LogicException(
                "{$where} has no type. Typed properties are the schema; an untyped field declares nothing.",
            );
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException(
                "{$where} is a union or intersection type, which the validator cannot coerce to. "
                . 'Declare one type, or take the value as a string and convert it yourself.',
            );
        }

        $name = $type->getName();

        if (!$type->isBuiltin()) {
            if (is_subclass_of($name, Model::class)) {
                return ['model', $name, null];
            }
            if (is_subclass_of($name, \BackedEnum::class)) {
                return ['enum', null, $name];
            }

            throw new \LogicException(
                "{$where} is {$name}, which the validator cannot build from request input. "
                . 'A field is a scalar, an array, a backed enum, or a model resolved by id.',
            );
        }

        if (!\in_array($name, ['string', 'int', 'float', 'bool', 'array'], true)) {
            throw new \LogicException(
                "{$where} is {$name}, which the validator cannot coerce to. "
                . 'Use string, int, float, bool, array, a backed enum, or a model.',
            );
        }

        return [$name, null, null];
    }
}
