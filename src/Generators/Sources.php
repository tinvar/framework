<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Table;
use Tinvar\Data\Data;
use Tinvar\Orm\Factory;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;

/**
 * The text of each file a generator writes, in the shape of the spec's own
 * examples and the shop's code, formatted as pint and prettier leave it.
 *
 * Every name reaching these has been checked by Names, so none can carry a
 * quote, a newline or a `*\/` out of the string or comment it is written in.
 *
 * Each entry point declares a check that fails closed. One that takes a
 * model names it -- Authorize('view', 'rack') -- as the build rules demand,
 * and the model's policy decides. One that takes none names its own class:
 * no policy is registered for a class that did not exist a moment ago, and
 * PolicyGate refuses a check it has no policy for, so the page, query or
 * action answers nobody until its author says who may. The alternative the
 * build rules also accept, #[NoAuthorization(reason: ...)], would answer
 * everyone, and nothing reads the reason: a placeholder there would ship.
 */
final class Sources
{
    private const string HEADER = "<?php\n\ndeclare(strict_types=1);\n\n";

    /**
     * A #[Page] class with a typed loader.
     *
     * @param array<string, class-string<Model>|null> $parameters route parameter => the model it names, if any
     */
    public static function page(string $class, string $data, string $path, string $view, ?string $name, array $parameters): string
    {
        $uses = new Uses($class);
        $short = Names::short($class);
        $models = array_filter($parameters);

        $arguments = [];
        foreach ($parameters as $parameter => $model) {
            $arguments[] = ($model === null ? 'string' : $uses->name($model)) . " \${$parameter}";
        }

        $checks = $models === []
            ? [\sprintf("%s('view', %s::class)", $uses->name(Authorize::class), $short)]
            : array_map(
                static fn (string $parameter): string => \sprintf("%s('view', '%s')", $uses->name(Authorize::class), $parameter),
                array_keys($models),
            );

        $attribute = \sprintf(
            "#[%s('%s', view: '%s'%s), %s]",
            $uses->name(Page::class),
            $path,
            $view,
            $name === null ? '' : ", name: '{$name}'",
            implode(', ', $checks),
        );

        $why = $models === []
            ? self::wrap("It takes no model, so its check names this class: until a policy with a view() ability is registered for {$short}, every request to it is refused. Register one, or name the class whose policy decides instead, as in Authorize('viewAny', Rack::class).")
            : self::wrap(self::modelsSentence($models) . ', so the binder resolves ' . (\count($models) === 1 ? 'it' : 'each') . ' -- a missing id is a 404 -- and ' . (\count($models) === 1 ? 'its' : 'each one\'s') . ' policy decides, through view(), before this loader runs.');

        $dataName = $uses->name($data);

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . " * The page at {$path}, drawn by {$view}.tsx.\n"
            . " *\n"
            . $why
            . " */\n"
            . "{$attribute}\n"
            . "final class {$short}\n"
            . "{\n"
            . '    public function __invoke(' . implode(', ', $arguments) . "): {$dataName}\n"
            . "    {\n"
            . "        return new {$dataName}();\n"
            . "    }\n"
            . "}\n";
    }

    /** A Data class with no fields yet, for what $for hands the view. */
    public static function data(string $class, string $for): string
    {
        $uses = new Uses($class);
        $base = $uses->name(Data::class);

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . self::wrap("What {$for} hands the view. Each field is a promoted constructor parameter, typed, and `tinvar codegen` makes the TypeScript the view reads from them. A model never crosses: copy what the view needs out of one, field by field.")
            . " */\n"
            . "final readonly class " . Names::short($class) . " extends {$base} {}\n";
    }

    /**
     * The TSX view of a page, typed by the PageProps codegen writes for it.
     * It draws nothing: what the page shows is its author's to decide.
     */
    public static function view(string $view, string $import, string $page, string $path, bool $hasParameters): string
    {
        $props = $hasParameters ? ['data', 'params'] : ['data'];
        $signature = 'export default function Page({ ' . implode(', ', $props) . " }: PageProps<'{$view}'>) {";

        // As prettier breaks it past its print width (110, .prettierrc.json).
        if (\strlen($signature) > 110) {
            $signature = "export default function Page({\n" . implode('', array_map(static fn (string $p): string => "  {$p},\n", $props)) . "}: PageProps<'{$view}'>) {";
        }

        $about = $hasParameters
            ? "The page at {$path}. Its `data` is what {$page} returns, and its `params` the route's, both typed by `tinvar codegen` once the page is registered."
            : "The page at {$path}. Its `data` is what {$page} returns, typed by `tinvar codegen` once the page is registered.";

        return "import type { PageProps } from '{$import}';\n\n"
            . "/**\n"
            . self::wrap($about, 80)
            . " */\n"
            . "{$signature}\n"
            . "  return null;\n"
            . "}\n";
    }

    /**
     * A #[Query] class.
     *
     * @param array{string, class-string<Model>}|null $model the parameter and the model it takes
     */
    public static function query(string $class, string $data, string $name, ?array $model): string
    {
        $uses = new Uses($class);
        $short = Names::short($class);
        $dataName = $uses->name($data);
        $query = $uses->name(Query::class);

        if ($model === null) {
            $check = \sprintf("%s('view', %s::class)", $uses->name(Authorize::class), $short);
            $argument = '';
            $why = "It takes no model, so its check names this class: until a policy with a view() ability is registered for {$short}, every call to it is refused. Register one, or name the class whose policy decides instead, as in Authorize('viewAny', Rack::class).";
        } else {
            [$parameter, $modelClass] = $model;
            $check = \sprintf("%s('view', '%s')", $uses->name(Authorize::class), $parameter);
            $argument = $uses->name($modelClass) . " \${$parameter}";
            $why = "\${$parameter} is resolved and scoped, and its policy decides through view(), before this body runs: on a render and over /_query alike.";
        }

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . self::wrap("{$name}: what a server component awaits through its generated `@queries` function, answered by this PHP request. A route may call it from the renderer only if its views do: the build reads their calls into the route's allowlist.")
            . " *\n"
            . self::wrap($why)
            . " */\n"
            . "#[{$query}, {$check}]\n"
            . "final class {$short}\n"
            . "{\n"
            . "    public function __invoke({$argument}): {$dataName}\n"
            . "    {\n"
            . "        return new {$dataName}();\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * A class holding one #[Action] method.
     *
     * @param array{string, class-string<Model>}|null $model the parameter and the model it takes
     */
    public static function action(string $class, string $method, ?array $model, ?string $request): string
    {
        $uses = new Uses($class);
        $short = Names::short($class);
        $action = $uses->name(Action::class);
        $result = $uses->name(ActionResult::class);
        $arguments = [];

        if ($model === null) {
            $check = \sprintf("%s('%s', %s::class)", $uses->name(Authorize::class), $method, $short);
            $why = "It takes no model, so its check names this class: until a policy with a {$method}() ability is registered for {$short}, every call to it is refused. Register one, or name the model it acts on instead.";
        } else {
            [$parameter, $modelClass] = $model;
            $check = \sprintf("%s('update', '%s')", $uses->name(Authorize::class), $parameter);
            $arguments[] = $uses->name($modelClass) . " \${$parameter}";
            $why = $request === null
                ? "\${$parameter} is resolved and scoped, and its policy decides through update(), before this body runs."
                : "\${$parameter} is resolved and scoped, its policy decides through update(), and the request is validated, before this body runs.";
        }
        if ($request !== null) {
            $arguments[] = $uses->name($request) . ' $request';
        }

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "final class {$short}\n"
            . "{\n"
            . "    /**\n"
            . self::wrap("Called from the browser through the stub `tinvar codegen` writes to `@actions/{$short}`, with the session's CSRF token, through the app's middleware.", 70, '     * ')
            . "     *\n"
            . self::wrap($why, 70, '     * ')
            . "     */\n"
            . "    #[{$action}, {$check}]\n"
            . "    public function {$method}(" . implode(', ', $arguments) . "): {$result}\n"
            . "    {\n"
            . "        return {$result}::ok();\n"
            . "    }\n"
            . "}\n";
    }

    /** A form request with no fields yet. */
    public static function request(string $class, string $action): string
    {
        $uses = new Uses($class);
        $base = $uses->name(FormRequest::class);

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . self::wrap("What {$action} is posted. Each field is a constructor parameter: its type says what the value may be, and rule attributes such as #[Max(255)] what the type cannot. The action's body runs only for a request that passed them.")
            . " */\n"
            . "final readonly class " . Names::short($class) . " extends {$base} {}\n";
    }

    /**
     * A model: its typed properties are the table.
     *
     * @param array<string, string> $columns property => PHP type, `?` for nullable
     */
    public static function model(string $class, string $table, array $columns): string
    {
        $uses = new Uses($class);
        $id = $uses->name(Id::class);
        $properties = ["    #[{$id}] public private(set) string \$id;"];
        foreach ($columns as $property => $type) {
            $properties[] = \sprintf(
                '    #[%s] public %s $%s%s;',
                $uses->name(Column::class),
                $type,
                $property,
                str_starts_with($type, '?') ? ' = null' : '',
            );
        }
        $attribute = \sprintf("#[%s('%s')]", $uses->name(Table::class), $table);
        $base = $uses->name(Model::class);

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . self::wrap("A row of the {$table} table. Its typed properties are the table -- type, nullability and default from PHP, #[Column] for what PHP cannot say -- and `tinvar make:migration` writes the migration that makes it. The id is a UUIDv7 the ORM sets on insert, read-only outside it.")
            . " */\n"
            . "{$attribute}\n"
            . 'final class ' . Names::short($class) . " extends {$base}\n"
            . "{\n"
            . implode("\n", $properties) . "\n"
            . "}\n";
    }

    /**
     * A typed factory for a model.
     *
     * @param array<string, string> $columns property => PHP type, `?` for nullable
     */
    public static function factory(string $class, string $model, array $columns): string
    {
        $uses = new Uses($class);
        $modelName = $uses->name($model);
        $base = $uses->name(Factory::class);
        $variable = lcfirst(Names::short($model));
        $variable = $variable === 'n' ? 'model' : $variable;

        $assignments = [];
        foreach ($columns as $property => $type) {
            $value = match ($type) {
                'string' => "\"{$property} {\$n}\"",
                'int' => '$n',
                'float' => '(float) $n',
                'bool' => 'false',
                default => null,   // nullable: left at its default, null
            };
            if ($value !== null) {
                $assignments[] = "        \${$variable}->{$property} = {$value};";
            }
        }
        $counted = array_filter($assignments, static fn (string $line): bool => str_contains($line, '$n'));

        return self::HEADER
            . 'namespace ' . self::namespaceOf($class) . ";\n\n"
            . $uses->block()
            . "/**\n"
            . self::wrap('Typed: definition() returns ' . self::article($modelName) . " {$modelName} with its fields set by name, so a renamed field is a type error rather than a wrong string key.")
            . " *\n"
            . " * @extends {$base}<{$modelName}>\n"
            . " */\n"
            . 'final class ' . Names::short($class) . " extends {$base}\n"
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
    }

    /** @param non-empty-array<string, class-string<Model>> $models */
    private static function modelsSentence(array $models): string
    {
        $parts = [];
        foreach ($models as $parameter => $model) {
            $parts[] = "\${$parameter} is " . self::article(Str::shortName($model)) . ' ' . Str::shortName($model);
        }

        return \count($parts) === 1 ? $parts[0] : implode(', ', \array_slice($parts, 0, -1)) . ' and ' . end($parts);
    }

    /** `a` or `an`, by the first letter: an Order, a Rack, a Unit; a rule, not a dictionary. */
    private static function article(string $noun): string
    {
        return preg_match('/^[AEIO]/i', $noun) === 1 ? 'an' : 'a';
    }

    /** Prose as docblock lines, wrapped at $width. */
    private static function wrap(string $text, int $width = 74, string $prefix = ' * '): string
    {
        return implode('', array_map(
            static fn (string $line): string => rtrim($prefix . $line) . "\n",
            explode("\n", wordwrap($text, $width, "\n", false)),
        ));
    }

    private static function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\'));
    }
}
