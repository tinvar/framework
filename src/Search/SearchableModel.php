<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Attributes\Searchable;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\Sensitive;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelMeta;

/**
 * A #[Searchable] model as search reads it (ADR 0154): its table, which
 * names it in every index; its fields, each placed in one of four weight
 * classes, A highest; and its tenant column, if it is #[TenantScoped].
 *
 * Four classes, because Postgres ranks a tsvector by four labels and no
 * more: the model's distinct weights, highest first, are A, B, C and D,
 * and each backend ranks by the weights themselves. A field is indexed as
 * the text of its class, so the index's shape is the same for every model
 * and a field added or taken out needs a reindex, never a migration.
 *
 * Built once per class and checked whole: a field that is no column of
 * the model, is one of a type search cannot read as text, or is a secret
 * -- #[Sensitive], or named as one -- is refused, naming it.
 */
final class SearchableModel
{
    /** The weight classes, highest first: the labels Postgres ranks by. */
    public const array CLASSES = ['a', 'b', 'c', 'd'];

    /**
     * @param class-string<Model> $class
     * @param array<string, string> $fields property => column, as the attribute lists them
     * @param array<string, int> $classOf property => its weight class, 0 (A) to 3 (D)
     * @param list<float> $weights each class's weight, A to D; 0 for a class no field is in
     */
    private function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly ?string $tenantColumn,
        public readonly array $fields,
        public readonly array $classOf,
        public readonly array $weights,
    ) {}

    /** @var array<class-string, self> */
    private static array $built = [];

    /**
     * @param class-string $class
     * @throws \InvalidArgumentException for a class that is no #[Searchable] model, or one whose fields are not its columns
     */
    public static function of(string $class): self
    {
        return self::$built[$class] ??= self::build($class);
    }

    /** Whether $class carries #[Searchable], read off the class itself. */
    public static function declaredOn(string $class): bool
    {
        return class_exists($class) && new \ReflectionClass($class)->getAttributes(Searchable::class) !== [];
    }

    /** @param class-string $class */
    private static function build(string $class): self
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new \InvalidArgumentException("{$class} is not a model: #[Searchable] goes on a Model whose rows search finds.");
        }
        $attribute = new \ReflectionClass($class)->getAttributes(Searchable::class)[0] ?? null;
        if ($attribute === null) {
            throw new \InvalidArgumentException("{$class} is not #[Searchable]: add #[Searchable(fields: [...])] naming the properties to search.");
        }
        $searchable = $attribute->newInstance();
        $meta = Metadata::for($class);

        $fields = [];
        foreach ($searchable->fields as $field) {
            $column = $meta->columns[$field] ?? null;
            if ($column === null) {
                throw new \InvalidArgumentException("{$class} searches '{$field}', which is no #[Column] property of it: name a property the model maps to a column.");
            }
            $property = new \ReflectionProperty($class, $field);
            self::assertTextual($property);
            self::assertNotSecret($property, $column);
            $fields[$field] = $column;
        }

        // The distinct weights, highest first, are the classes A to D.
        $distinct = [];
        foreach ($searchable->fields as $field) {
            $weight = $searchable->weightOf($field);
            if (!\in_array($weight, $distinct, true)) {
                $distinct[] = $weight;
            }
        }
        rsort($distinct);
        $classOf = [];
        foreach ($searchable->fields as $field) {
            $classOf[$field] = (int) array_search($searchable->weightOf($field), $distinct, true);
        }
        $weights = array_pad($distinct, \count(self::CLASSES), 0.0);

        return new self($class, $meta->table, $meta->tenantColumn, $fields, $classOf, $weights);
    }

    /**
     * Refuses a field whose type is not one search reads as text: a string,
     * a number, a boolean or a backed enum, nullable or not.
     */
    private static function assertTextual(\ReflectionProperty $property): void
    {
        $class = $property->getDeclaringClass()->getName();
        $field = $property->getName();
        $type = $property->getType();
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(static fn (\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : 'mixed', $type->getTypes()),
            default => ['mixed'],
        };
        foreach ($names as $name) {
            if (\in_array($name, ['string', 'int', 'float', 'bool', 'null'], true) || is_subclass_of($name, \BackedEnum::class)) {
                continue;
            }
            throw new \InvalidArgumentException("{$class} searches '{$field}', typed {$name}: search reads text, numbers, booleans and backed enums. Search a property of one of those types instead.");
        }
    }

    /**
     * Refuses a field the audit log would redact: one marked #[Sensitive],
     * or whose property or column name says it is a secret
     * (Audited::SECRET_NAMES). The index is a copy of the text, kept apart
     * from the row -- in Meilisearch, off the database altogether -- and a
     * search hands back which rows a guess matches, so a secret indexed is
     * a secret anyone who may search can test guesses against.
     */
    private static function assertNotSecret(\ReflectionProperty $property, string $column): void
    {
        $class = $property->getDeclaringClass()->getName();
        $field = $property->getName();
        if ($property->getAttributes(Sensitive::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
            throw new \InvalidArgumentException("{$class} searches '{$field}', which is #[Sensitive]: a secret is never indexed, where anyone who may search could test guesses against it. Take it out of #[Searchable(fields: [...])].");
        }
        if (Audited::namesSecret($field) || Audited::namesSecret($column)) {
            throw new \InvalidArgumentException("{$class} searches '{$field}', whose name says it is a secret: a secret is never indexed, where anyone who may search could test guesses against it. Take it out of #[Searchable(fields: [...])], or rename it if it is not one.");
        }
    }

    /** The ORM's metadata for the model. */
    public function meta(): ModelMeta
    {
        return Metadata::for($this->class);
    }

    /** Whether the model is #[TenantScoped]: its index is held to the tenant as its rows are. */
    public function tenantScoped(): bool
    {
        return $this->tenantColumn !== null;
    }

    /**
     * The document a model is indexed as: its key, and the text of each
     * weight class, its fields' values joined by a line.
     */
    public function document(Model $model): SearchDocument
    {
        $meta = $this->meta();
        $classes = array_fill(0, \count(self::CLASSES), []);
        foreach (array_keys($this->fields) as $field) {
            $classes[$this->classOf[$field]][] = self::text($meta->get($model, $field));
        }

        return new SearchDocument(
            $meta->keyOf($model),
            array_values(array_map(static fn (array $texts): string => implode("\n", array_filter($texts, static fn (string $t): bool => $t !== '')), $classes)),
        );
    }

    /**
     * The fields, highest weight first: the order Meilisearch ranks a match
     * in one above a match in the next.
     *
     * @return list<string>
     */
    public function fieldsByWeight(): array
    {
        $fields = array_keys($this->fields);
        usort($fields, fn (string $x, string $y): int => $this->classOf[$x] <=> $this->classOf[$y]);

        return $fields;
    }

    /** A value as the text it is indexed by: at most SearchDocument::MAX_TEXT bytes of it. */
    private static function text(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            \is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \BackedEnum => (string) $value->value,
            \is_scalar($value) => (string) $value,
            default => throw new \LogicException('A searchable field holds ' . get_debug_type($value) . ', which search cannot read as text.'),
        };

        return \strlen($text) > SearchDocument::MAX_TEXT ? mb_strcut($text, 0, SearchDocument::MAX_TEXT, 'UTF-8') : $text;
    }
}
