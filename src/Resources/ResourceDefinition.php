<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Tinvar\Attributes\AdminResource;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;

/**
 * An #[AdminResource]'s declarations, read once and held to its model:
 * every column, field and filter names a property the model keeps, each a
 * kind it can be, and every bulk action a method the resource has.
 *
 * Read when the resource is registered (ActionRegistry asks), so a typo is
 * a refusal at boot naming the class and the property, never a 500 on the
 * first request to the list. Kept per worker by class: a class does not
 * change while the worker runs, and nothing of a request is kept.
 */
final class ResourceDefinition
{
    /** What the list reads from its query besides its filters, each of which is read by its key. */
    public const array LIST_PARAMETERS = ['page', 'sort', 'direction', 'search'];

    /** @var array<class-string, self> */
    private static array $definitions = [];

    /**
     * @param class-string<Resource> $class
     * @param class-string<Model> $model
     * @param list<array{Column, ModelProperty}> $columns
     * @param list<array{Field, ModelProperty}> $fields
     * @param list<array{Filter, ModelProperty}> $filters
     * @param array<string, BulkAction> $bulkActions by key
     */
    private function __construct(
        public readonly string $class,
        public readonly AdminResource $attribute,
        public readonly string $model,
        public readonly array $columns,
        public readonly array $fields,
        public readonly array $filters,
        public readonly array $bulkActions,
        public readonly Sort $sort,
    ) {}

    /**
     * @param class-string $class
     * @throws \LogicException naming what is wrong with the declarations
     */
    public static function of(string $class): self
    {
        if (isset(self::$definitions[$class])) {
            return self::$definitions[$class];
        }

        $attribute = AdminResource::of($class) ?? throw new \LogicException(
            "{$class} extends " . Resource::class . ' and carries no #[AdminResource]: declare which model it is of and in which panel, '
            . "as in #[AdminResource(model: Product::class, panel: 'admin')].",
        );
        if (!is_subclass_of($class, Resource::class)) {
            throw new \LogicException("{$class} is an #[AdminResource] and does not extend " . Resource::class . ', whose screens and actions it is.');
        }
        $model = $attribute->model;
        if (!is_subclass_of($model, Model::class)) {
            throw new \LogicException("{$class} is an #[AdminResource] of {$model}, which is not a model.");
        }
        $short = Str::shortName($class);

        $columns = [];
        foreach (self::listOf($class::columns(), Column::class, "{$short}::columns()") as $column) {
            $where = "{$short}'s column '{$column->key}'";
            $property = ModelProperty::of($model, $column->key, $where);
            // Not the id: a key is a UUID, which the ORM compares by no LIKE on any database.
            if ($column->searchable && $property->type !== 'string') {
                throw new \LogicException("{$where} is searchable and holds a {$property->type}: the search looks in text. Take searchable() off it.");
            }
            if ($column->sortable && $property->type === 'relation') {
                throw new \LogicException("{$where} is sortable and is a record it belongs to, which sorts by nothing a reader sees. Take sortable() off it.");
            }
            if ($column->kind === ColumnKind::Money && $property->type !== 'int') {
                throw new \LogicException("{$where} is money() and holds a {$property->type}: money is an int of cents.");
            }
            self::assertLabelledBy($column->labelledBy, $property, $where);
            $columns[] = [$column, $property];
        }
        if ($columns === []) {
            throw new \LogicException("{$short} is an #[AdminResource] with no columns: return at least one Column from {$short}::columns().");
        }
        self::assertUnique(array_map(static fn (array $c): string => $c[0]->key, $columns), "{$short}::columns()");

        $fields = [];
        foreach (self::listOf($class::fields(), Field::class, "{$short}::fields()") as $field) {
            $where = "{$short}'s field '{$field->key}'";
            $property = ModelProperty::of($model, $field->key, $where);
            if ($property->type === 'id') {
                throw new \LogicException("{$where} is the id, which the ORM sets: a form never does. Take it out of fields().");
            }
            if ($field->textarea && $property->type !== 'string') {
                throw new \LogicException("{$where} is a textarea() and holds a {$property->type}. Only text is written in one.");
            }
            if (($field->min !== null || $field->max !== null) && !\in_array($property->type, ['string', 'int', 'float'], true)) {
                throw new \LogicException("{$where} has a min() or max() and holds a {$property->type}; they bound numbers and text.");
            }
            self::assertLabelledBy($field->labelledBy, $property, $where);
            $fields[] = [$field, $property];
        }
        self::assertUnique(array_map(static fn (array $f): string => $f[0]->key, $fields), "{$short}::fields()");

        $filters = [];
        foreach (self::listOf($class::filters(), Filter::class, "{$short}::filters()") as $filter) {
            $where = "{$short}'s filter '{$filter->key}'";
            if (\in_array($filter->key, self::LIST_PARAMETERS, true)) {
                throw new \LogicException("{$where} is read from the query under its name, which the list reads as its own " . $filter->key . '. Filter by another property.');
            }
            $property = ModelProperty::of($model, $filter->key, $where);
            if (!\in_array($property->type, ['string', 'int', 'bool', 'enum'], true)) {
                throw new \LogicException("{$where} holds a {$property->type}; a filter narrows by text, a whole number, a bool or an enum.");
            }
            if ($property->type !== 'enum' && $property->type !== 'bool' && $filter->options === null && !$filter->distinct) {
                throw new \LogicException("{$where} offers nothing: give it options([...]) or distinct(), or filter an enum or a bool, whose values it knows.");
            }
            $filters[] = [$filter, $property];
        }
        self::assertUnique(array_map(static fn (array $f): string => $f[0]->key, $filters), "{$short}::filters()");

        $bulkActions = [];
        foreach (self::listOf($class::bulkActions(), BulkAction::class, "{$short}::bulkActions()") as $bulk) {
            if (preg_match('/^[a-z][A-Za-z0-9]{0,39}\z/', $bulk->key) !== 1) {
                throw new \LogicException("{$short}'s bulk action '{$bulk->key}' needs a key of letters and digits, starting with a lower-case letter.");
            }
            if (isset($bulkActions[$bulk->key])) {
                throw new \LogicException("{$short}::bulkActions() has two actions keyed '{$bulk->key}'.");
            }
            if (preg_match('/^[a-z][A-Za-z0-9]*\z/', $bulk->ability) !== 1) {
                throw new \LogicException("{$short}'s bulk action '{$bulk->key}' asks the ability '{$bulk->ability}', which is not a policy method's name.");
            }
            if ($bulk->method !== null) {
                self::assertBulkMethod($class, $bulk, $model, $short);
            }
            $bulkActions[$bulk->key] = $bulk;
        }

        $sort = $class::sort() ?? Sort::desc('id');
        if ($sort->key !== 'id') {
            ModelProperty::of($model, $sort->key, "{$short}::sort()");
        }

        return self::$definitions[$class] = new self($class, $attribute, $model, $columns, $fields, $filters, $bulkActions, $sort);
    }

    /** The column named $key that may be sorted by, or null. */
    public function sortable(string $key): ?ModelProperty
    {
        foreach ($this->columns as [$column, $property]) {
            if ($column->key === $key && $column->sortable) {
                return $property;
            }
        }

        return null;
    }

    /** Whether any column is searched by default. */
    public function hasSearchableColumns(): bool
    {
        foreach ($this->columns as [$column]) {
            if ($column->searchable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of object
     * @param mixed $declared what the method returned
     * @param class-string<T> $of
     * @return list<T>
     */
    private static function listOf(mixed $declared, string $of, string $where): array
    {
        if (!\is_array($declared) || !array_is_list($declared)) {
            throw new \LogicException("{$where} returns a list of " . Str::shortName($of) . '.');
        }
        foreach ($declared as $item) {
            if (!$item instanceof $of) {
                throw new \LogicException("{$where} returns a list of " . Str::shortName($of) . ', and holds a ' . get_debug_type($item) . '.');
            }
        }

        /** @var list<T> */
        return $declared;
    }

    /** @param list<string> $keys */
    private static function assertUnique(array $keys, string $where): void
    {
        $twice = array_keys(array_filter(array_count_values($keys), static fn (int $count): bool => $count > 1));
        if ($twice !== []) {
            throw new \LogicException("{$where} names '" . implode("', '", $twice) . "' twice.");
        }
    }

    private static function assertLabelledBy(?string $labelledBy, ModelProperty $property, string $where): void
    {
        if ($property->type !== 'relation') {
            if ($labelledBy !== null) {
                throw new \LogicException("{$where} is labelledBy('{$labelledBy}') and is not a record it belongs to. Take labelledBy() off it.");
            }

            return;
        }
        /** @var class-string<Model> $related */
        $related = $property->class;
        $label = ModelProperty::of($related, $labelledBy ?? 'name', "{$where}'s label");
        if (!\in_array($label->type, ['string', 'id', 'int'], true)) {
            throw new \LogicException("{$where} is labelled by {$related}::\${$label->name}, a {$label->type}. Label it by text: labelledBy('email').");
        }
    }

    /**
     * @param class-string $class
     * @param class-string<Model> $model
     */
    private static function assertBulkMethod(string $class, BulkAction $bulk, string $model, string $short): void
    {
        $method = (string) $bulk->method;
        if (!method_exists($class, $method)) {
            throw new \LogicException("{$short}'s bulk action '{$bulk->key}' runs {$method}(), which {$short} does not have.");
        }
        $reflection = new \ReflectionMethod($class, $method);
        $parameters = $reflection->getParameters();
        $type = ($parameters[0] ?? null)?->getType();
        if ($reflection->isStatic() || \count($parameters) !== 1 || !$type instanceof \ReflectionNamedType
            || !\in_array($type->getName(), [$model, Model::class], true)) {
            throw new \LogicException(
                "{$short}'s bulk action '{$bulk->key}' runs {$method}(), which takes one " . Str::shortName($model)
                . ' and nothing else: public function ' . $method . '(' . Str::shortName($model) . ' $record): void.',
            );
        }
        if ($reflection->getAttributes(\Tinvar\Attributes\Action::class) !== []) {
            throw new \LogicException("{$short}'s bulk action '{$bulk->key}' runs {$method}(), which is an #[Action] of its own: a bulk action's method is reached only through bulk().");
        }
    }
}
