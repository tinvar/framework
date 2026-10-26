<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Orm\Model;
use Tinvar\Support\Str;

/**
 * The generators: "Generators in the Artisan style: make:page, make:query,
 * make:action, make:model, make:migration" (tinvar-spec.md, "Dev
 * experience"). make:migration is the ORM's (MigrateCommand); these four
 * write the classes and the view around it.
 *
 *   tinvar make:page <Name> --path=/racks/{rack} [--view=racks/show]
 *       a #[Page] class with a typed loader, the Data class it returns,
 *       and its view, typed with PageProps<'racks/show'>
 *   tinvar make:query <Name> [--model=Rack]
 *       a #[Query] class and the Data class it returns
 *   tinvar make:action <Class> <method> [--model=Rack] [--request]
 *       a class with one #[Action] method, and its form request
 *   tinvar make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory]
 *       a #[Table] model, and its typed factory
 *
 * Every entry point is written with a check that fails closed (see
 * Sources), and passes the build rules as written: a route parameter or
 * --model naming one of the application's models is taken as that model
 * and named by #[Authorize], and a parameter that names one by its id --
 * {rackId} -- is refused, as a raw id would reach the loader unscoped.
 *
 * Nothing is ever replaced: if any file a command would write is there, it
 * writes none of them. Where each goes is the application's own psr-4
 * autoloading (AppLayout), and what a developer types is held to a name or
 * a route (Names) before it is part of any path.
 */
final class MakeCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status for a run refused before anything was written. */
    public const int FAILED = 1;

    public const array COMMANDS = ['make:page', 'make:query', 'make:action', 'make:model'];

    private const array USAGES = [
        'make:page' => 'tinvar make:page <Name> --path=/racks/{rack} [--view=racks/show]',
        'make:query' => 'tinvar make:query <Name> [--model=Rack]',
        'make:action' => 'tinvar make:action <Class> <method> [--model=Rack] [--request]',
        'make:model' => 'tinvar make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory]',
    ];

    /** What --columns may type a column as: what the ORM casts and make:migration maps. */
    private const array COLUMN_TYPES = ['string', 'int', 'float', 'bool'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param list<class-string<Model>> $models the application's models: what a route
     *        parameter or --model is matched against
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     */
    public function __construct(
        private readonly AppLayout $layout,
        private readonly array $models = [],
        ?\Closure $out = null,
        ?\Closure $err = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments what follows the command */
    public function run(string $command, array $arguments): int
    {
        try {
            $usage = self::USAGES[$command] ?? throw new \InvalidArgumentException("Unknown command '{$command}'.");
            [$positional, $options] = self::parse($arguments, $usage);

            [$files, $next] = match ($command) {
                'make:page' => $this->page($positional, $options, $usage),
                'make:query' => $this->query($positional, $options, $usage),
                'make:action' => $this->action($positional, $options, $usage),
                default => $this->model($positional, $options, $usage),
            };

            foreach ($files->write($this->layout->relative(...)) as $path) {
                ($this->out)('wrote ' . $this->layout->relative($path));
            }
            foreach ($next as $line) {
                ($this->out)($line);
            }

            return 0;
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (GenerationRefused $e) {
            ($this->err)($e->getMessage());

            return self::FAILED;
        }
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>}
     */
    private function page(array $positional, array $options, string $usage): array
    {
        self::only($options, ['path', 'view'], $usage);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'page name', 'Racks/Show');
        $path = \is_string($options['path'] ?? null) ? $options['path'] : throw new \InvalidArgumentException("make:page needs --path. Usage: {$usage}");

        $parameters = [];
        foreach (Names::routeParameters($path) as $parameter) {
            $parameters[$parameter] = $this->modelNamedBy($parameter, $path);
        }

        $view = Names::view(match (true) {
            \is_string($options['view'] ?? null) => $options['view'],
            isset($options['view']) => throw new \InvalidArgumentException("--view takes a view, as in --view=racks/show. Usage: {$usage}"),
            default => implode('/', array_map(Names::kebab(...), $segments)),
        });

        // racks/show is racks.show, as the spec's route('racks.show'); a view
        // whose segments are not identifiers that way gets no name.
        $routeName = implode('.', array_map(Names::camel(...), explode('/', $view)));
        $routeName = Str::isDottedIdentifier($routeName) ? $routeName : null;

        $class = $this->class('Pages', $segments);
        $last = end($segments);
        $data = $this->class('Data', [implode('', $segments) . (str_ends_with($last, 'Page') ? 'Data' : 'PageData')]);
        $viewFile = $this->layout->viewPath($view);

        // A view of another kind under the same name would be imported in
        // its place, or beside it, by whatever resolves `pages/racks/show`.
        FileSet::assertAbsent(
            array_map(fn (string $extension): string => $this->layout->viewPath($view, $extension), ['ts', 'jsx', 'js']),
            $this->layout->relative(...),
        );

        $files = (new FileSet())
            ->add($this->layout->pathOf($class), Sources::page($class, $data, $path, $view, $routeName, $parameters))
            ->add($this->layout->pathOf($data), Sources::data($data, $class))
            ->add($viewFile, Sources::view($view, $this->layout->typesImport($view), $class, $path, $parameters !== []));

        $next = ["Next: register {$class} with the application's routes, add the view to its renderer's route table, and run `tinvar codegen` for PageProps<'{$view}'>."];
        if (array_filter($parameters) === []) {
            $next[] = "Until a policy with a view() ability is registered for {$class}, every request to the page is refused.";
        }

        return [$files, $next];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>}
     */
    private function query(array $positional, array $options, string $usage): array
    {
        self::only($options, ['model'], $usage);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'query name', 'RackStats, or Racks/Stats');
        $model = $this->modelOption($options, $usage);

        $class = $this->class('Queries', $segments);
        $data = $this->class('Data', [implode('', $segments) . 'Data']);
        $wire = Names::queryName($class);

        $files = (new FileSet())
            ->add($this->layout->pathOf($class), Sources::query($class, $data, $wire, $model))
            ->add($this->layout->pathOf($data), Sources::data($data, $class));

        $next = ["Next: register {$class} with the application's queries, list '{$wire}' among the queries of each route whose components call it, and run `tinvar codegen`."];
        if ($model === null) {
            $next[] = "Until a policy with a view() ability is registered for {$class}, every call to the query is refused.";
        }

        return [$files, $next];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>}
     */
    private function action(array $positional, array $options, string $usage): array
    {
        self::only($options, ['model', 'request'], $usage);
        [$name, $method] = self::positional($positional, 2, $usage);
        $segments = Names::classPath($name, 'class name', 'RackActions');
        $method = Names::method($method);
        $model = $this->modelOption($options, $usage);
        if (isset($options['request']) && $options['request'] !== true) {
            throw new \InvalidArgumentException("--request takes no value: the request is named after the method. Usage: {$usage}");
        }

        $class = $this->class('Actions', $segments);
        $request = isset($options['request']) ? $this->class('Requests', [ucfirst($method) . 'Request']) : null;

        $files = (new FileSet())->add($this->layout->pathOf($class), Sources::action($class, $method, $model, $request));
        if ($request !== null) {
            $files->add($this->layout->pathOf($request), Sources::request($request, Names::short($class) . "::{$method}"));
        }

        $next = ["Next: register {$class} with the application's actions, and run `tinvar codegen` for `@actions/" . Names::short($class) . '`.'];
        if ($model === null) {
            $next[] = "Until a policy with a {$method}() ability is registered for {$class}, every call to the action is refused.";
        }

        return [$files, $next];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>}
     */
    private function model(array $positional, array $options, string $usage): array
    {
        self::only($options, ['table', 'columns', 'factory'], $usage);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'model name', 'Rack');
        foreach (['table', 'columns'] as $option) {
            if (($options[$option] ?? '') === true) {
                throw new \InvalidArgumentException("--{$option} takes a value. Usage: {$usage}");
            }
        }
        if (isset($options['factory']) && $options['factory'] !== true) {
            throw new \InvalidArgumentException("--factory takes no value. Usage: {$usage}");
        }

        $short = end($segments);
        $table = Names::table(\is_string($options['table'] ?? null) ? $options['table'] : Names::plural(Str::snake($short)));
        $columns = self::columns(\is_string($options['columns'] ?? null) ? $options['columns'] : '');

        $class = $this->class('Models', $segments);
        $files = (new FileSet())->add($this->layout->pathOf($class), Sources::model($class, $table, $columns));
        if (isset($options['factory'])) {
            $factory = $this->class('Database\\Factories', [...\array_slice($segments, 0, -1), "{$short}Factory"]);
            $files->add($this->layout->pathOf($factory), Sources::factory($factory, $class, $columns));
        }

        return [$files, ["Next: register {$class} with the application's models, and run `tinvar make:migration create_" . strtolower($table) . '` for its table.']];
    }

    /**
     * The model a route parameter names, or null for a plain string.
     *
     * One that names a model by its id -- {rackId}, {rack_id} -- is
     * refused: the loader would be handed the raw value, to look the row up
     * itself, unscoped and unchecked.
     *
     * @return class-string<Model>|null
     */
    private function modelNamedBy(string $parameter, string $path): ?string
    {
        $model = $this->findModel($parameter);
        if ($model !== null) {
            return $model;
        }

        $stripped = (string) preg_replace('/(_ids?|ids?)$/i', '', $parameter);
        $byId = $stripped !== $parameter && $stripped !== '' ? $this->findModel($stripped) : null;
        if ($byId !== null) {
            throw new \InvalidArgumentException(
                "{{$parameter}} in {$path} names " . Str::shortName($byId) . " by its id. Name it {{$stripped}}, and the page "
                . 'takes the ' . Str::shortName($byId) . ' itself -- resolved, scoped to the tenant and authorized -- rather than a raw id.',
            );
        }

        return null;
    }

    /**
     * The model --model names, by its short name or in full, as the
     * parameter it is taken by.
     *
     * @param array<string, string|true> $options
     * @return array{string, class-string<Model>}|null
     */
    private function modelOption(array $options, string $usage): ?array
    {
        if (!isset($options['model'])) {
            return null;
        }

        $named = $options['model'];
        if ($named === true || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $named) !== 1) {
            throw new \InvalidArgumentException("--model names one of the application's models, as in --model=Rack. Usage: {$usage}");
        }

        $model = $this->findModel($named) ?? throw new \InvalidArgumentException(
            "'{$named}' is not one of the application's models"
            . ($this->models === [] ? '' : ' (' . implode(', ', array_map(Str::shortName(...), $this->models)) . ')') . '.',
        );

        return [lcfirst(Str::shortName($model)), $model];
    }

    /**
     * The application's model a name means: its full name, or its short
     * name in any case -- `rack` for Rack, as a route parameter names it.
     * Two models of one short name are a name that means neither.
     *
     * @return class-string<Model>|null
     */
    private function findModel(string $name): ?string
    {
        $name = ltrim($name, '\\');
        $candidates = [...$this->models];

        // One the developer has just made, under the application's Models
        // namespace, and not yet registered.
        $conventional = "{$this->layout->namespace}\\Models\\" . ucfirst($name);
        if (!str_contains($name, '\\') && Str::isIdentifier($name) && class_exists($conventional) && is_subclass_of($conventional, Model::class)) {
            $candidates[] = $conventional;
        }

        $matches = array_values(array_unique(array_filter(
            $candidates,
            static fn (string $model): bool => strcasecmp($model, $name) === 0 || strcasecmp(Str::shortName($model), $name) === 0,
        )));

        if (\count($matches) > 1) {
            throw new \InvalidArgumentException("'{$name}' could be any of " . implode(', ', $matches) . '; name the model in full.');
        }

        return $matches[0] ?? null;
    }

    /**
     * A class under the application's namespace.
     *
     * @param list<string> $segments
     */
    private function class(string $area, array $segments): string
    {
        return "{$this->layout->namespace}\\{$area}\\" . implode('\\', $segments);
    }

    /**
     * --columns as property => PHP type: `name:string,heightU:int,notes:?string`.
     *
     * @return array<string, string>
     */
    private static function columns(string $spec): array
    {
        if ($spec === '') {
            return [];
        }

        $columns = [];
        foreach (explode(',', $spec) as $column) {
            [$property, $type] = array_pad(explode(':', trim($column), 2), 2, '');
            $property = Names::property($property);
            if ($property === 'id' || isset($columns[$property])) {
                throw new \InvalidArgumentException("--columns names {$property} " . ($property === 'id' ? 'which is the id #[Id] declares.' : 'twice.'));
            }
            // Redeclared on the model, a property the ORM keeps there is a
            // fatal error the first time the class loads.
            if (property_exists(Model::class, $property)) {
                throw new \InvalidArgumentException("--columns names {$property}, which every model already has: the ORM keeps its own state there.");
            }
            if (!\in_array(str_starts_with($type, '?') ? substr($type, 1) : $type, self::COLUMN_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "'{$type}' is not a column type --columns takes: " . implode(', ', self::COLUMN_TYPES) . ", or one of them after '?' for a "
                    . 'column that may be null, as in notes:?string.',
                );
            }
            $columns[$property] = $type;
        }

        return $columns;
    }

    /**
     * Positional arguments, and `--name=value` options and `--name` flags.
     *
     * @param list<string> $arguments
     * @return array{list<string>, array<string, string|true>}
     */
    private static function parse(array $arguments, string $usage): array
    {
        $positional = [];
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '-')) {
                $positional[] = $argument;
                continue;
            }
            if (preg_match('/^--([a-z]+)(?:=(.*))?\z/s', $argument, $m) !== 1 || isset($options[$m[1]])) {
                throw new \InvalidArgumentException("Unknown or repeated argument '{$argument}'. Usage: {$usage}");
            }
            $options[$m[1]] = $m[2] ?? true;
        }

        return [$positional, $options];
    }

    /**
     * @param array<string, string|true> $options
     * @param list<string> $allowed
     */
    private static function only(array $options, array $allowed, string $usage): void
    {
        foreach (array_keys($options) as $option) {
            if (!\in_array($option, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown argument '--{$option}'. Usage: {$usage}");
            }
        }
    }

    /**
     * Exactly $count positional arguments.
     *
     * @param list<string> $positional
     * @return non-empty-list<string>
     */
    private static function positional(array $positional, int $count, string $usage): array
    {
        if (\count($positional) !== $count || $positional === []) {
            throw new \InvalidArgumentException("Usage: {$usage}");
        }

        return $positional;
    }
}
