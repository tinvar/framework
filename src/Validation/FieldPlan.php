<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Orm\Model;

/** One form-request field, reflected once. */
final readonly class FieldPlan
{
    /**
     * @param list<Rule>                $rules
     * @param class-string<Model>|null  $modelClass
     * @param class-string<\BackedEnum>|null $enumClass
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public array $rules,
        public ?string $modelClass = null,
        public ?string $enumClass = null,
    ) {}

    public static function of(\ReflectionParameter $parameter): self
    {
        $type = $parameter->getType();
        $name = $parameter->getName();

        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        $modelClass = null;
        $enumClass = null;

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            if (is_subclass_of($typeName, Model::class)) {
                $modelClass = $typeName;
            } elseif (is_subclass_of($typeName, \BackedEnum::class)) {
                $enumClass = $typeName;
            }
        }

        $rules = [];
        foreach ($parameter->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance instanceof Rule) {
                $rules[] = $instance;
            }
        }

        return new self(
            name: $name,
            type: $typeName,
            nullable: $type?->allowsNull() ?? true,
            hasDefault: $parameter->isDefaultValueAvailable(),
            default: $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            rules: $rules,
            modelClass: $modelClass,
            enumClass: $enumClass,
        );
    }
}
