<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column as ColumnAttribute;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Model;

/**
 * One of a model's properties, as a resource's column, field or filter
 * names it: what it holds, read from its PHP type and the ORM's attribute
 * on it. Only a property the ORM keeps -- the #[Id], a #[Column] or a
 * #[BelongsTo] -- is one a resource may name: anything else is not in the
 * table, and is refused, saying which it may be.
 */
final readonly class ModelProperty
{
    /**
     * @param 'id'|'string'|'int'|'float'|'bool'|'enum'|'moment'|'relation' $type
     * @param class-string|null $class an enum's, or the model it belongs to
     * @param bool $nullable whether it may hold null
     * @param mixed $default what a new record holds, where the property says
     */
    private function __construct(
        public string $name,
        public string $type,
        public ?string $class,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
    ) {}

    /**
     * @param class-string<Model> $model
     * @param string $where what names it, for the message: `ProductResource's column 'price'`
     * @throws \LogicException for a property the model does not keep in its table, or one of a
     *         type a resource cannot show
     */
    public static function of(string $model, string $name, string $where): self
    {
        $reflection = new \ReflectionClass($model);
        if (!$reflection->hasProperty($name)) {
            throw new \LogicException("{$where} names '{$name}', which {$model} does not have. Name one of its #[Column], #[BelongsTo] or #[Id] properties.");
        }
        $property = $reflection->getProperty($name);
        $kept = $property->getAttributes(Id::class) !== [] || $property->getAttributes(ColumnAttribute::class) !== []
            || $property->getAttributes(BelongsTo::class) !== [];
        if (!$kept || !$property->isPublic() || $property->isStatic()) {
            throw new \LogicException("{$where} names {$model}::\${$name}, which is not one of its columns. Name a public #[Column], #[BelongsTo] or #[Id] property.");
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException("{$where} names {$model}::\${$name}, whose type a resource cannot show: give it one type, or leave it off.");
        }
        $typeName = $type->getName();

        $kind = match (true) {
            $property->getAttributes(Id::class) !== [] => 'id',
            $property->getAttributes(BelongsTo::class) !== [] && is_subclass_of($typeName, Model::class) => 'relation',
            \in_array($typeName, ['string', 'int', 'float', 'bool'], true) => $typeName,
            is_subclass_of($typeName, \BackedEnum::class) => 'enum',
            is_a($typeName, CarbonImmutable::class, true) || $typeName === \DateTimeImmutable::class => 'moment',
            default => throw new \LogicException(
                "{$where} names {$model}::\${$name}, a {$typeName}, which a resource cannot show: it takes text, numbers, bools, backed enums, "
                . 'moments and the records a model belongs to.',
            ),
        };

        /** @var class-string|null $class */
        $class = \in_array($kind, ['enum', 'relation'], true) ? $typeName : null;

        return new self(
            name: $name,
            type: $kind,
            class: $class,
            nullable: $type->allowsNull(),
            hasDefault: $property->hasDefaultValue() && !($property->getDefaultValue() === null && !$type->allowsNull()),
            default: $property->hasDefaultValue() ? $property->getDefaultValue() : null,
        );
    }

    /** `priceCents` as `Price cents`. */
    public function label(): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $this->name)));
    }

    /** How a column of it is drawn, unless it says. */
    public function columnKind(): ColumnKind
    {
        return match ($this->type) {
            'int', 'float' => ColumnKind::Number,
            'bool' => ColumnKind::Boolean,
            'enum' => ColumnKind::Enum,
            'moment' => ColumnKind::Moment,
            'relation' => ColumnKind::Relation,
            default => ColumnKind::Text,
        };
    }

    /**
     * An enum's cases, value => label: the case's label() where the enum
     * has one, else its name as words.
     *
     * @return array<string, string>
     */
    public function enumOptions(): array
    {
        if ($this->type !== 'enum' || $this->class === null) {
            return [];
        }
        /** @var class-string<\BackedEnum> $enum */
        $enum = $this->class;
        $options = [];
        foreach ($enum::cases() as $case) {
            $label = method_exists($case, 'label') ? $case->label() : null;
            $options[(string) $case->value] = \is_string($label)
                ? $label
                : ucfirst(strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $case->name)));
        }

        return $options;
    }

    /**
     * What $record holds, as text: see Data\ResourceRowData. A record it
     * belongs to is that record's $labelledBy, read through the relation --
     * loaded with the list's rows, so one statement for the page.
     */
    public function text(Model $record, ?string $labelledBy = null): ?string
    {
        if ($this->type === 'relation') {
            if ($record->foreignKey($this->name) === null) {
                return null;
            }
            $related = $record->{$this->name};

            return $related instanceof Model ? self::scalar($related->{$labelledBy ?? 'name'}) : null;
        }

        $reflection = new \ReflectionProperty($record, $this->name);
        if (!$reflection->isInitialized($record)) {
            return null;
        }

        return self::scalar($record->{$this->name});
    }

    /** What $record holds, as its form's input does: a record it belongs to by its id, a moment to the minute. */
    public function formValue(?Model $record): ?string
    {
        if ($record === null) {
            return $this->hasDefault ? self::scalar($this->default, true) : null;
        }
        if ($this->type === 'relation') {
            $key = $record->foreignKey($this->name);

            return $key === null ? null : (string) $key;
        }
        $reflection = new \ReflectionProperty($record, $this->name);

        return $reflection->isInitialized($record) ? self::scalar($record->{$this->name}, true) : null;
    }

    private static function scalar(mixed $value, bool $forInput = false): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value), \is_string($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value)->utc()->format($forInput ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s\Z'),
            default => null,
        };
    }
}
