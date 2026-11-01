<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthorizationException;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Request;
use Tinvar\Orm\Direction;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Resources\Data\ResourceActionsData;
use Tinvar\Resources\Data\ResourceBulkActionData;
use Tinvar\Resources\Data\ResourceColumnData;
use Tinvar\Resources\Data\ResourceDetailData;
use Tinvar\Resources\Data\ResourceFilterData;
use Tinvar\Resources\Data\ResourceFormData;
use Tinvar\Resources\Data\ResourceFormFieldData;
use Tinvar\Resources\Data\ResourceIndexData;
use Tinvar\Resources\Data\ResourceOptionData;
use Tinvar\Resources\Data\ResourceRowData;
use Tinvar\Resources\Data\ResourceSectionData;
use Tinvar\Resources\Data\ResourceShowData;
use Tinvar\Resources\Data\ResourceSummaryData;
use Tinvar\Support\Str;
use Tinvar\Validation\ValidationException;

/**
 * A model's admin screens, from what a subclass declares (ADR 0150).
 *
 *     #[AdminResource(model: Product::class, panel: 'admin'), Domain('admin.{root}')]
 *     final class ProductResource extends Resource
 *     {
 *         public static function columns(): array
 *         {
 *             return [Column::make('name')->sortable()->searchable(), Column::make('priceCents', 'Price')->money()];
 *         }
 *
 *         public static function fields(): array
 *         {
 *             return [Field::make('name')->max(120), Field::make('priceCents', 'Price in cents')->min(0)];
 *         }
 *     }
 *
 * The loaders and actions here are its pages' and its actions' bodies, and
 * final: the router and the action registry register them with the checks
 * #[AdminResource] lists, and what a subclass changes is what it declares
 * and the hooks below -- query(), search(), title(), sections(), saving()
 * and written() -- never who may reach a screen. The model's policy
 * decides that: viewAny, view, create, update and delete, as for any page
 * or action, and with no policy for the model every screen is refused.
 *
 * Rows are read through the ORM, so scoped to the tenant like any query;
 * a record's pages take it by the route's `{record}`, which the binder
 * resolves in that scope -- an id of another tenant's is a 404. What a
 * form posts is read field by field as the model's property is typed, and
 * only the fields declared are ever written.
 *
 * Every screen hands the view Data, never a model; what crosses for a value
 * is text (Data\ResourceRowData), with a kind that says how to draw it.
 */
abstract class Resource
{
    /** The longest search the list reads; past it, the rest is dropped. */
    public const int MAX_SEARCH = 100;

    /** The most records a select of the records a field may belong to offers. */
    public const int MAX_CHOICES = 500;

    public function __construct(
        protected readonly Orm $orm,
        protected readonly Gate $gate,
    ) {}

    /** @return list<Column> the list's columns, and the record page's lines */
    public static function columns(): array
    {
        return [];
    }

    /** @return list<Field> the create and edit forms' inputs; none, and the forms post nothing */
    public static function fields(): array
    {
        return [];
    }

    /** @return list<Filter> */
    public static function filters(): array
    {
        return [];
    }

    /** @return list<BulkAction> */
    public static function bulkActions(): array
    {
        return [];
    }

    /** The list's order until a heading is clicked; newest first by default, as ids are made. */
    public static function sort(): ?Sort
    {
        return null;
    }

    /**
     * The list's query, before its filters, search, order and page: where a
     * resource eager-loads what its title() reads, or narrows what the list
     * shows by default. It narrows the list only: a record outside it is
     * still reached by its URL wherever its policy says it may be, so what
     * a reader may not see is the policy's to refuse, never this.
     *
     * @param Query<Model> $query
     * @return Query<Model>
     */
    protected function query(Query $query): Query
    {
        return $query;
    }

    /**
     * The list's search: where the term appears in a searchable() column,
     * by LIKE, so `%` and `_` in it match any text as they do in SQL, and
     * matched as the database compares text -- SQLite and MySQL ignoring
     * case, Postgres not. A resource searched another way -- an index of
     * its own, the search module -- overrides this, and the list shows its
     * box even with no searchable column.
     *
     * @param Query<Model> $query
     * @return Query<Model>
     */
    protected function search(Query $query, string $term): Query
    {
        $columns = [];
        foreach ($this->definition()->columns as [$column]) {
            if ($column->searchable) {
                $columns[] = $column->key;
            }
        }
        if ($columns === []) {
            return $query;
        }

        return $query->where(static function (Query $group) use ($columns, $term): Query {
            foreach ($columns as $column) {
                $group = $group->orWhere($column, 'like', "%{$term}%");
            }

            return $group;
        });
    }

    /** What a record is called on its page and in the list: its first column's value, by default, or its id. */
    protected function title(Model $record): string
    {
        [$column, $property] = $this->definition()->columns[0];

        return $property->text($record, $column->labelledBy) ?? $record->primaryKey();
    }

    /**
     * Groups of lines under a record's own on its page: where an
     * application shows a record's activity, its history, what it is part
     * of. Read in the page's loader, so in its read-only transaction.
     *
     * @return list<ResourceSectionData>
     */
    protected function sections(Model $record): array
    {
        return [];
    }

    /**
     * Called before a record the form filled is saved: where what no field
     * sets is set -- a number, a slug, a default the form leaves out.
     */
    protected function saving(Model $record, ResourceOperation $operation): void {}

    /**
     * Called once a record is written -- created, updated or deleted, by its
     * form, its delete button or a bulk action -- inside the action's
     * transaction, so what it writes commits with the change or not at all:
     * where an activity log records who did what.
     */
    protected function written(Model $record, ResourceOperation $operation): void {}

    /** The list: GET {path}, under Authorize('viewAny', Model::class). */
    final public function index(Request $request): ResourceIndexData
    {
        $definition = $this->definition();
        $query = $this->query($this->orm->query($definition->model));

        $relations = [];
        foreach ($definition->columns as [$column, $property]) {
            if ($property->type === 'relation') {
                $relations[] = $column->key;
            }
        }
        if ($relations !== []) {
            $query = $query->with(...$relations);
        }

        // Each filter by its own key in the query -- `?status=placed` -- as a
        // query holds only strings and lists of them (Sapi).
        $filters = [];
        foreach ($definition->filters as [$filter, $property]) {
            $options = $this->filterOptions($filter, $property);
            $raw = $request->query[$filter->key] ?? null;
            $value = \is_string($raw) && isset($options[$raw]) ? $raw : null;
            if ($value !== null) {
                $query = $query->where($filter->key, self::typed($property, $value));
            }
            $filters[] = new ResourceFilterData($filter->key, $filter->label ?? $property->label(), self::options($options), $value);
        }

        $searchable = $definition->hasSearchableColumns() || new \ReflectionMethod($this, 'search')->getDeclaringClass()->getName() !== self::class;
        $search = \is_string($request->query['search'] ?? null) ? mb_substr(trim($request->query['search']), 0, self::MAX_SEARCH) : '';
        if ($searchable && $search !== '') {
            $query = $this->search($query, $search);
        }

        $asked = \is_string($request->query['sort'] ?? null) ? $request->query['sort'] : null;
        $sort = $asked !== null && $definition->sortable($asked) !== null ? $asked : null;
        $askedDirection = \is_string($request->query['direction'] ?? null) ? Direction::tryFrom($request->query['direction']) : null;
        $direction = $sort === null ? $definition->sort->direction : ($askedDirection ?? Direction::Asc);
        $by = $sort ?? $definition->sort->key;
        $query = $query->orderBy($by, $direction);
        if ($by !== 'id') {
            // Rows that tie keep one order from page to page.
            $query = $query->orderBy('id', $direction);
        }

        $perPage = $definition->attribute->perPage;
        $page = \is_string($request->query['page'] ?? null) ? (Str::wholeNumber($request->query['page']) ?? 1) : 1;
        $page = max(1, min($page, 1_000_000));
        $result = $query->paginate($page, $perPage);
        if ($result->items === [] && $page > $result->lastPage()) {
            // Past the end: the last page there is, not an empty one.
            $page = $result->lastPage();
            $result = $query->paginate($page, $perPage);
        }

        $rows = [];
        foreach ($result->items as $record) {
            $cells = [];
            foreach ($definition->columns as [$column, $property]) {
                $cells[$column->key] = $property->text($record, $column->labelledBy);
            }
            $id = $record->primaryKey();
            $rows[] = new ResourceRowData(
                id: $id,
                title: $this->title($record),
                cells: $cells,
                showUrl: $this->url("/{$id}"),
                editUrl: $this->may('update', $record) ? $this->url("/{$id}/edit") : null,
                canDelete: $this->may('delete', $record),
            );
        }

        $columns = [];
        foreach ($definition->columns as [$column, $property]) {
            $columns[] = new ResourceColumnData(
                key: $column->key,
                label: $column->label ?? $property->label(),
                kind: $column->kind ?? $property->columnKind(),
                sortable: $column->sortable,
                options: self::options($property->enumOptions()),
            );
        }

        $bulk = [];
        foreach ($definition->bulkActions as $action) {
            $bulk[] = new ResourceBulkActionData($action->key, $action->label, $action->destructive);
        }

        return new ResourceIndexData(
            resource: $this->summary(),
            columns: $columns,
            rows: $rows,
            filters: $filters,
            searchable: $searchable,
            search: $search,
            sort: $sort,
            direction: $direction,
            page: $page,
            lastPage: $result->lastPage(),
            perPage: $perPage,
            total: $result->total,
            bulkActions: $bulk,
            actions: $this->actions(),
        );
    }

    /** The create form: GET {path}/new, under Authorize('create', Model::class). */
    final public function create(): ResourceFormData
    {
        return $this->form(null);
    }

    /** One record: GET {path}/{record}, under Authorize('view', 'record'). */
    final public function show(Model $record): ResourceShowData
    {
        $details = [];
        foreach ($this->definition()->columns as [$column, $property]) {
            $details[] = new ResourceDetailData(
                key: $column->key,
                label: $column->label ?? $property->label(),
                kind: $column->kind ?? $property->columnKind(),
                value: $property->text($record, $column->labelledBy),
                options: self::options($property->enumOptions()),
            );
        }
        $id = $record->primaryKey();

        return new ResourceShowData(
            resource: $this->summary(),
            id: $id,
            title: $this->title($record),
            details: $details,
            sections: self::sectionsOf($this->sections($record), static::class),
            editUrl: $this->may('update', $record) ? $this->url("/{$id}/edit") : null,
            canDelete: $this->may('delete', $record),
            actions: $this->actions(),
        );
    }

    /** The edit form: GET {path}/{record}/edit, under Authorize('update', 'record'). */
    final public function edit(Model $record): ResourceFormData
    {
        return $this->form($record);
    }

    /** Creates a record from the form, and shows it. */
    #[Action(transaction: true), Authorize('create', Model::class)]
    final public function store(ResourceInput $input): ActionResult
    {
        $model = $this->definition()->model;
        $record = new $model();
        $this->fill($record, $input->values);
        $this->saving($record, ResourceOperation::Created);
        $this->orm->save($record);
        $this->written($record, ResourceOperation::Created);

        return ActionResult::redirect($this->url('/' . $record->primaryKey()));
    }

    /** Saves the form to the record, and shows it. */
    #[Action(transaction: true), Authorize('update', 'record')]
    final public function update(Model $record, ResourceInput $input): ActionResult
    {
        $this->fill($record, $input->values);
        $this->saving($record, ResourceOperation::Updated);
        $this->orm->save($record);
        $this->written($record, ResourceOperation::Updated);

        return ActionResult::redirect($this->url('/' . $record->primaryKey()));
    }

    /** Deletes the record, and goes back to the list. */
    #[Action(transaction: true), Authorize('delete', 'record')]
    final public function destroy(Model $record): ActionResult
    {
        $this->written($record, ResourceOperation::Deleted);
        $this->orm->delete($record);

        return ActionResult::redirect($this->url(''));
    }

    /**
     * Runs a bulk action on the rows ticked: every row read again in the
     * tenant's scope and asked its policy's ability for the action, and
     * none changed if any one is refused or gone. The class-level check is
     * viewAny, which the list itself is under; each row's is the action's.
     */
    #[Action(transaction: true), Authorize('viewAny', Model::class)]
    final public function bulk(ResourceBulkInput $input): ActionResult
    {
        $definition = $this->definition();
        $action = $definition->bulkActions[$input->action]
            ?? throw new ValidationException(['action' => ["'{$input->action}' is not one of this list's bulk actions."]]);

        // Typed for the client as strings; what arrived is held to that here.
        /** @var list<mixed> $posted */
        $posted = $input->ids;
        $ids = [];
        foreach ($posted as $id) {
            if (!\is_string($id) || $id === '') {
                throw new ValidationException(['ids' => ['Each row is named by its id.']]);
            }
            $ids[$id] = true;
        }

        $records = [];
        foreach (array_keys($ids) as $id) {
            $records[] = $this->orm->find($definition->model, (string) $id)
                ?? throw new ValidationException(['ids' => ['A row ticked is not there any more. Reload the list and tick them again.']]);
        }
        foreach ($records as $record) {
            if (!$this->may($action->ability, $record)) {
                throw new ForbiddenException("You may not {$action->label} every row ticked, so none was changed.");
            }
        }

        foreach ($records as $record) {
            if ($action->method === null) {
                $this->written($record, ResourceOperation::Deleted);
                $this->orm->delete($record);
                continue;
            }
            $this->{$action->method}($record);
            $this->orm->save($record);
            $this->written($record, ResourceOperation::Updated);
        }

        return ActionResult::revalidate("{$definition->attribute->panel}.{$definition->attribute->slug()}.index");
    }

    /** The resource's declarations, read and checked once a worker. */
    final protected function definition(): ResourceDefinition
    {
        return ResourceDefinition::of(static::class);
    }

    /**
     * Whether the reader's policy allows $ability on $record: what decides
     * which links a screen draws, and no more -- the page or action behind
     * each asks again. A policy with no such ability draws no link; the
     * screen or action itself, asked, is the server error a policy missing
     * an ability always is.
     */
    final protected function may(string $ability, ?Model $record = null): bool
    {
        try {
            return $record === null
                ? $this->gate->allows(new Authorize($ability, $this->definition()->model), [])
                : $this->gate->allows(new Authorize($ability, AdminResource::RECORD), [AdminResource::RECORD => $record]);
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** A path under the list's, on the host the screens are on. */
    final protected function url(string $below): string
    {
        $path = $this->definition()->attribute->basePath() . $below;

        return $path === '' ? '/' : $path;
    }

    private function summary(): ResourceSummaryData
    {
        $attribute = $this->definition()->attribute;

        return new ResourceSummaryData(
            name: $attribute->slug(),
            singular: $attribute->singular(),
            plural: $attribute->pluralLabel(),
            indexUrl: $this->url(''),
            createUrl: $this->may('create') ? $this->url('/new') : null,
        );
    }

    private function actions(): ResourceActionsData
    {
        $short = Str::shortName(static::class);

        return new ResourceActionsData("{$short}.store", "{$short}.update", "{$short}.destroy", "{$short}.bulk");
    }

    private function form(?Model $record): ResourceFormData
    {
        $definition = $this->definition();
        $fields = [];
        foreach ($definition->fields as [$field, $property]) {
            $fields[] = new ResourceFormFieldData(
                name: $field->key,
                label: $field->label ?? $property->label(),
                kind: self::fieldKind($field, $property),
                required: self::required($property),
                value: $property->formValue($record),
                options: self::options($property->type === 'relation' ? $this->choices($field, $property) : $property->enumOptions()),
                help: $field->help,
                min: $field->min === null ? null : (float) $field->min,
                max: $field->max === null ? null : (float) $field->max,
                decimal: $property->type === 'float',
            );
        }
        $id = $record?->primaryKey();

        return new ResourceFormData(
            resource: $this->summary(),
            mode: $record === null ? FormMode::Create : FormMode::Edit,
            id: $id,
            title: $record === null ? "New {$definition->attribute->singular()}" : $this->title($record),
            fields: $fields,
            action: $record === null ? $this->actions()->store : $this->actions()->update,
            cancelUrl: $record === null ? $this->url('') : $this->url("/{$id}"),
        );
    }

    /**
     * The records a field may name, value => label, in the label's order:
     * read in the tenant's scope, so another tenant's are never offered.
     *
     * @return array<string, string>
     */
    private function choices(Field $field, ModelProperty $property): array
    {
        /** @var class-string<Model> $related */
        $related = $property->class;
        $label = $field->labelledBy ?? 'name';
        $choices = [];
        foreach ($this->orm->query($related)->orderBy($label)->orderBy('id')->limit(self::MAX_CHOICES)->fetch() as $row) {
            $choices[$row->primaryKey()] = (string) $row->{$label};
        }

        return $choices;
    }

    /**
     * Each declared field read from what was posted, as its property is
     * typed, and set on $record -- or every error, under `values.<name>`,
     * before anything is set.
     *
     * @param array<string, mixed> $values
     */
    private function fill(Model $record, array $values): void
    {
        $errors = [];
        $read = [];
        foreach ($this->definition()->fields as [$field, $property]) {
            $label = $field->label ?? $property->label();
            [$value, $error] = $this->read($field, $property, $values[$field->key] ?? null, $label);
            if ($error !== null) {
                $errors["values.{$field->key}"] = [$error];
                continue;
            }
            $read[$field->key] = $value;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        foreach ($read as $key => $value) {
            $record->{$key} = $value;
        }
    }

    /** @return array{mixed, string|null} the value, or what is wrong with what was posted */
    private function read(Field $field, ModelProperty $property, mixed $raw, string $label): array
    {
        if ($property->type === 'bool') {
            // A checkbox left off posts nothing.
            return match (true) {
                $raw === null, $raw === '', $raw === false, $raw === '0', $raw === 'false', $raw === 'off' => [false, null],
                $raw === true, $raw === '1', $raw === 'true', $raw === 'on' => [true, null],
                default => [null, "{$label} is on or off."],
            };
        }
        if (\is_int($raw) || \is_float($raw)) {
            $raw = (string) $raw;
        }
        if ($raw !== null && !\is_string($raw)) {
            return [null, "{$label} is text."];
        }
        if ($raw === null || trim($raw) === '') {
            return self::required($property) ? [null, "{$label} is required."] : [null, null];
        }

        switch ($property->type) {
            case 'string':
                $length = mb_strlen($raw);
                if ($field->min !== null && $length < $field->min) {
                    return [null, "{$label} is at least {$field->min} characters."];
                }
                if ($field->max !== null && $length > $field->max) {
                    return [null, "{$label} is at most {$field->max} characters."];
                }

                return [$raw, null];
            case 'int':
            case 'float':
                $number = $property->type === 'int' ? Str::wholeNumber(trim($raw)) : (is_numeric(trim($raw)) ? (float) trim($raw) : null);
                if ($number === null || (\is_float($number) && !is_finite($number))) {
                    return [null, $property->type === 'int' ? "{$label} is a whole number." : "{$label} is a number."];
                }
                if ($field->min !== null && $number < $field->min) {
                    return [null, "{$label} is at least {$field->min}."];
                }
                if ($field->max !== null && $number > $field->max) {
                    return [null, "{$label} is at most {$field->max}."];
                }

                return [$number, null];
            case 'enum':
                /** @var class-string<\BackedEnum> $enum */
                $enum = (string) $property->class;
                $case = self::typed($property, $raw);

                return $case instanceof $enum ? [$case, null] : [null, "{$label} is one of " . implode(', ', $property->enumOptions()) . '.'];
            case 'moment':
                $moment = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new \DateTimeZone('UTC'))
                    ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $raw, new \DateTimeZone('UTC'));
                if ($moment === false) {
                    return [null, "{$label} is a date and time, as 2026-11-01T09:30."];
                }

                return [CarbonImmutable::instance($moment), null];
            case 'relation':
                /** @var class-string<Model> $related */
                $related = (string) $property->class;
                $found = $this->orm->find($related, $raw);

                return $found === null ? [null, "{$label} does not match a record."] : [$found, null];
        }

        return [null, "{$label} cannot be written by a form."];
    }

    /**
     * A filter's options, value => label.
     *
     * @return array<string, string>
     */
    private function filterOptions(Filter $filter, ModelProperty $property): array
    {
        if ($filter->options !== null) {
            return $filter->options;
        }
        if ($property->type === 'enum') {
            return $property->enumOptions();
        }
        if ($property->type === 'bool') {
            return ['true' => 'Yes', 'false' => 'No'];
        }
        $options = [];
        $values = $this->orm->query($this->definition()->model)->distinct()->orderBy($filter->key)->limit(Filter::MAX_DISTINCT)->pluck($filter->key);
        foreach ($values as $value) {
            if (\is_scalar($value)) {
                $options[(string) $value] = (string) $value;
            }
        }

        return $options;
    }

    /** A value as text, as the property's type: an enum's case, a whole number, a bool; null for one that is none. */
    private static function typed(ModelProperty $property, string $value): mixed
    {
        switch ($property->type) {
            case 'enum':
                /** @var class-string<\BackedEnum> $enum */
                $enum = (string) $property->class;
                $intBacked = new \ReflectionEnum($enum)->getBackingType()?->getName() === 'int';
                if ($intBacked) {
                    $number = Str::wholeNumber($value);

                    return $number === null ? null : $enum::tryFrom($number);
                }

                return $enum::tryFrom($value);
            case 'int':
                return Str::wholeNumber($value);
            case 'bool':
                return $value === 'true';
            default:
                return $value;
        }
    }

    private static function required(ModelProperty $property): bool
    {
        return $property->type !== 'bool' && !$property->nullable;
    }

    private static function fieldKind(Field $field, ModelProperty $property): FieldKind
    {
        return match ($property->type) {
            'int', 'float' => FieldKind::Number,
            'bool' => FieldKind::Checkbox,
            'enum', 'relation' => FieldKind::Select,
            'moment' => FieldKind::DateTime,
            default => $field->textarea ? FieldKind::Textarea : FieldKind::Text,
        };
    }

    /**
     * @param array<string, string> $options
     * @return list<ResourceOptionData>
     */
    private static function options(array $options): array
    {
        $out = [];
        foreach ($options as $value => $label) {
            $out[] = new ResourceOptionData((string) $value, $label);
        }

        return $out;
    }

    /**
     * @param mixed $sections what sections() returned
     * @return list<ResourceSectionData>
     */
    private static function sectionsOf(mixed $sections, string $class): array
    {
        if (!\is_array($sections) || !array_is_list($sections)) {
            throw new \LogicException("{$class}::sections() returns a list of ResourceSectionData.");
        }
        foreach ($sections as $section) {
            if (!$section instanceof ResourceSectionData) {
                throw new \LogicException("{$class}::sections() returns a list of ResourceSectionData, and holds a " . get_debug_type($section) . '.');
            }
        }

        return $sections;
    }
}
