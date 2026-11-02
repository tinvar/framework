<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Policy;
use Tinvar\Attributes\Query;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Data\Data;
use Tinvar\Http\Request;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Direction;
use Tinvar\Orm\Factory;
use Tinvar\Orm\Migrations\Migration;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table as SchemaTable;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\ValidationException;

/**
 * The text of each file make:resource writes, in the shape of the
 * skeleton's and the shop's own code, formatted as pint and prettier leave
 * it -- as Sources is for the single-class generators.
 *
 * Every name reaching these was held to a shape by Names and
 * ResourceFields, so none can carry a quote, a newline, a backtick or a
 * `*\/` out of the string, template or comment it is written in.
 *
 * What the files say about who may: every page, query and action names
 * the model or its row in an #[Authorize], so the policy written beside
 * them decides each, and that policy lets in anyone signed in and nobody
 * else. A guest is refused everything; narrowing it to the right people is
 * the one decision left to the developer, and the command says so. In an
 * app that uses tenancy the model is a tenant's (#[TenantScoped]), its
 * table carries the tenant, and the policy lets in only a member of the
 * tenant in force -- anyone signed in would be every tenant's users.
 *
 * Each PHP file's body is written before its `use` block: a class is
 * imported as the body names it (Uses), so the block is only whole once
 * the body is.
 */
final class ResourceSources
{
    private const string HEADER = "<?php\n\ndeclare(strict_types=1);\n\n";

    /** The browser's datetime-local value, which a timestamp field is posted as. */
    public const string MOMENT_PATTERN = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}';

    /** A model: its typed properties are the table, as make:model writes one. */
    public static function model(ResourcePlan $p): string
    {
        $uses = new Uses($p->model);
        $lines = ['    #[' . $uses->name(Id::class) . '] public private(set) string $id;'];
        foreach ($p->fields as $field) {
            $type = self::modelType($field, $p, $uses);
            $lines[] = match ($field->kind) {
                // A relation with no default: the ORM reads it lazily, and
                // a nullable one is cleared by assigning null.
                'belongsTo' => self::belongsTo($field, $type, $uses),
                'text' => \sprintf("    #[%s(type: 'text')] public %s \$%s%s;", $uses->name(Column::class), $type, $field->name, $field->nullable ? ' = null' : ''),
                'bool' => \sprintf('    #[%s] public %s $%s = %s;', $uses->name(Column::class), $type, $field->name, $field->nullable ? 'null' : 'false'),
                default => \sprintf('    #[%s] public %s $%s%s;', $uses->name(Column::class), $type, $field->name, $field->nullable ? ' = null' : ''),
            };
        }
        $carbon = $uses->name(CarbonImmutable::class);

        $keys = '';
        foreach ($p->ofKind('belongsTo') as $field) {
            $related = Names::short((string) $field->model);
            $keys .= "\n    /** The {$field->label()}'s id, read from the row's key without loading the " . lcfirst($related) . ". */\n"
                . "    public function {$field->name}Id(): " . ($field->nullable ? '?string' : 'string') . "\n"
                . "    {\n"
                . ($field->nullable
                    ? "        \$key = \$this->foreignKey('{$field->name}');\n\n        return \$key === null ? null : (string) \$key;\n"
                    : "        return (string) \$this->foreignKey('{$field->name}');\n")
                . "    }\n";
        }

        $body = "/**\n"
            . Sources::wrap("A row of the {$p->table} table, written by `tinvar make:resource`. Its typed properties are the table -- type, nullability and default from PHP, #[Column] for what PHP cannot say -- and the migration written beside it makes it. The id is a UUIDv7 the ORM sets on insert, read-only outside it.")
            . ($p->tenanted ? " *\n" . Sources::wrap("A tenant's: #[TenantScoped] has the ORM read and write only the rows of the tenant in force, and stamp that tenant on each it inserts.") : '')
            . " *\n"
            . Sources::wrap('A model never crosses to the renderer: ' . Names::short($p->record) . ' copies what a page needs out of one, field by field, and the encoder refuses anything else.')
            . " */\n"
            . "#[{$uses->name(Table::class)}('{$p->table}')" . ($p->tenanted ? ', ' . $uses->name(TenantScoped::class) : '') . "]\n"
            . 'final class ' . Names::short($p->model) . " extends {$uses->name(Model::class)}\n"
            . "{\n"
            . implode("\n", $lines) . "\n\n"
            . "    /** When it was made, in UTC: stamped by the ORM on insert (ADR 0145). */\n"
            . "    #[{$uses->name(Column::class)}] public {$carbon} \$createdAt;\n\n"
            . "    /** When it was last saved, in UTC: stamped by the ORM on every save. */\n"
            . "    #[{$uses->name(Column::class)}] public {$carbon} \$updatedAt;\n"
            . $keys
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->model) . ";\n\n"
            . self::block($uses, [Table::class, Model::class])
            . $body;
    }

    /** A backed enum for one enum field, its cases in the order they were named. */
    public static function enum(ResourcePlan $p, ResourceField $field): string
    {
        $class = $p->enums[$field->name];
        $cases = '';
        $labels = '';
        foreach ($field->cases as $case => $value) {
            $cases .= "    case {$case} = '{$value}';\n";
            $label = ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $case)));
            $labels .= "            self::{$case} => '{$label}',\n";
        }

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($class) . ";\n\n"
            . "/**\n"
            . Sources::wrap("What " . Sources::article($p->short) . " {$p->short}'s {$field->label()} may be. The `{$p->table}.{$field->column()}` column holds the value and nothing else: the migration's enum() checks it against these cases, and a Data field of this type crosses to the renderer as its value.")
            . " */\n"
            . 'enum ' . Names::short($class) . ": string\n"
            . "{\n"
            . $cases
            . "\n"
            . "    /** How a person reads it, in a form's choices. */\n"
            . "    public function label(): string\n"
            . "    {\n"
            . "        return match (\$this) {\n"
            . $labels
            . "        };\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * The migration that makes the model's table, as `tinvar make:migration`
     * would write it from the model: keys from the models they reference
     * (foreign()), moments as timestamp() columns, enums held to their
     * cases (enum()), and the created and updated stamps (timestamps()).
     */
    public static function migration(ResourcePlan $p): string
    {
        $imports = [Migration::class, Schema::class, SchemaTable::class];
        $lines = ["            \$table->uuid('id')->primary();"];
        if ($p->tenanted) {
            // Its tenant, indexed, and on Postgres held by row-level security.
            $lines[] = '            $table->tenant();';
        }
        $indexes = [];
        foreach ($p->fields as $field) {
            $column = $field->column();
            $call = match ($field->kind) {
                'string' => "\$table->string('{$column}')",
                'text' => "\$table->text('{$column}')",
                'int' => "\$table->integer('{$column}')",
                'float' => "\$table->float('{$column}')",
                'bool' => "\$table->boolean('{$column}')",
                'timestamp' => "\$table->timestamp('{$column}')",
                'enum' => "\$table->enum('{$column}', " . Names::short($p->enums[$field->name]) . '::class)',
                // Named only where it is not the one foreign() would name it.
                'belongsTo' => '$table->foreign(' . Names::short((string) $field->model) . '::class'
                    . ($column === Str::snake(Names::short((string) $field->model)) . '_id' ? '' : ", '{$column}'") . ')',
            };
            if ($field->kind === 'enum') {
                $imports[] = $p->enums[$field->name];
            }
            if ($field->kind === 'belongsTo') {
                $imports[] = (string) $field->model;
                $indexes[] = "            \$table->index(['{$column}']);";
            }
            if ($field->nullable) {
                $call .= '->nullable()';
            }
            if ($field->kind === 'bool' && !$field->nullable) {
                $call .= '->default(false)';
            }
            if ($field->kind === 'belongsTo' && $field->nullable) {
                $call .= '->nullOnDelete()';
            }
            $lines[] = "            {$call};";
        }
        $lines[] = '            $table->timestamps();';

        $imports = array_values(array_unique($imports));
        $uses = array_map(static fn (string $class): string => "use {$class};", $imports);
        usort($uses, strcasecmp(...));

        return self::HEADER
            . implode("\n", $uses) . "\n\n"
            . "/**\n"
            . Sources::wrap("The {$p->table} table, for " . Names::short($p->model) . ', written by `tinvar make:resource`: what `tinvar make:migration` would write from the model, so running that next finds nothing to add. Times are in UTC.')
            . " */\n"
            . "return new class extends Migration {\n"
            . "    public function up(Schema \$schema): void\n"
            . "    {\n"
            . "        \$schema->create('{$p->table}', function (Table \$table): void {\n"
            . implode("\n", [...$lines, ...$indexes]) . "\n"
            . "        });\n"
            . "    }\n\n"
            . "    public function down(Schema \$schema): void\n"
            . "    {\n"
            . "        \$schema->drop('{$p->table}');\n"
            . "    }\n"
            . "};\n";
    }

    /** A typed factory, filling every field a row needs but its relations, which for() gives it. */
    public static function factory(ResourcePlan $p): string
    {
        $uses = new Uses($p->factory);
        $modelName = $uses->name($p->model);
        $base = $uses->name(Factory::class);
        $variable = $p->parameter === 'n' ? 'model' : $p->parameter;

        $assignments = [];
        foreach ($p->fields as $field) {
            if ($field->nullable || $field->kind === 'belongsTo') {
                continue;
            }
            $value = match ($field->kind) {
                'string' => "\"{$field->label()} {\$n}\"",
                'text' => "\"The {$field->label()} of {$p->short} {\$n}.\"",
                'int' => '$n',
                'float' => '$n + 0.5',
                'bool' => 'false',
                'timestamp' => 'new ' . $uses->name(CarbonImmutable::class) . "('2026-01-01 00:00:00', 'UTC')",
                'enum' => $uses->name($p->enums[$field->name]) . '::' . array_key_first($field->cases),
            };
            $assignments[] = "        \${$variable}->{$field->name} = {$value};";
        }
        $counted = array_filter($assignments, static fn (string $line): bool => str_contains($line, '$n'));
        $relations = array_map(static fn (ResourceField $field): string => $field->name, array_filter($p->ofKind('belongsTo'), static fn (ResourceField $field): bool => !$field->nullable));

        $about = 'Typed: definition() returns ' . Sources::article($modelName) . " {$modelName} with its fields set by name, so a renamed field is a type error rather than a wrong string key.";
        if ($relations !== []) {
            $about .= ' Its ' . implode(' and ', $relations) . ' ' . (\count($relations) === 1 ? 'is' : 'are') . ' for the caller to give, as in `' . Names::short($p->factory) . '::new()->for($' . $relations[0] . ')->create($orm)`.';
        }

        $body = "/**\n"
            . Sources::wrap($about)
            . " *\n"
            . " * @extends {$base}<{$modelName}>\n"
            . " */\n"
            . 'final class ' . Names::short($p->factory) . " extends {$base}\n"
            . "{\n"
            . ($counted === [] ? '' : "    private int \$next = 1;\n\n")
            . "    public function definition(): {$modelName}\n"
            . "    {\n"
            . ($counted === [] ? '' : "        \$n = \$this->next++;\n\n")
            . "        \${$variable} = new {$modelName}();\n"
            . ($assignments === [] ? '' : implode("\n", $assignments) . "\n")
            . "\n"
            . "        return \${$variable};\n"
            . "    }\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->factory) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** One row as the renderer is sent it, and how one is copied out of a model. */
    public static function record(ResourcePlan $p): string
    {
        $uses = new Uses($p->record);
        $modelName = $uses->name($p->model);
        $base = $uses->name(Data::class);
        $carbon = $uses->name(CarbonImmutable::class);
        $parameters = ['        public string $id,'];
        $copies = ["            id: \${$p->parameter}->id,"];
        foreach ($p->fields as $field) {
            [$name, $type] = self::dataField($field, $p, $uses);
            $parameters[] = "        public {$type} \${$name},";
            $copies[] = "            {$name}: \${$p->parameter}->" . ($field->kind === 'belongsTo' ? "{$field->name}Id()" : $field->name) . ',';
        }
        $parameters[] = "        public {$carbon} \$createdAt,";
        $parameters[] = "        public {$carbon} \$updatedAt,";
        $copies[] = "            createdAt: \${$p->parameter}->createdAt,";
        $copies[] = "            updatedAt: \${$p->parameter}->updatedAt,";

        $body = "/**\n"
            . Sources::wrap(Sources::article($p->short) === 'an' ? "An {$p->short}, as the renderer is sent it" : "A {$p->short}, as the renderer is sent it")
            . " *\n"
            . Sources::wrap('Each field is a promoted constructor parameter, typed, and `tinvar codegen` makes the TypeScript the views read from them: an enum crosses as its value, a moment as ISO 8601 in UTC, a relation as its id.')
            . " */\n"
            . 'final readonly class ' . Names::short($p->record) . " extends {$base}\n"
            . "{\n"
            . "    public function __construct(\n"
            . implode("\n", $parameters) . "\n"
            . "    ) {}\n\n"
            . "    /** Copied out of a row, field by field: its relations by their keys, so none is read. */\n"
            . "    public static function of({$modelName} \${$p->parameter}): self\n"
            . "    {\n"
            . "        return new self(\n"
            . implode("\n", $copies) . "\n"
            . "        );\n"
            . "    }\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->record) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** A page of rows, and where it stands in all of them. */
    public static function listData(ResourcePlan $p): string
    {
        $uses = new Uses($p->list);
        $base = $uses->name(Data::class);
        $record = $uses->name($p->record);

        $body = "/** One page of {$p->table}, newest first, and how many there are in all. */\n"
            . 'final readonly class ' . Names::short($p->list) . " extends {$base}\n"
            . "{\n"
            . "    /** @param list<{$record}> \$items */\n"
            . "    public function __construct(\n"
            . "        public array \$items,\n"
            . "        public int \$page,\n"
            . "        public int \$perPage,\n"
            . "        public int \$total,\n"
            . "        public int \$lastPage,\n"
            . "    ) {}\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->list) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** One choice a form offers: the value posted, and what the person reads. */
    public static function option(ResourcePlan $p): string
    {
        $uses = new Uses($p->option);

        $body = "/** One choice the {$p->parameter} form offers: the value it posts, and what the person reads. */\n"
            . 'final readonly class ' . Names::short($p->option) . ' extends ' . $uses->name(Data::class) . "\n"
            . "{\n"
            . "    public function __construct(\n"
            . "        public string \$value,\n"
            . "        public string \$label,\n"
            . "    ) {}\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->option) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** What the create and edit pages draw their form from. */
    public static function formData(ResourcePlan $p): string
    {
        $uses = new Uses($p->form);
        $record = $uses->name($p->record);
        $option = $uses->name($p->option);
        $parameters = ["        public ?{$record} \$record,"];
        $docs = [];
        foreach ($p->ofKind('enum', 'belongsTo') as $field) {
            $parameters[] = "        public array \${$field->name}Options,";
            $docs[] = "     * @param list<{$option}> \${$field->name}Options";
        }

        $body = "/**\n"
            . Sources::wrap("The {$p->parameter} form: the row it edits, or none for a new one, and the choices each of its select fields offers.")
            . " */\n"
            . 'final readonly class ' . Names::short($p->form) . ' extends ' . $uses->name(Data::class) . "\n"
            . "{\n"
            . ($docs === [] ? '' : "    /**\n" . implode("\n", $docs) . "\n     */\n")
            . "    public function __construct(\n"
            . implode("\n", $parameters) . "\n"
            . "    ) {}\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->form) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The list query: a page of rows, newest first. */
    public static function listQuery(ResourcePlan $p): string
    {
        $uses = new Uses($p->listQuery);
        $model = $uses->name($p->model);
        $list = $uses->name($p->list);
        $record = $uses->name($p->record);
        $direction = $uses->name(Direction::class);
        $short = Names::short($p->listQuery);

        $body = "/**\n"
            . Sources::wrap(Names::queryName($p->listQuery) . ": the {$p->table}, newest first, a page at a time. What the index page loads, and what a server component may await through its generated `@queries` function.")
            . " *\n"
            . Sources::wrap("Its #[Authorize] asks {$model}'s policy, through viewAny(), before this body runs: on a render and over /_query alike. The rows are the tenant's alone where the model is a tenant's, as the ORM bounds every read.")
            . " */\n"
            . "#[{$uses->name(Query::class)}, {$uses->name(Authorize::class)}('viewAny', {$model}::class)]\n"
            . "final class {$short}\n"
            . "{\n"
            . "    /** How many rows one page holds. */\n"
            . "    public const int PER_PAGE = 25;\n\n"
            . "    public function __construct(private readonly {$uses->name(Orm::class)} \$orm) {}\n\n"
            . "    public function __invoke(int \$page = 1): {$list}\n"
            . "    {\n"
            . "        \$found = {$model}::query(\$this->orm)\n"
            . "            ->orderBy('createdAt', {$direction}::Desc)\n"
            . "            ->orderBy('id', {$direction}::Desc)\n"
            . "            ->paginate(max(1, \$page), self::PER_PAGE);\n\n"
            . "        return new {$list}(\n"
            . "            items: array_map({$record}::of(...), \$found->items),\n"
            . "            page: \$found->page,\n"
            . "            perPage: \$found->perPage,\n"
            . "            total: \$found->total,\n"
            . "            lastPage: \$found->lastPage(),\n"
            . "        );\n"
            . "    }\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->listQuery) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The detail query: one row, by the model it is handed. */
    public static function showQuery(ResourcePlan $p): string
    {
        $uses = new Uses($p->showQuery);
        $model = $uses->name($p->model);
        $record = $uses->name($p->record);
        $short = Names::short($p->showQuery);

        $body = "/**\n"
            . Sources::wrap(Names::queryName($p->showQuery) . ': one ' . lcfirst($p->short) . ', by its id. What the show page loads, and what a server component may await through its generated `@queries` function.')
            . " *\n"
            . Sources::wrap("\${$p->parameter} is resolved and scoped -- a missing or foreign id is a 404 -- and its policy decides through view(), before this body runs.")
            . " */\n"
            . "#[{$uses->name(Query::class)}, {$uses->name(Authorize::class)}('view', '{$p->parameter}')]\n"
            . "final class {$short}\n"
            . "{\n"
            . "    public function __invoke({$model} \${$p->parameter}): {$record}\n"
            . "    {\n"
            . "        return {$record}::of(\${$p->parameter});\n"
            . "    }\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->showQuery) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** What the create and edit pages read their form's choices with. */
    public static function formOptions(ResourcePlan $p): string
    {
        $uses = new Uses($p->formOptions);
        $model = $uses->name($p->model);
        $form = $uses->name($p->form);
        $record = $uses->name($p->record);
        $option = $p->ofKind('enum', 'belongsTo') === [] ? '' : $uses->name($p->option);
        $short = Names::short($p->formOptions);
        $relations = $p->ofKind('belongsTo');

        $arguments = ["            record: \${$p->parameter} === null ? null : {$record}::of(\${$p->parameter}),"];
        $methods = '';
        foreach ($p->fields as $field) {
            if ($field->kind === 'enum') {
                $enum = $uses->name($p->enums[$field->name]);
                $arguments[] = "            {$field->name}Options: array_map(static fn ({$enum} \$case): {$option} => new {$option}(\$case->value, \$case->label()), {$enum}::cases()),";
            }
            if ($field->kind === 'belongsTo') {
                $arguments[] = "            {$field->name}Options: \$this->{$field->name}Options(\${$p->parameter}),";
                $related = $uses->name((string) $field->model);
                $label = $p->choiceLabels[$field->name];
                $row = lcfirst(Names::short((string) $field->model));
                $row = \in_array($row, [$p->parameter, 'this', 'case', 'chosen', 'rows'], true) ? 'row' : $row;
                $current = "        \$chosen = \${$p->parameter}?->{$field->name};\n"
                    . "        if (\$chosen !== null && !\\in_array(\$chosen->id, array_map(static fn ({$related} \${$row}): string => \${$row}->id, \$rows), true)) {\n";
                $methods .= "\n"
                    . "    /**\n"
                    . Sources::wrap('The ' . strtolower($field->label()) . ' ' . Sources::article($p->short) . ' ' . strtolower(self::title($p->short)) . " may belong to: the first MAX_CHOICES by their {$label}, and the one it belongs to now wherever that falls, so saving the form never moves it unasked.", 70, '     * ')
                    . "     *\n"
                    . "     * @return list<{$option}>\n"
                    . "     */\n"
                    . "    private function {$field->name}Options(?{$model} \${$p->parameter}): array\n"
                    . "    {\n"
                    . "        \$rows = {$related}::query(\$this->orm)->orderBy('{$label}')->limit(self::MAX_CHOICES)->fetch();\n"
                    . $current
                    . "            \$rows[] = \$chosen;\n"
                    . "        }\n\n"
                    . "        return array_map(static fn ({$related} \${$row}): {$option} => new {$option}(\${$row}->id, " . ($label === 'id' ? "\${$row}->id" : "\${$row}->{$label}") . "), \$rows);\n"
                    . "    }\n";
            }
        }

        $body = "/**\n"
            . Sources::wrap("What the {$p->parameter} form is drawn from: the row it edits, and the choices of its select fields. Read for the create and edit pages, whose checks have run by then" . ($relations === [] ? '.' : ', each of which names the related models it lists, so their policies decide who may read these choices.'))
            . " */\n"
            . "final class {$short}\n"
            . "{\n"
            . ($relations === [] ? '' : "    /** The most rows of a related model a select lists: past this, a search is the better control. */\n    public const int MAX_CHOICES = 200;\n\n    public function __construct(private readonly {$uses->name(Orm::class)} \$orm) {}\n\n")
            . "    public function for(?{$model} \${$p->parameter}): {$form}\n"
            . "    {\n"
            . "        return new {$form}(\n"
            . implode("\n", $arguments) . "\n"
            . "        );\n"
            . "    }\n"
            . $methods
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->formOptions) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The four pages: index, show, create and edit. */
    public static function page(ResourcePlan $p, string $screen): string
    {
        $class = match ($screen) {
            'index' => $p->indexPage,
            'show' => $p->showPage,
            'create' => $p->createPage,
            default => $p->editPage,
        };
        $uses = new Uses($class);
        $model = $uses->name($p->model);
        $authorize = $uses->name(Authorize::class);
        $short = Names::short($class);
        $param = $p->parameter;

        $path = match ($screen) {
            'index' => $p->basePath,
            'show' => "{$p->basePath}/{{$param}}",
            'create' => "{$p->basePath}/new",
            default => "{$p->basePath}/{{$param}}/edit",
        };
        // The related models a form lists are read with the page, so their
        // policies are asked too: a select of every customer is a list of
        // customers, whoever's form it is in.
        $related = [];
        foreach (\in_array($screen, ['create', 'edit'], true) ? $p->ofKind('belongsTo') : [] as $field) {
            $related[] = \sprintf("%s('viewAny', %s::class)", $authorize, $uses->name((string) $field->model));
        }
        $checks = match ($screen) {
            'index' => ["{$authorize}('viewAny', {$model}::class)"],
            'show' => ["{$authorize}('view', '{$param}')"],
            'create' => ["{$authorize}('create', {$model}::class)", ...$related],
            default => ["{$authorize}('update', '{$param}')", ...$related],
        };
        $checks = array_values(array_unique($checks));

        $attribute = \sprintf("#[%s('%s', view: '%s', name: '%s'), %s]", $uses->name(Page::class), $path, $p->view($screen), $p->route($screen), implode(', ', $checks));
        if (\strlen($attribute) > 120) {
            $attribute = \sprintf("#[%s('%s', view: '%s', name: '%s'),\n    %s,]", $uses->name(Page::class), $path, $p->view($screen), $p->route($screen), implode(",\n    ", $checks));
        }

        [$dependency, $signature, $body, $about] = match ($screen) {
            'index' => [
                $uses->name($p->listQuery),
                "__invoke({$uses->name(Request::class)} \$request): {$uses->name($p->list)}",
                "        // ?page=2, or the first page for anything that is not a page number.\n"
                    . "        \$page = filter_var(\$request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);\n\n"
                    . "        return (\$this->list)(\\is_int(\$page) ? \$page : 1);\n",
                "The {$p->table}, newest first, a page at a time: what " . Names::short($p->listQuery) . ' reads. Its check asks the policy through viewAny().',
            ],
            'show' => [
                $uses->name($p->showQuery),
                "__invoke({$model} \${$param}): {$uses->name($p->record)}",
                "        return (\$this->show)(\${$param});\n",
                "One " . lcfirst($p->short) . ": \${$param} is resolved and scoped -- a missing or foreign id is a 404 -- and its policy decides, through view(), before this loader runs.",
            ],
            'create' => [
                $uses->name($p->formOptions),
                "__invoke(): {$uses->name($p->form)}",
                "        return \$this->options->for(null);\n",
                'The form a new ' . lcfirst($p->short) . " is made with, posted to {$uses->name($p->actions)}::create(). Its check asks the policy through create().",
            ],
            default => [
                $uses->name($p->formOptions),
                "__invoke({$model} \${$param}): {$uses->name($p->form)}",
                "        return \$this->options->for(\${$param});\n",
                "The form " . Sources::article($p->short) . ' ' . lcfirst($p->short) . " is changed with, posted to {$uses->name($p->actions)}::update(). \${$param} is resolved and scoped, and its policy decides through update().",
            ],
        };
        $property = match ($screen) {
            'index' => 'list',
            'show' => 'show',
            default => 'options',
        };

        $body = "/**\n"
            . Sources::wrap("The page at {$path}, drawn by {$p->view($screen)}.tsx. {$about}")
            . " */\n"
            . "{$attribute}\n"
            . "final class {$short}\n"
            . "{\n"
            . "    public function __construct(private readonly {$dependency} \${$property}) {}\n\n"
            . "    public function {$signature}\n"
            . "    {\n"
            . $body
            . "    }\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The form request create() and update() are posted. */
    public static function request(ResourcePlan $p): string
    {
        $uses = new Uses($p->request);
        $required = [];
        $optional = [];
        foreach ($p->fields as $field) {
            $base = match ($field->kind) {
                'string', 'text', 'timestamp' => 'string',
                'int', 'float', 'bool' => $field->kind,
                'enum' => $uses->name($p->enums[$field->name]),
                'belongsTo' => $uses->name((string) $field->model),
            };
            $rules = match ($field->kind) {
                'string' => [$uses->name(Min::class) . '(1)', $uses->name(Max::class) . '(255)'],
                'text' => [$uses->name(Min::class) . '(1)', $uses->name(Max::class) . '(65535)'],
                'timestamp' => [$uses->name(Pattern::class) . "('" . self::MOMENT_PATTERN . "', 'Give a date and time, as in 2026-11-01T09:30.')"],
                default => [],
            };
            $line = ($rules === [] ? '' : '        #[' . implode(', ', $rules) . "]\n")
                . '        public ' . ($field->nullable ? '?' : '') . "{$base} \${$field->name}";
            if ($field->nullable) {
                $optional[] = "{$line} = null,";
            } elseif ($field->kind === 'bool') {
                // A checkbox left clear is not posted at all.
                $optional[] = "{$line} = false,";
            } else {
                $required[] = "{$line},";
            }
        }
        $relations = array_map(static fn (ResourceField $field): string => "\${$field->name}", $p->ofKind('belongsTo'));

        $body = "/**\n"
            . Sources::wrap('What ' . Names::short($p->actions) . '::create() and update() are posted. Each field is a constructor parameter: its type says what the value may be, and the rule attributes what the type cannot. The action\'s body runs only for a request that passed them, and its errors go back to their fields.')
            . ($relations === [] ? '' : " *\n" . Sources::wrap(implode(' and ', $relations) . ' ' . (\count($relations) === 1 ? 'is a row' : 'are rows') . ', resolved by its id and scoped to the tenant -- a missing or foreign id is a 404 -- and named by an #[Authorize] on each action, so its policy decides whether it may be chosen.'))
            . " */\n"
            . 'final readonly class ' . Names::short($p->request) . ' extends ' . $uses->name(FormRequest::class) . "\n"
            . "{\n"
            . "    public function __construct(\n"
            . implode("\n", [...$required, ...$optional]) . "\n"
            . "    ) {}\n"
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->request) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The class holding create(), update() and destroy(). */
    public static function actions(ResourcePlan $p): string
    {
        $uses = new Uses($p->actions);
        $model = $uses->name($p->model);
        $request = $uses->name($p->request);
        $result = $uses->name(ActionResult::class);
        $action = $uses->name(Action::class);
        $authorize = $uses->name(Authorize::class);
        $param = $p->parameter;
        $short = Names::short($p->actions);

        $relations = array_map(static fn (ResourceField $field): string => "{$authorize}('view', '{$field->name}')", $p->ofKind('belongsTo'));
        $createChecks = implode(', ', ["{$authorize}('create', {$model}::class)", ...$relations]);
        $updateChecks = implode(', ', ["{$authorize}('update', '{$param}')", ...$relations]);

        $fill = [];
        $moments = false;
        foreach ($p->fields as $field) {
            if ($field->kind === 'timestamp') {
                $moments = true;
                $value = $field->nullable
                    ? "\$request->{$field->name} === null ? null : self::moment('{$field->name}', \$request->{$field->name})"
                    : "self::moment('{$field->name}', \$request->{$field->name})";
            } else {
                $value = "\$request->{$field->name}";
            }
            $fill[] = "        \${$param}->{$field->name} = {$value};";
        }

        $momentMethod = '';
        if ($moments) {
            $carbon = $uses->name(CarbonImmutable::class);
            $momentMethod = "\n"
                . "    /**\n"
                . "     * A moment the form posted as the browser's datetime-local -- 2026-11-01T09:30\n"
                . "     * -- read in UTC. One the calendar has no such day for is refused on its field.\n"
                . "     */\n"
                . "    private static function moment(string \$field, string \$value): {$carbon}\n"
                . "    {\n"
                . "        \$moment = {$carbon}::createFromFormat('Y-m-d\\TH:i', \$value, 'UTC');\n"
                . "        if (!\$moment instanceof {$carbon} || \$moment->format('Y-m-d\\TH:i') !== \$value) {\n"
                . "            throw new {$uses->name(ValidationException::class)}([\$field => ['Give a date and time the calendar has, as in 2026-11-01T09:30.']]);\n"
                . "        }\n\n"
                . "        return \$moment;\n"
                . "    }\n";
        }

        $doc = static fn (string $text): string => "    /**\n" . Sources::wrap($text, 70, '     * ') . "     */\n";
        $one = lcfirst($p->short);

        $body = "/**\n"
            . Sources::wrap("Making, changing and deleting {$p->table}. Each is called from the browser through the stub `tinvar codegen` writes to `@actions/{$short}`, with the session's CSRF token, through the actions' throttle; the policy is asked and the request validated before its body runs.")
            . " */\n"
            . "final class {$short}\n"
            . "{\n"
            . "    public function __construct(private readonly {$uses->name(Orm::class)} \$orm) {}\n\n"
            . $doc("A new {$one}, then its page. The policy decides through create()" . ($relations === [] ? '.' : ', and each related row\'s through view().'))
            . "    #[{$action}, {$createChecks}]\n"
            . "    public function create({$request} \$request): {$result}\n"
            . "    {\n"
            . "        \${$param} = new {$model}();\n"
            . "        \$this->fill(\${$param}, \$request);\n"
            . "        // Its id, createdAt and updatedAt are set by the ORM as it is inserted.\n"
            . "        \$this->orm->save(\${$param});\n\n"
            . "        return {$result}::redirect('{$p->basePath}/' . \${$param}->id);\n"
            . "    }\n\n"
            . $doc("The {$one} changed, then its page. \${$param} is resolved and scoped, and its policy decides through update()" . ($relations === [] ? '.' : ', and each related row\'s through view().'))
            . "    #[{$action}, {$updateChecks}]\n"
            . "    public function update({$model} \${$param}, {$request} \$request): {$result}\n"
            . "    {\n"
            . "        \$this->fill(\${$param}, \$request);\n"
            . "        \$this->orm->save(\${$param});\n\n"
            . "        return {$result}::redirect('{$p->basePath}/' . \${$param}->id);\n"
            . "    }\n\n"
            . $doc("The {$one} deleted, then the list. \${$param} is resolved and scoped, and its policy decides through delete().")
            . "    #[{$action}, {$authorize}('delete', '{$param}')]\n"
            . "    public function destroy({$model} \${$param}): {$result}\n"
            . "    {\n"
            . "        \$this->orm->delete(\${$param});\n\n"
            . "        return {$result}::redirect('{$p->basePath}');\n"
            . "    }\n\n"
            . "    private function fill({$model} \${$param}, {$request} \$request): void\n"
            . "    {\n"
            . implode("\n", $fill) . "\n"
            . "    }\n"
            . $momentMethod
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->actions) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /** The policy every check the resource declares asks. */
    public static function policy(ResourcePlan $p): string
    {
        $uses = new Uses($p->policy);
        $model = $uses->name($p->model);
        $user = $uses->name(Authenticatable::class);
        $param = $p->parameter;
        $one = lcfirst($p->short);
        $allowed = $p->tenanted ? '$this->member($user)' : '$user !== null';

        $ability = static fn (string $name, string $what, bool $row): string => "    /** {$what} */\n"
            . "    public function {$name}(?{$user} \$user" . ($row ? ", {$model} \${$param}" : '') . "): bool\n"
            . "    {\n"
            . "        return {$allowed};\n"
            . "    }\n";

        $constructor = '';
        $member = '';
        if ($p->tenanted) {
            $constructor = "    public function __construct(\n"
                . "        private readonly {$uses->name(Orm::class)} \$orm,\n"
                . "        private readonly {$uses->name(PanelContext::class)} \$panels,\n"
                . "        private readonly ?{$uses->name(Memberships::class)} \$memberships = null,\n"
                . "    ) {}\n\n";
            $member = "\n    /**\n"
                . Sources::wrap("Whether \$user holds a membership of the tenant in force, in the panel the request is in. Each {$one} it is asked of is that tenant's already: the ORM reads no other's.", 70, '     * ')
                . "     */\n"
                . "    private function member(?{$user} \$user): bool\n"
                . "    {\n"
                . "        \$tenant = \$this->orm->tenant();\n"
                . "        \$panel = \$this->panels->currentOrNull();\n"
                . "        if (\$user === null || \$tenant === '' || \$panel === null || \$this->memberships === null) {\n"
                . "            return false;\n"
                . "        }\n\n"
                . "        return \$this->memberships->membership(\$user, \$panel, \$tenant) !== null;\n"
                . "    }\n";
        }

        $body = "/**\n"
            . Sources::wrap("Who may do what with {$p->table}: #[Policy] is how discovery registers it for {$model}. Every #[Authorize] naming " . Sources::article($p->short) . " {$one} asks here, before any loader, query body or action body runs; an ability with no method here refuses everyone.")
            . " *\n"
            . ($p->tenanted
                ? Sources::wrap("{$p->plural} are a tenant's. As `tinvar make:resource` wrote it, a member of the tenant in force -- holding a membership of the panel the request is in, by the app's Memberships -- may do anything with any of its {$p->table}, and nobody else anything: not a guest, another tenant's user, a request in no tenant or in no panel, nor anyone at all while the app gives no Memberships. Say here what each member may -- a role, a permission -- before it ships.")
                : Sources::wrap("As `tinvar make:resource` wrote it, anyone signed in may do anything with any {$one}, and a guest nothing. Say here who may -- an owner, a role, a panel's permission -- before it ships."))
            . " */\n"
            . "#[{$uses->name(Policy::class)}({$model}::class)]\n"
            . 'final class ' . Names::short($p->policy) . "\n"
            . "{\n"
            . $constructor
            . $ability('viewAny', "The list of {$p->table}.", false) . "\n"
            . $ability('view', "One {$one}.", true) . "\n"
            . $ability('create', "Making one.", false) . "\n"
            . $ability('update', "Changing one.", true) . "\n"
            . $ability('delete', "Deleting one.", true)
            . $member
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($p->policy) . ";\n\n"
            . $uses->block()
            . $body;
    }

    /**
     * The model's screens in a panel (ADR 0151): an #[AdminResource] the
     * framework registers as four pages and four actions behind the panel's
     * guard and membership, each asking the same policy. Every field is in
     * its form; the list shows them but long text, sorts by any but a
     * relation and searches the lines of text; an enum is a filter.
     */
    public static function adminResource(ResourcePlan $p): string
    {
        $class = (string) $p->resource;
        $uses = new Uses($class);
        $model = $uses->name($p->model);
        $columnClass = $uses->name(\Tinvar\Resources\Column::class);
        $fieldClass = $uses->name(\Tinvar\Resources\Field::class);
        $columns = [];
        $fields = [];
        $filters = [];
        foreach ($p->fields as $field) {
            if ($field->kind !== 'text') {
                $columns[] = "            {$columnClass}::make('{$field->name}')"
                    . ($field->kind === 'belongsTo' ? '' : '->sortable()')
                    . ($field->kind === 'string' ? '->searchable()' : '') . ',';
            }
            $fields[] = "            {$fieldClass}::make('{$field->name}')" . ($field->kind === 'text' ? '->textarea()' : '') . ',';
            if ($field->kind === 'enum') {
                $filters[] = '            ' . $uses->name(\Tinvar\Resources\Filter::class) . "::make('{$field->name}'),";
            }
        }
        $list = static fn (array $lines): string => $lines === [] ? '[]' : "[\n" . implode("\n", $lines) . "\n        ]";
        $method = static fn (string $name, string $returns, string $about): string => "    /** {$about} */\n"
            . "    public static function {$name}(): array\n"
            . "    {\n"
            . "        return {$returns};\n"
            . "    }\n";

        $body = "/**\n"
            . Sources::wrap("{$p->plural} in the {$p->panel} panel, written by `tinvar make:resource --panel={$p->panel}`: a list, a record, a create and an edit form, deleting one and deleting several, at {$p->panelPath()}. Each is a page or an action behind the panel's guard and membership, asking " . Names::short($p->policy) . ' as the resource\'s own pages do; the panel draws them with its three resource views.')
            . " */\n"
            . '#[' . $uses->name(\Tinvar\Attributes\AdminResource::class) . "(model: {$model}::class, panel: '{$p->panel}', path: '{$p->panelPath()}')]\n"
            . 'final class ' . Names::short($class) . ' extends ' . $uses->name(\Tinvar\Resources\Resource::class) . "\n"
            . "{\n"
            . $method('columns', $list($columns), 'What the list shows, sorts by and searches.') . "\n"
            . $method('fields', $list($fields), 'What the create and edit forms ask, each read as the model types it.') . "\n"
            . $method('filters', $list($filters), 'What the list may be narrowed to.') . "\n"
            . $method('bulkActions', '[' . $uses->name(\Tinvar\Resources\BulkAction::class) . '::delete()]', 'What may be done to several at once: each asks the policy of each row.')
            . "}\n";

        return self::HEADER
            . 'namespace ' . Sources::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . $body;
    }

    // ------------------------------------------------------------- views
    //
    // Each view is written as prettier leaves it at the workspace's print
    // width (110): a line that would not fit is broken as prettier breaks
    // it (tag(), wrapped()), and each field is named once a line -- a
    // nullable one through a small formatter in the view, never in a
    // ternary repeating its name -- so how a line breaks never depends on
    // how long a name is past what ResourceFields allows.

    /** The index view: a table of the rows, a link to each, and pages. */
    public static function indexView(ResourcePlan $p, string $typesImport): string
    {
        $row = self::rowVariable($p);
        $title = self::title($p->plural);
        $label = $p->labelField();
        $columns = \array_slice(array_values(array_filter(
            $p->fields,
            static fn (ResourceField $field): bool => $field !== $label && $field->kind !== 'text',
        )), 0, 4);

        $heads = ['              <Th>' . ($label === null ? 'Id' : $label->label()) . '</Th>'];
        $link = self::tag(18, 'Link', ["href={`{$p->basePath}/\${{$row}.id}`}"], ($label === null ? "{{$row}.id}" : self::shown($label, $row)));
        $cells = ["                <Td>\n{$link}\n                </Td>"];
        $imports = ['EmptyState', 'Heading', 'RelativeTime', 'Table', 'TBody', 'Td', 'Text', 'Th', 'THead', 'Tr'];
        foreach ($columns as $field) {
            $numeric = \in_array($field->kind, ['int', 'float', 'timestamp'], true);
            $heads[] = '              <Th' . ($numeric ? ' numeric' : '') . ">{$field->label()}</Th>";
            $open = '<Td' . ($numeric ? ' numeric' : '') . ($field->kind === 'belongsTo' ? ' mono' : '') . '>';
            $cells[] = self::wrapped(16, $open, self::shown($field, $row), '</Td>');
        }
        $heads[] = '              <Th numeric>Made</Th>';
        $cells[] = "                <Td numeric>\n                  <RelativeTime date={{$row}.createdAt} />\n                </Td>";

        $one = strtolower(self::title($p->short));
        $many = strtolower($title);
        $empty = self::tag(8, 'EmptyState', ["title=\"No {$many} yet\"", 'description="Each one made shows up here, newest first."']);

        return "import * as stylex from '@stylexjs/stylex';\n"
            . 'import { ' . implode(', ', $imports) . " } from '@meridian/ui';\n"
            . "import { space } from '@meridian/ui/tokens.stylex';\n"
            . "import { Title } from '@tinvar/react/head';\n"
            . "import { Link } from '@tinvar/react/link';\n"
            . "import type { PageProps } from '{$typesImport}';\n\n"
            . "/**\n"
            . Sources::wrap("The {$many}, newest first, a page at a time: what " . Names::short($p->indexPage) . ' returns. Written by `tinvar make:resource`; draw it as the app should.', 77)
            . " */\n"
            . self::signature('data', $p->view('index'))
            . "  return (\n"
            . "    <div {...stylex.props(styles.page)}>\n"
            . "      <Title>{$title}</Title>\n"
            . "      <header {...stylex.props(styles.header)}>\n"
            . "        <Heading level={1} size=\"title1\">\n"
            . "          {$title}\n"
            . "        </Heading>\n"
            . self::tag(8, 'Link', ["href=\"{$p->basePath}/new\""], "New {$one}") . "\n"
            . "      </header>\n"
            . "      {data.total === 0 ? (\n"
            . "{$empty}\n"
            . "      ) : (\n"
            . "        <Table\n"
            . "          label=\"{$title}\"\n"
            . "          footer={\n"
            . "            data.lastPage > 1 ? (\n"
            . "              <nav aria-label=\"Pages\" {...stylex.props(styles.pager)}>\n"
            . self::conditional(16, 'data.page > 1', self::tag(18, 'Link', ["href={`{$p->basePath}?page=\${data.page - 1}`}"], 'Newer')) . "\n"
            . "                <Text size=\"sm\" tone=\"secondary\">{`Page \${data.page} of \${data.lastPage}`}</Text>\n"
            . self::conditional(16, 'data.page < data.lastPage', self::tag(18, 'Link', ["href={`{$p->basePath}?page=\${data.page + 1}`}"], 'Older')) . "\n"
            . "              </nav>\n"
            . "            ) : undefined\n"
            . "          }\n"
            . "        >\n"
            . "          <THead>\n"
            . "            <Tr>\n"
            . implode("\n", $heads) . "\n"
            . "            </Tr>\n"
            . "          </THead>\n"
            . "          <TBody>\n"
            . "            {data.items.map(({$row}) => (\n"
            . "              <Tr key={{$row}.id}>\n"
            . implode("\n", $cells) . "\n"
            . "              </Tr>\n"
            . "            ))}\n"
            . "          </TBody>\n"
            . "        </Table>\n"
            . "      )}\n"
            . "    </div>\n"
            . "  );\n"
            . "}\n"
            . self::formatters($p->fields, false)
            . "\n"
            . "const styles = stylex.create({\n"
            . "  page: { display: 'flex', flexDirection: 'column', rowGap: space.s6 },\n"
            . "  header: { alignItems: 'center', display: 'flex', justifyContent: 'space-between' },\n"
            . "  pager: { alignItems: 'center', columnGap: space.s4, display: 'flex' },\n"
            . "});\n";
    }

    /** The show view: every field, a link to edit it, and a button to delete it. */
    public static function showView(ResourcePlan $p, string $typesImport, string $deleteImport): string
    {
        $row = self::rowVariable($p);
        $items = [];
        foreach ($p->fields as $field) {
            $items[] = self::item($field->label(), self::shown($field, $row, expression: true));
        }
        $items[] = self::item('Made', "<RelativeTime date={{$row}.createdAt} format=\"datetime\" />");
        $items[] = self::item('Changed', "<RelativeTime date={{$row}.updatedAt} format=\"datetime\" />");
        $label = $p->labelField();
        $heading = $label === null ? self::title($p->short) : self::shown($label, $row);
        $one = strtolower(self::title($p->short));
        $delete = Names::short($p->deleteComponent);

        return "import * as stylex from '@stylexjs/stylex';\n"
            . "import { DescriptionList, Heading, RelativeTime } from '@meridian/ui';\n"
            . "import { space } from '@meridian/ui/tokens.stylex';\n"
            . "import { Title } from '@tinvar/react/head';\n"
            . "import { Link } from '@tinvar/react/link';\n"
            . "import type { PageProps } from '{$typesImport}';\n"
            . "import { {$delete} } from '{$deleteImport}';\n\n"
            . "/**\n"
            . Sources::wrap("One {$one}: what " . Names::short($p->showPage) . ' returns, with the way to change it and to delete it. Written by `tinvar make:resource`; draw it as the app should.', 77)
            . " */\n"
            . self::signature("data: {$row}", $p->view('show'))
            . "  return (\n"
            . "    <div {...stylex.props(styles.page)}>\n"
            . "      <Title>{$heading}</Title>\n"
            . "      <header {...stylex.props(styles.header)}>\n"
            . "        <Heading level={1} size=\"title1\">\n"
            . "          {$heading}\n"
            . "        </Heading>\n"
            . self::tag(8, 'Link', ["href={`{$p->basePath}/\${{$row}.id}/edit`}"], 'Edit') . "\n"
            . "      </header>\n"
            . "      <DescriptionList\n"
            . "        items={[\n"
            . implode("\n", $items) . "\n"
            . "        ]}\n"
            . "      />\n"
            . "      <{$delete} id={{$row}.id} />\n"
            . "    </div>\n"
            . "  );\n"
            . "}\n"
            . self::formatters($p->fields, true)
            . "\n"
            . "const styles = stylex.create({\n"
            . "  page: { display: 'flex', flexDirection: 'column', rowGap: space.s6 },\n"
            . "  header: { alignItems: 'center', display: 'flex', justifyContent: 'space-between' },\n"
            . "});\n";
    }

    /** The create or the edit view: a heading and the form. */
    public static function formView(ResourcePlan $p, string $screen, string $typesImport, string $formImport): string
    {
        $one = strtolower(self::title($p->short));
        $title = $screen === 'create' ? "New {$one}" : "Edit {$one}";
        $page = $screen === 'create' ? $p->createPage : $p->editPage;
        $form = Names::short($p->formComponent);

        return "import * as stylex from '@stylexjs/stylex';\n"
            . "import { Heading } from '@meridian/ui';\n"
            . "import { space } from '@meridian/ui/tokens.stylex';\n"
            . "import { Title } from '@tinvar/react/head';\n"
            . "import type { PageProps } from '{$typesImport}';\n"
            . "import { {$form} } from '{$formImport}';\n\n"
            . "/**\n"
            . Sources::wrap(($screen === 'create' ? "The form a new {$one} is made with" : "The form {$one} is changed with") . ': what ' . Names::short($page) . " returns, drawn by {$form}. Written by `tinvar make:resource`.", 77)
            . " */\n"
            . self::signature('data', $p->view($screen))
            . "  return (\n"
            . "    <div {...stylex.props(styles.page)}>\n"
            . "      <Title>{$title}</Title>\n"
            . "      <Heading level={1} size=\"title1\">\n"
            . "        {$title}\n"
            . "      </Heading>\n"
            . "      <{$form} data={data} />\n"
            . "    </div>\n"
            . "  );\n"
            . "}\n\n"
            . "const styles = stylex.create({\n"
            . "  page: { display: 'flex', flexDirection: 'column', maxWidth: '640px', rowGap: space.s6 },\n"
            . "});\n";
    }

    /** The form, a client component posting to create() or update(). */
    public static function formComponent(ResourcePlan $p, string $typesImport): string
    {
        $one = strtolower(self::title($p->short));
        $imports = ['Button', 'Field'];
        $controls = [];
        foreach ($p->fields as $field) {
            $name = $field->name;
            $required = $field->nullable ? [] : ['required'];
            $props = ["label=\"{$field->label()}\""];
            if ($field->kind === 'timestamp') {
                $props[] = 'hint="In UTC."';
            }
            $props[] = "error={form.errors.{$name}}";
            if ($field->nullable) {
                $props[] = 'optional';
            }
            $control = match ($field->kind) {
                'string' => self::tag(12, 'Input', ["name=\"{$name}\"", "defaultValue={record?.{$name} ?? ''}", 'maxLength={255}', ...$required]),
                'text' => self::tag(12, 'Textarea', ["name=\"{$name}\"", "defaultValue={record?.{$name} ?? ''}", 'rows={5}', ...$required]),
                'int' => self::tag(12, 'Input', ["name=\"{$name}\"", 'type="number"', 'step={1}', "defaultValue={record?.{$name} ?? ''}", ...$required]),
                'float' => self::tag(12, 'Input', ["name=\"{$name}\"", 'type="number"', 'step="any"', "defaultValue={record?.{$name} ?? ''}", ...$required]),
                'timestamp' => self::tag(12, 'Input', ["name=\"{$name}\"", 'type="datetime-local"', "defaultValue={record?.{$name}?.slice(0, 16) ?? ''}", ...$required]),
                'bool' => self::tag(12, 'Checkbox', ["name=\"{$name}\"", 'value="1"', 'label="Yes"', "defaultChecked={record?.{$name} ?? false}"]),
                'enum', 'belongsTo' => self::tag(
                    12,
                    'Select',
                    ["name=\"{$name}\"", "defaultValue={record?.{$name}" . ($field->kind === 'belongsTo' ? 'Id' : '') . " ?? ''}", ...$required],
                    ($field->nullable ? "              <option value=\"\">None</option>\n" : '')
                        . "              {data.{$name}Options.map((option) => (\n"
                        . "                <option key={option.value} value={option.value}>\n"
                        . "                  {option.label}\n"
                        . "                </option>\n"
                        . '              ))}',
                ),
            };
            $imports[] = match ($field->kind) {
                'text' => 'Textarea',
                'bool' => 'Checkbox',
                'enum', 'belongsTo' => 'Select',
                default => 'Input',
            };
            $controls[] = self::tag(10, 'Field', $props, $control);
        }
        $imports = array_values(array_unique($imports));
        usort($imports, strcasecmp(...));
        $short = Names::short($p->formComponent);
        $formType = Names::short($p->form);

        return "'use client';\n\n"
            . "import * as stylex from '@stylexjs/stylex';\n"
            . 'import { ' . implode(', ', $imports) . " } from '@meridian/ui';\n"
            . "import { space } from '@meridian/ui/tokens.stylex';\n"
            . "import { Form } from '@tinvar/react/form';\n"
            . "import { create, update } from '@actions/" . Names::short($p->actions) . "';\n"
            . "import type { {$formType} } from '{$typesImport}';\n\n"
            . "/**\n"
            . Sources::wrap("The {$one} form: a new one posted to " . Names::short($p->actions) . "::create(), or the one it is handed to update(). A real form: before JavaScript loads it is an ordinary post, with the session's CSRF token in a hidden field, and a mistake comes back as the page with the error on its field. Once hydrated it submits in place, and the action's answer goes to the {$one}'s page.", 77)
            . " */\n"
            . "export function {$short}({ data }: { data: {$formType} }) {\n"
            . "  const record = data.record;\n\n"
            . "  return (\n"
            . "    <Form {...stylex.props(styles.form)} action={record === null ? create : update}>\n"
            . "      {(form) => (\n"
            . "        <>\n"
            . "          {record === null ? null : <input type=\"hidden\" name=\"{$p->parameter}\" value={record.id} />}\n"
            . implode("\n", $controls) . "\n"
            . "          <div {...stylex.props(styles.actions)}>\n"
            . "            <Button type=\"submit\" variant=\"primary\" disabled={form.processing}>\n"
            . "              {record === null ? 'Create' : 'Save'} {$one}\n"
            . "            </Button>\n"
            . "          </div>\n"
            . "        </>\n"
            . "      )}\n"
            . "    </Form>\n"
            . "  );\n"
            . "}\n\n"
            . "const styles = stylex.create({\n"
            . "  form: { display: 'flex', flexDirection: 'column', rowGap: space.s4 },\n"
            . "  actions: { display: 'flex', justifyContent: 'flex-end' },\n"
            . "});\n";
    }

    /** The delete button, a client component posting to destroy() once the person confirms. */
    public static function deleteComponent(ResourcePlan $p): string
    {
        $one = strtolower(self::title($p->short));
        $short = Names::short($p->deleteComponent);

        return "'use client';\n\n"
            . "import { Button } from '@meridian/ui';\n"
            . "import { Form } from '@tinvar/react/form';\n"
            . "import { destroy } from '@actions/" . Names::short($p->actions) . "';\n\n"
            . "/**\n"
            . Sources::wrap("Deletes the {$one} whose id it is handed, through " . Names::short($p->actions) . "::destroy(), once the person says yes; the action's answer goes back to the list. A real form, so it posts before script loads too -- without the question, which needs script.", 77)
            . " */\n"
            . "export function {$short}({ id }: { id: string }) {\n"
            . "  const sure = (): boolean => window.confirm('Delete this {$one}? It cannot be undone.');\n\n"
            . "  return (\n"
            . "    <Form action={destroy} onBefore={sure}>\n"
            . "      {(form) => (\n"
            . "        <>\n"
            . "          <input type=\"hidden\" name=\"{$p->parameter}\" value={id} />\n"
            . "          <Button type=\"submit\" variant=\"danger\" disabled={form.processing}>\n"
            . "            Delete {$one}\n"
            . "          </Button>\n"
            . "        </>\n"
            . "      )}\n"
            . "    </Form>\n"
            . "  );\n"
            . "}\n";
    }

    // ----------------------------------------------------------- helpers

    /**
     * The `use` block, with $first among the imports -- for a file whose
     * own text names them before Uses has been asked.
     *
     * @param list<string> $first
     */
    private static function block(Uses $uses, array $first): string
    {
        foreach ($first as $class) {
            $uses->name($class);
        }

        return $uses->block();
    }

    /**
     * A relation's property: its key named where the ORM would name it
     * otherwise -- after the model, customer_id, for a `recipient` -- and a
     * nullable one cleared when the row it names is deleted.
     */
    private static function belongsTo(ResourceField $field, string $type, Uses $uses): string
    {
        $arguments = [];
        if ($field->column() !== Str::snake(Names::short((string) $field->model)) . '_id') {
            $arguments[] = "foreignKey: '{$field->column()}'";
        }
        if ($field->nullable) {
            $arguments[] = "onDelete: 'set null'";
        }

        return \sprintf('    #[%s%s] public %s $%s;', $uses->name(BelongsTo::class), $arguments === [] ? '' : '(' . implode(', ', $arguments) . ')', $type, $field->name);
    }

    /** A field's type on the model. */
    private static function modelType(ResourceField $field, ResourcePlan $p, Uses $uses): string
    {
        $base = match ($field->kind) {
            'string', 'text' => 'string',
            'int', 'float', 'bool' => $field->kind,
            'timestamp' => $uses->name(CarbonImmutable::class),
            'enum' => $uses->name($p->enums[$field->name]),
            'belongsTo' => $uses->name((string) $field->model),
        };

        return ($field->nullable ? '?' : '') . $base;
    }

    /**
     * A field as its Data class has it: a relation as its id.
     *
     * @return array{string, string} its name and type
     */
    private static function dataField(ResourceField $field, ResourcePlan $p, Uses $uses): array
    {
        if ($field->kind === 'belongsTo') {
            return ["{$field->name}Id", ($field->nullable ? '?' : '') . 'string'];
        }

        return [$field->name, self::modelType($field, $p, $uses)];
    }

    /** The variable one row is in, in a view: `order`, or `row` where that is a word the view uses. */
    private static function rowVariable(ResourcePlan $p): string
    {
        return \in_array($p->parameter, ['data', 'page', 'option', 'form', 'record', 'styles', 'stylex', 'space', 'count', 'yesNo', 'sure', 'id'], true) ? 'row' : $p->parameter;
    }

    /** Words for a heading: Order lines for OrderLines. */
    private static function title(string $studly): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $studly)));
    }

    /**
     * How a field is shown: as JSX children (a `{...}` expression or an
     * element), or -- $expression -- as a value inside one. Each names the
     * field once; a number, a yes or no and a moment go through the
     * formatters the view declares (formatters()).
     */
    private static function shown(ResourceField $field, string $row, bool $expression = false): string
    {
        $value = "{$row}.{$field->name}" . ($field->kind === 'belongsTo' ? 'Id' : '');
        if ($field->kind === 'timestamp') {
            return "<Moment date={{$value}} />";
        }
        $inner = match ($field->kind) {
            'int', 'float' => "count({$value})",
            'bool' => "yesNo({$value})",
            default => $field->nullable ? "{$value} ?? '—'" : $value,
        };

        return $expression ? $inner : "{{$inner}}";
    }

    /**
     * The formatters the fields shown in a view need, declared after it:
     * one for numbers, one for yes or no, one for moments -- each taking a
     * value that may be null and showing a dash for it.
     *
     * @param list<ResourceField> $fields
     */
    private static function formatters(array $fields, bool $exact): string
    {
        $kinds = array_map(static fn (ResourceField $field): string => $field->kind, $fields);
        $out = '';
        if (\in_array('int', $kinds, true) || \in_array('float', $kinds, true)) {
            $out .= "\n/** A number as the person reads it, or a dash for none. */\n"
                . "function count(value: number | null): string {\n"
                . "  return value === null ? '—' : value.toLocaleString('en-US');\n"
                . "}\n";
        }
        if (\in_array('bool', $kinds, true)) {
            $out .= "\n/** Yes or no, or a dash for neither. */\n"
                . "function yesNo(value: boolean | null): string {\n"
                . "  return value === null ? '—' : value ? 'Yes' : 'No';\n"
                . "}\n";
        }
        if (\in_array('timestamp', $kinds, true)) {
            $out .= "\n/** A moment, " . ($exact ? 'to the minute' : 'as how long ago') . ", or a dash for none. */\n"
                . "function Moment({ date }: { date: string | null }) {\n"
                . '  return date === null ? \'—\' : <RelativeTime date={date}' . ($exact ? ' format="datetime"' : '') . " />;\n"
                . "}\n";
        }

        return $out;
    }

    /**
     * An element as prettier writes it: on one line where it fits the
     * print width, its props one a line where it does not; with $children,
     * those on lines of their own unless the whole element fits on one.
     *
     * @param list<string> $props
     * @param string|null $children text or one expression, or lines already indented
     */
    private static function tag(int $indent, string $name, array $props, ?string $children = null): string
    {
        $pad = str_repeat(' ', $indent);
        $open = $props === [] ? $name : $name . ' ' . implode(' ', $props);
        $multiline = $children !== null && str_contains($children, "\n");
        if ($children === null) {
            $line = "{$pad}<{$open} />";
            if (mb_strlen($line) <= 110) {
                return $line;
            }

            return "{$pad}<{$name}\n" . implode('', array_map(static fn (string $prop): string => "{$pad}  {$prop}\n", $props)) . "{$pad}/>";
        }
        $tagLine = "{$pad}<{$open}>";
        $openLines = mb_strlen($tagLine) <= 110
            ? $tagLine
            : "{$pad}<{$name}\n" . implode('', array_map(static fn (string $prop): string => "{$pad}  {$prop}\n", $props)) . "{$pad}>";
        if (!$multiline) {
            $line = "{$pad}<{$open}>{$children}</{$name}>";
            if (mb_strlen($line) <= 110) {
                return $line;
            }
            $children = "{$pad}  " . ltrim($children);
        } elseif (!str_starts_with($children, ' ')) {
            $children = "{$pad}  " . $children;
        }

        return "{$openLines}\n{$children}\n{$pad}</{$name}>";
    }

    /** A view's signature, its props broken onto their own line past the print width. */
    private static function signature(string $props, string $view): string
    {
        $line = "export default function Page({ {$props} }: PageProps<'{$view}'>) {";

        return (mb_strlen($line) <= 110 ? $line : "export default function Page({\n  {$props},\n}: PageProps<'{$view}'>) {") . "\n";
    }

    /** One description of a show view's list, broken as prettier breaks an object past the print width. */
    private static function item(string $term, string $value): string
    {
        $item = "          { term: '{$term}', value: {$value} },";
        if (mb_strlen($item) <= 110) {
            return $item;
        }
        $valueLine = "            value: {$value},";

        return "          {\n            term: '{$term}',\n"
            . (mb_strlen($valueLine) > 110 ? "            value:\n              {$value},\n" : "{$valueLine}\n")
            . '          },';
    }

    /** Children between an opening and a closing tag, on one line where they fit. */
    private static function wrapped(int $indent, string $open, string $children, string $close): string
    {
        $pad = str_repeat(' ', $indent);
        $line = "{$pad}{$open}{$children}{$close}";

        return mb_strlen($line) <= 110 ? $line : "{$pad}{$open}\n{$pad}  {$children}\n{$pad}{$close}";
    }

    /** `{condition ? <element> : null}` as prettier writes it, the element in parentheses where it does not fit. */
    private static function conditional(int $indent, string $condition, string $element): string
    {
        $pad = str_repeat(' ', $indent);
        $line = "{$pad}{{$condition} ? " . ltrim($element) . ' : null}';
        if (mb_strlen($line) <= 110 && !str_contains($element, "\n")) {
            return $line;
        }

        return "{$pad}{{$condition} ? (\n{$element}\n{$pad}) : null}";
    }
}
