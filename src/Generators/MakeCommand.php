<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Console\Prompts;
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
 *   tinvar make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory] [--migration]
 *       a #[Table] model, its typed factory, and the migration make:migration
 *       writes for its table
 *   tinvar make:resource <Name> --fields="title:string,status:enum(Draft,Published),customer:belongsTo" [--path=/orders]
 *       all of it at once, for one model: the model, its enums, factory and
 *       migration; its Data, list and detail queries, form request and
 *       create, update and delete actions; its index, show, create and edit
 *       pages, their views and client components; its policy; its tests
 *
 * Where a person is there to ask (Prompts::canAsk()), what a command needs
 * and was not given is asked: the name, the route, the method. Run bare --
 * `tinvar make:model` -- it asks the rest too: the table, the columns,
 * whether to write a factory and a migration; the model a query or action
 * takes. Each answer is held to what the argument would be. With nobody
 * there, a missing argument is the usage error it always was, so a script
 * runs exactly as it did.
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
 * a route (Names) before it is part of any path. In an application laid
 * out as the skeleton is (ADR 0148), each kind has its folder -- Http\Pages,
 * Http\Data, Database\Models and the rest -- and a name's first segments
 * are its domain: `make:page Orders/OrderDetail` writes
 * Http\Pages\Orders\OrderDetail, and its Data in Http\Data\Orders.
 */
final class MakeCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status for a run refused before anything was written. */
    public const int FAILED = 1;

    public const array COMMANDS = ['make:page', 'make:query', 'make:action', 'make:model', 'make:resource'];

    private const array USAGES = [
        'make:page' => 'tinvar make:page <Name> --path=/racks/{rack} [--view=racks/show]',
        'make:query' => 'tinvar make:query <Name> [--model=Rack]',
        'make:action' => 'tinvar make:action <Class> <method> [--model=Rack] [--request]',
        'make:model' => 'tinvar make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory] [--migration]',
        'make:resource' => 'tinvar make:resource <Name> --fields="title:string,status:enum(Draft,Published),price:int,customer:belongsTo" [--path=/orders] [--panel=admin] [--no-tenancy]',
    ];

    /**
     * What a resource's name may not make its route parameter: a variable
     * the classes it writes already use for something else.
     */
    private const array TAKEN_VARIABLES = ['request', 'orm', 'page', 'found', 'list', 'show', 'options', 'n', 'field', 'value', 'moment', 'user', 'chosen', 'rows', 'case', 'kernel', 'row', 'next'];

    /** What --columns may type a column as: what the ORM casts and make:migration maps. */
    private const array COLUMN_TYPES = ['string', 'int', 'float', 'bool'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /** The migration make:model --migration asks for once the model is written. */
    private ?string $migration = null;

    /**
     * @param list<class-string<Model>> $models the application's models: what a route
     *        parameter or --model is matched against
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param Registrations $registrations where a page's view is registered: the
     *        route table, or, with none, nowhere -- the
     *        developer is told where to
     * @param (\Closure(string): int)|null $migrate runs make:migration with the name it is
     *        given, in a process that loads the model just registered; with none,
     *        make:model --migration says to
     * @param string|null $migrations the application's migrations directory, which
     *        make:resource writes its table's migration into; with none, it says to make one
     * @param (\Closure(ResourcePlan): string)|null $resourceTest the text of a resource's test,
     *        tinvar/testing's, handed in where it is installed; with none, no test is written
     * @param bool $tenancy whether the app uses tenancy -- tinvar/tenancy installed and its Orm
     *        half registered in the Kernel (AppConsole) -- so make:resource writes a tenant's
     *        model and a policy that lets in only a member of the tenant (ADR 0151)
     */
    public function __construct(
        private readonly AppLayout $layout,
        private readonly array $models = [],
        ?\Closure $out = null,
        ?\Closure $err = null,
        private readonly Registrations $registrations = new Registrations(),
        private readonly ?\Closure $migrate = null,
        private readonly ?string $migrations = null,
        private readonly ?\Closure $resourceTest = null,
        private readonly bool $tenancy = false,
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
            if (Prompts::canAsk()) {
                [$positional, $options] = $this->ask($command, $positional, $options);
            }
            $this->migration = null;

            [$files, $next, $registered] = match ($command) {
                'make:page' => $this->page($positional, $options, $usage),
                'make:query' => $this->query($positional, $options, $usage),
                'make:action' => $this->action($positional, $options, $usage),
                'make:resource' => $this->resource($positional, $options, $usage),
                default => $this->model($positional, $options, $usage),
            };

            foreach ($files->write($this->layout->relative(...)) as $path) {
                ($this->out)('wrote ' . $this->layout->relative($path));
            }
            // Discovered once written (ADR 0146); a page's view registered in
            // the route table, or what could not be made said: a resource's
            // four pages' views.
            $done = [];
            $left = [];
            foreach ($registered as $registration) {
                [$madeHere, $leftHere] = $this->registrations->register(...$registration);
                $done = [...$done, ...$madeHere];
                $left = [...$left, ...$leftHere];
            }
            foreach ($done as $line) {
                ($this->out)($line);
            }
            foreach ([...$left, ...$next] as $line) {
                ($this->out)($line);
            }

            return $this->migration === null || $this->migrate === null ? 0 : ($this->migrate)($this->migration);
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
     * @return array{FileSet, list<string>, list<array{string, string, AppLayout, string|null}>}
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
        $data = $this->class('Data', $this->dataPath($segments, str_ends_with($last, 'Page') ? 'Data' : 'PageData'));
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

        $next = [$this->registrations->none()
            ? "Next: add the view to its renderer's route table, and run `tinvar codegen` for PageProps<'{$view}'>."
            : "Next: run `tinvar codegen` for PageProps<'{$view}'>."];
        if (array_filter($parameters) === []) {
            $next[] = "Until a policy with a view() ability is registered for {$class}, every request to the page is refused.";
        }

        return [$files, $next, [['page', $class, $this->layout, $view]]];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>, list<array{0: string, 1: string, 2: AppLayout, 3?: string|null}>}
     */
    private function query(array $positional, array $options, string $usage): array
    {
        self::only($options, ['model'], $usage);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'query name', 'RackStats, or Racks/Stats');
        $model = $this->modelOption($options, $usage);

        $class = $this->class('Queries', $segments);
        $data = $this->class('Data', $this->dataPath($segments, 'Data'));
        $wire = Names::queryName($class);

        $files = (new FileSet())
            ->add($this->layout->pathOf($class), Sources::query($class, $data, $wire, $model))
            ->add($this->layout->pathOf($data), Sources::data($data, $class));

        // No list to keep: the build reads each view's calls into its
        // route's allowlist, so the query is allowed exactly where it is
        // called, and a hand-kept list could only drift from that.
        $next = ['Next: run'
            . " `tinvar codegen` for its `@queries` function. The build allows '{$wire}' on each route whose views call it."];
        if ($model === null) {
            $next[] = "Until a policy with a view() ability is registered for {$class}, every call to the query is refused.";
        }

        return [$files, $next, [['query', $class, $this->layout]]];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>, list<array{0: string, 1: string, 2: AppLayout, 3?: string|null}>}
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
        $request = isset($options['request']) ? $this->class('Requests', $this->besideDomain($segments, ucfirst($method) . 'Request')) : null;

        $files = (new FileSet())->add($this->layout->pathOf($class), Sources::action($class, $method, $model, $request));
        if ($request !== null) {
            $files->add($this->layout->pathOf($request), Sources::request($request, Names::short($class) . "::{$method}"));
        }

        $next = ['Next: run'
            . ' `tinvar codegen` for `@actions/' . Names::short($class) . '`.'];
        if ($model === null) {
            $next[] = "Until a policy with a {$method}() ability is registered for {$class}, every call to the action is refused.";
        }

        return [$files, $next, [['action', $class, $this->layout]]];
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>, list<array{0: string, 1: string, 2: AppLayout, 3?: string|null}>}
     */
    private function model(array $positional, array $options, string $usage): array
    {
        self::only($options, ['table', 'columns', 'factory', 'migration'], $usage);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'model name', 'Rack');
        foreach (['table', 'columns'] as $option) {
            if (($options[$option] ?? '') === true) {
                throw new \InvalidArgumentException("--{$option} takes a value. Usage: {$usage}");
            }
        }
        foreach (['factory', 'migration'] as $flag) {
            if (isset($options[$flag]) && $options[$flag] !== true) {
                throw new \InvalidArgumentException("--{$flag} takes no value. Usage: {$usage}");
            }
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

        $migration = 'create_' . strtolower($table);
        // Made here only where a process can load the model once it is
        // discovered; otherwise, as without --migration, said.
        if (isset($options['migration']) && $this->migrate !== null) {
            $this->migration = $migration;

            return [$files, [], [['model', $class, $this->layout]]];
        }

        return [$files, ['Next: run'
            . " `tinvar make:migration {$migration}` for its table."], [['model', $class, $this->layout]]];
    }

    /**
     * A resource: a model and everything that lists, shows, makes, changes
     * and deletes its rows, in one run -- the model with its enums, factory
     * and migration; its Data, list and detail queries, form request and
     * actions; its index, show, create and edit pages and their views and
     * client components; its policy; and its tests. Each in the folder its
     * kind goes in, in the resource's domain, found by discovery once it is
     * there.
     *
     * Everything is decided -- every field read, every name held to a
     * shape, every file and route checked free -- before the first file is
     * written, and FileSet writes all of them or none.
     *
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{FileSet, list<string>, list<array{string, string, AppLayout, string|null}>}
     */
    private function resource(array $positional, array $options, string $usage): array
    {
        self::only($options, ['fields', 'path', 'panel', 'no-tenancy'], $usage);
        if (isset($options['no-tenancy']) && $options['no-tenancy'] !== true) {
            throw new \InvalidArgumentException("--no-tenancy takes no value: it writes the resource as an app with no tenancy gets it. Usage: {$usage}");
        }
        // A tenant's resource where the app uses tenancy, unless told
        // otherwise: an app that registers tenancy and runs with no tenant
        // in force, as the shop does, has --no-tenancy.
        $tenanted = $this->tenancy && !isset($options['no-tenancy']);
        [$name] = self::positional($positional, 1, $usage);
        $segments = Names::classPath($name, 'resource name', 'Orders/Order');
        $short = end($segments);
        $fieldsOption = $options['fields'] ?? throw new \InvalidArgumentException(
            'make:resource needs its fields. Pass them, as in --fields="title:string,status:enum(Draft,Published),price:int,customer:belongsTo", '
            . 'or run it on a terminal to be asked for each.',
        );
        if ($fieldsOption === true) {
            throw new \InvalidArgumentException("--fields takes a value, as in --fields=\"title:string,price:int\". Usage: {$usage}");
        }
        $fields = ResourceFields::parse($fieldsOption, $this->findModel(...));

        // What the views and components are named after, and take their
        // rows as: past this, a name is a sentence.
        if (\strlen($short) > 24) {
            throw new \InvalidArgumentException("{$short} is " . \strlen($short) . " characters long; a resource's name is at most 24. Its domain says the rest: Fulfilment/Shipment.");
        }
        $parameter = lcfirst($short);
        if (\in_array($parameter, self::TAKEN_VARIABLES, true) || Names::isReserved($parameter)) {
            throw new \InvalidArgumentException("A resource named {$short} would take its rows as \${$parameter}, which the classes it writes use for something else. Name it differently.");
        }
        if ($this->findModel($short) !== null) {
            throw new GenerationRefused("The app has a model named {$short} already. Nothing was written: make:resource writes the model too. Choose another name, or write the rest with make:page, make:query and make:action.");
        }

        // The domain is every segment but the last, or -- in an application
        // split by domain -- the plural of a name given without one: Order's
        // classes go in Orders.
        $plural = Names::plural($short);
        $domain = \count($segments) > 1 ? \array_slice($segments, 0, -1) : ($this->byDomain() ? [$plural] : []);
        $table = Names::table(Names::plural(Str::snake($short)));
        foreach ($this->models as $model) {
            $declared = (new \ReflectionClass($model))->getAttributes(\Tinvar\Orm\Attributes\Table::class)[0] ?? null;
            if ($declared !== null && strcasecmp((string) ($declared->getArguments()[0] ?? $declared->getArguments()['name'] ?? ''), $table) === 0) {
                throw new GenerationRefused("{$model} is the {$table} table already. Nothing was written. Choose another name for the resource.");
            }
        }

        // orders, or catalog/products: the domain's segments, then the
        // plural, said once where the domain is the plural.
        $kebab = array_map(Names::kebab(...), $domain);
        if (end($kebab) !== Names::kebab($plural)) {
            $kebab[] = Names::kebab($plural);
        }
        $viewPrefix = Names::view(implode('/', $kebab));
        $basePath = match (true) {
            !isset($options['path']) => '/' . $viewPrefix,
            $options['path'] === true => throw new \InvalidArgumentException("--path takes a path, as in --path=/orders. Usage: {$usage}"),
            default => rtrim($options['path'], '/'),
        };
        if ($basePath === '' || Names::routeParameters($basePath) !== []) {
            throw new \InvalidArgumentException("--path is where the list is, a path with no {parameter}, as in --path=/orders: the rest go under it.");
        }
        // Everything under /_ is the framework's (ADR 0075): the routes would
        // be refused when the app boots.
        if (str_starts_with($basePath, '/_')) {
            throw new \InvalidArgumentException("--path={$basePath} is under /_, where every path is the framework's. Choose another, as in --path=/orders.");
        }
        $routePrefix = implode('.', array_map(Names::camel(...), $kebab));
        $panel = match (true) {
            !isset($options['panel']) => null,
            $options['panel'] === true => throw new \InvalidArgumentException("--panel takes a panel's name, as in --panel=admin. Usage: {$usage}"),
            default => self::panelName($options['panel']),
        };

        $enums = [];
        foreach ($fields as $field) {
            if ($field->kind === 'enum') {
                $enums[$field->name] = $this->class('Models', [...$domain, $short . ucfirst($field->name)]);
            }
        }

        // Each Data class by its own name, which codegen emits it by: two of
        // one short name would be refused there.
        $dataClass = function (string $suffix) use ($domain, $short): string {
            $path = $this->besideDomain([...$domain, $short], $short . $suffix);
            if ($this->layout->namedElsewhere($this->layout->area('Data'), $short . $suffix, implode('\\', $path))) {
                throw new GenerationRefused("A Data class named {$short}{$suffix} is in the app already: codegen names each by its short name, so two of one name are refused there. Nothing was written. Name the resource differently.");
            }

            return $this->class('Data', $path);
        };

        $choiceLabels = [];
        $relatedFactories = [];
        $rowRelations = [];
        foreach ($fields as $field) {
            if ($field->kind !== 'belongsTo') {
                continue;
            }
            $related = $field->model ?? throw new \LogicException("{$field->name} is a relation to no model.");
            // A bare {$field}Id naming a tenant's model is refused by the
            // build rules (tenant-scoped-ids-must-be-typed, ADR 0105): the
            // Data sends the related row instead, typed -- its id and what a
            // person reads, as the form's choices hold it.
            if (\Tinvar\Orm\Attributes\TenantScoped::on($related) !== null) {
                $rowRelations[] = $field->name;
            }
            if (\Tinvar\Orm\Metadata::for($related)->idProperty !== 'id') {
                throw new GenerationRefused("{$related}'s key is not \$id, which the form's choices of it are read by. Nothing was written.");
            }
            $choiceLabels[$field->name] = self::choiceLabel($related);
            $relatedFactories[$field->name] = $this->factoryOf($related);
        }

        // A tenant's resource is let in only by a membership of the tenant,
        // which the test's stand-in user, in no tenant, does not hold; and a
        // tenant's row is made only in a tenant, which the test is in none of.
        $test = $tenanted || $rowRelations !== [] ? null : $this->testClass([...$domain, "{$short}ResourceTest"], $relatedFactories);
        $components = implode('/', $kebab);

        $plan = new ResourcePlan(
            short: $short,
            plural: $plural,
            table: $table,
            fields: $fields,
            model: $this->class('Models', [...$domain, $short]),
            enums: $enums,
            factory: $this->class('Database\\Factories', [...$domain, "{$short}Factory"]),
            record: $dataClass('Data'),
            list: $dataClass('ListData'),
            form: $dataClass('FormData'),
            option: $dataClass('OptionData'),
            listQuery: $this->class('Queries', [...$domain, "List{$plural}"]),
            showQuery: $this->class('Queries', [...$domain, "Show{$short}"]),
            formOptions: $this->class('Pages', [...$domain, "{$short}FormOptions"]),
            actions: $this->class('Actions', [...$domain, "{$short}Actions"]),
            request: $this->class('Requests', $this->besideDomain([...$domain, $short], "Save{$short}Request")),
            policy: "{$this->layout->namespace}\\Policies\\" . implode('\\', $this->besideDomain([...$domain, $short], "{$short}Policy")),
            indexPage: $this->class('Pages', [...$domain, "{$short}Index"]),
            showPage: $this->class('Pages', [...$domain, "{$short}Show"]),
            createPage: $this->class('Pages', [...$domain, "{$short}Create"]),
            editPage: $this->class('Pages', [...$domain, "{$short}Edit"]),
            basePath: $basePath,
            parameter: $parameter,
            viewPrefix: $viewPrefix,
            routePrefix: $routePrefix,
            formComponent: "{$short}Form",
            deleteComponent: "{$short}DeleteForm",
            formComponentPath: $this->layout->componentPath("{$components}/{$short}Form"),
            deleteComponentPath: $this->layout->componentPath("{$components}/{$short}DeleteForm"),
            typesFromComponents: AppLayout::importBetween($this->layout->componentPath("{$components}/{$short}Form"), $this->layout->generatedTypes()),
            choiceLabels: $choiceLabels,
            relatedFactories: $relatedFactories,
            test: $test,
            testPath: $test === null ? null : $this->layout->inProject('tests/' . str_replace('\\', '/', substr($test, \strlen('Tests\\'))) . '.php'),
            appNamespace: $this->layout->namespace,
            resource: $panel === null ? null : $this->class('Resources', [...$domain, "{$short}Resource"]),
            panel: $panel,
            tenanted: $tenanted,
            rowRelations: $rowRelations,
        );

        $screens = ['index' => '', 'show' => "/{{$parameter}}", 'create' => '/new', 'edit' => "/{{$parameter}}/edit"];
        $paths = array_values(array_map(static fn (string $rest): string => $basePath . $rest, $screens));
        if ($panel !== null) {
            $paths = [...$paths, ...array_map(static fn (string $rest): string => $plan->panelPath() . $rest, ['', '/new', '/{record}', '/{record}/edit'])];
        }
        $collisions = $this->registrations->collisions($paths, array_map($plan->route(...), array_keys($screens)));
        if ($collisions !== []) {
            throw new GenerationRefused(implode('; ', $collisions) . '. Nothing was written: give the resource another --path or name.');
        }

        $migrationPath = null;
        if ($this->migrations !== null) {
            $directory = $this->layout->inProject(rtrim($this->migrations, '/') . '/x');
            $existing = glob(\dirname($directory) . "/*_create_{$table}.php") ?: [];
            if ($existing !== []) {
                throw GenerationRefused::exists(array_map($this->layout->relative(...), $existing));
            }
            // After every migration there, so it runs after the tables it
            // references: two resources made in one second keep their order.
            $at = time();
            foreach (glob(\dirname($directory) . '/*.php') ?: [] as $file) {
                $made = \DateTimeImmutable::createFromFormat('!Y_m_d_His', substr(basename($file), 0, 17), new \DateTimeZone('UTC'));
                if ($made !== false && $made->getTimestamp() >= $at) {
                    $at = $made->getTimestamp() + 1;
                }
            }
            $migrationPath = \dirname($directory) . '/' . gmdate('Y_m_d_His', $at) . "_create_{$table}.php";
        }

        $files = (new FileSet())->add($this->layout->pathOf($plan->model), ResourceSources::model($plan));
        foreach ($fields as $field) {
            if ($field->kind === 'enum') {
                $files->add($this->layout->pathOf($enums[$field->name]), ResourceSources::enum($plan, $field));
            }
        }
        $files->add($this->layout->pathOf($plan->factory), ResourceSources::factory($plan));
        if ($migrationPath !== null) {
            $files->add($migrationPath, ResourceSources::migration($plan));
        }
        $files->add($this->layout->pathOf($plan->record), ResourceSources::record($plan))
            ->add($this->layout->pathOf($plan->list), ResourceSources::listData($plan))
            ->add($this->layout->pathOf($plan->option), ResourceSources::option($plan))
            ->add($this->layout->pathOf($plan->form), ResourceSources::formData($plan))
            ->add($this->layout->pathOf($plan->listQuery), ResourceSources::listQuery($plan))
            ->add($this->layout->pathOf($plan->showQuery), ResourceSources::showQuery($plan))
            ->add($this->layout->pathOf($plan->request), ResourceSources::request($plan))
            ->add($this->layout->pathOf($plan->actions), ResourceSources::actions($plan))
            ->add($this->layout->pathOf($plan->formOptions), ResourceSources::formOptions($plan))
            ->add($this->layout->pathOf($plan->indexPage), ResourceSources::page($plan, 'index'))
            ->add($this->layout->pathOf($plan->showPage), ResourceSources::page($plan, 'show'))
            ->add($this->layout->pathOf($plan->createPage), ResourceSources::page($plan, 'create'))
            ->add($this->layout->pathOf($plan->editPage), ResourceSources::page($plan, 'edit'))
            ->add($this->layout->pathOf($plan->policy), ResourceSources::policy($plan));
        if ($plan->resource !== null) {
            $files->add($this->layout->pathOf($plan->resource), ResourceSources::adminResource($plan));
        }

        $views = [];
        foreach (array_keys($screens) as $screen) {
            $view = $plan->view($screen);
            $views[$screen] = $this->layout->viewPath($view);
            FileSet::assertAbsent(
                array_map(fn (string $extension): string => $this->layout->viewPath($view, $extension), ['ts', 'jsx', 'js']),
                $this->layout->relative(...),
            );
        }
        $files->add($views['index'], ResourceSources::indexView($plan, $this->layout->typesImport($plan->view('index'))))
            ->add($views['show'], ResourceSources::showView($plan, $this->layout->typesImport($plan->view('show')), AppLayout::importBetween($views['show'], substr($plan->deleteComponentPath, 0, -4))))
            ->add($views['create'], ResourceSources::formView($plan, 'create', $this->layout->typesImport($plan->view('create')), AppLayout::importBetween($views['create'], substr($plan->formComponentPath, 0, -4))))
            ->add($views['edit'], ResourceSources::formView($plan, 'edit', $this->layout->typesImport($plan->view('edit')), AppLayout::importBetween($views['edit'], substr($plan->formComponentPath, 0, -4))))
            ->add($plan->formComponentPath, ResourceSources::formComponent($plan, $plan->typesFromComponents))
            ->add($plan->deleteComponentPath, ResourceSources::deleteComponent($plan));
        if ($plan->testPath !== null) {
            $files->add($plan->testPath, ($this->resourceTest ?? throw new \LogicException('A test was planned with nothing to write it.'))($plan));
        }

        $next = [$this->registrations->none()
            ? "Next: add the views {$plan->view('index')}, {$plan->view('show')}, {$plan->view('create')} and {$plan->view('edit')} to the renderer's route table, and run `tinvar codegen`."
            : 'Next: run `tinvar codegen` for their PageProps, `@queries` and `@actions/' . Names::short($plan->actions) . '`.'];
        $next[] = $migrationPath === null
            ? "Then `tinvar make:migration create_{$table}` for its table, and `tinvar migrate`."
            : 'Then `tinvar migrate` for its table.';
        if ($panel !== null) {
            $next[] = "In the {$panel} panel at {$plan->panelPath()}: its three resource views, {$panel}/resources/index, {$panel}/resources/show and {$panel}/resources/form, "
                . 'must be in the route table, drawn once for every resource in the panel. If the panel is on a host of its own, add the #[Domain] its pages carry to '
                . Names::short($plan->resource ?? '') . '.';
        }
        if ($tenanted) {
            $next[] = Names::short($plan->model) . " is a tenant's (#[TenantScoped]): each tenant reads and changes only its own {$plural}. "
                . Names::short($plan->policy) . " lets in only a member of the tenant in force, holding a membership of the panel the request is in, by the app's Memberships: "
                . "put its pages in your tenants' panel with #[Panel], have them read their tenant with #[TenantFrom], and give the Kernel Memberships -- until then it refuses everyone. "
                . 'Say there what each member may do before it ships.';
            $next[] = "No test was written: a tenant's resource is let in only by a membership of a tenant, which the test's stand-in user does not hold.";
        } else {
            $next[] = Names::short($plan->policy) . " lets anyone signed in do anything with any {$parameter}, and a guest nothing: say there who may before it ships.";
        }
        if ($test === null && !$tenanted) {
            $next[] = $rowRelations !== []
                ? 'No test was written: ' . implode(' and ', $rowRelations) . ' belong' . (\count($rowRelations) === 1 ? 's' : '') . " to a tenant's model, whose rows are made only in a tenant, which the test's stand-in user is in none of."
                : $this->whyNoTest($relatedFactories);
        }

        $registered = [
            ['page', $plan->indexPage, $this->layout, $plan->view('index')],
            ['page', $plan->showPage, $this->layout, $plan->view('show')],
            ['page', $plan->createPage, $this->layout, $plan->view('create')],
            ['page', $plan->editPage, $this->layout, $plan->view('edit')],
        ];

        return [$files, $next, $registered];
    }

    /** A panel's name, as a #[Panel] names one: lower case letters, digits, '-' and '_'. */
    private static function panelName(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}\z/', $value) !== 1) {
            throw new \InvalidArgumentException("'{$value}' is not a panel's name: lower case letters, digits, '-' and '_', as in --panel=admin.");
        }

        return $value;
    }

    /**
     * The fields of a resource, asked one at a time: its name, what it is,
     * an enum's values or the model a relation belongs to, and whether it
     * may be left empty -- each held to what --fields would take, and the
     * list read again as a whole once it is done.
     */
    private function askFields(): string
    {
        $kinds = ResourceField::KINDS;
        if ($this->models === []) {
            unset($kinds['belongsTo']);
        }
        $specs = [];
        $names = [];
        while (\count($specs) < ResourceFields::MAX_FIELDS) {
            $name = Prompts::text(
                $specs === [] ? 'What is its first field called?' : 'And the next? Leave it empty when that is all of them.',
                placeholder: $specs === [] ? 'title' : '',
                required: $specs === [],
                validate: self::verdict(static function (string $name) use ($names): void {
                    if ($name === '') {
                        return;
                    }
                    Names::property($name);
                    if (\in_array($name, $names, true)) {
                        throw new \InvalidArgumentException("{$name} is a field already.");
                    }
                }),
                hint: 'A property name, in lower camel case: the id, createdAt and updatedAt are written already.',
                name: 'field',
            );
            if ($name === '') {
                break;
            }
            $kind = (string) Prompts::select("What is {$name}?", $kinds, default: 'string', name: 'type');
            $argument = '';
            if ($kind === 'enum') {
                $cases = Prompts::text("Which values may {$name} take?", placeholder: 'Draft,Published', required: true, validate: self::verdict(fn (string $cases) => ResourceFields::parse("{$name}:enum({$cases})", $this->findModel(...))), hint: 'Capitalised names, split by commas.', name: 'cases');
                $argument = "({$cases})";
            }
            if ($kind === 'belongsTo') {
                $choices = [];
                foreach ($this->models as $model) {
                    $choices[$model] = Str::shortName($model);
                }
                $default = self::tried(fn (): ?string => $this->findModel($name));
                $argument = '(' . (string) Prompts::select("Which model does {$name} belong to?", $choices, default: $default, name: 'model') . ')';
            }
            $nullable = Prompts::confirm("May {$name} be left empty?", default: false, name: 'nullable');
            $spec = "{$name}:" . ($nullable ? '?' : '') . $kind . $argument;
            try {
                ResourceFields::parse(implode(',', [...$specs, $spec]), $this->findModel(...));
            } catch (\InvalidArgumentException $e) {
                Prompts::error($e->getMessage() . ' That field was left out; name it again.');
                continue;
            }
            $specs[] = $spec;
            $names[] = $name;
        }

        return implode(',', $specs);
    }

    /**
     * The property a related model's choices are labelled and ordered by:
     * its name, title or the like where it has one, any line of text
     * otherwise, and its id where it has none.
     *
     * @param class-string<Model> $model
     */
    private static function choiceLabel(string $model): string
    {
        $candidates = [];
        foreach ((new \ReflectionClass($model))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($property->getAttributes(\Tinvar\Orm\Attributes\Column::class) !== [] && $type instanceof \ReflectionNamedType && $type->getName() === 'string' && !$type->allowsNull()) {
                $candidates[] = $property->getName();
            }
        }
        foreach (['name', 'title', 'label', 'email', 'number', 'sku', 'code'] as $preferred) {
            if (\in_array($preferred, $candidates, true)) {
                return $preferred;
            }
        }

        return $candidates[0] ?? 'id';
    }

    /**
     * The factory make:model writes for $model, where it has one: what a
     * resource's test makes a related row with.
     *
     * @param class-string<Model> $model
     */
    private function factoryOf(string $model): ?string
    {
        $models = "{$this->layout->namespace}\\{$this->layout->area('Models')}\\";
        if (!str_starts_with($model, $models)) {
            return null;
        }
        $factory = "{$this->layout->namespace}\\Database\\Factories\\" . substr($model, \strlen($models)) . 'Factory';

        return class_exists($factory) && is_subclass_of($factory, \Tinvar\Orm\Factory::class) ? $factory : null;
    }

    /**
     * The resource's test class, where tinvar/testing is there to write it
     * and the application's tests can run it as the skeleton's run: a tests/ directory, a Kernel built by
     * fromEnvironment(database:, telemetry:), a Database\Migrations that
     * migrates, and a factory for each related model. Null otherwise.
     *
     * @param non-empty-list<string> $segments
     * @param array<string, string|null> $relatedFactories
     */
    private function testClass(array $segments, array $relatedFactories): ?string
    {
        $kernel = "{$this->layout->namespace}\\Kernel";
        $migrations = "{$this->layout->namespace}\\Database\\Migrations";
        if ($this->resourceTest === null || !is_dir("{$this->layout->root}/tests") || \in_array(null, $relatedFactories, true)
            || !class_exists($kernel) || !method_exists($kernel, 'fromEnvironment')
            || !class_exists($migrations) || !method_exists($migrations, 'migrate')) {
            return null;
        }
        $parameters = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod($kernel, 'fromEnvironment'))->getParameters());
        if (!\in_array('database', $parameters, true) || !\in_array('telemetry', $parameters, true)) {
            return null;
        }

        return 'Tests\\Feature\\' . implode('\\', $segments);
    }

    /** @param array<string, string|null> $relatedFactories */
    private function whyNoTest(array $relatedFactories): string
    {
        $missing = array_keys(array_filter($relatedFactories, static fn (?string $factory): bool => $factory === null));
        if ($missing !== []) {
            return 'No test was written: ' . implode(' and ', $missing) . ' belong' . (\count($missing) === 1 ? 's' : '') . ' to a model with no factory to make a row with (`tinvar make:model --factory`).';
        }

        if ($this->resourceTest === null) {
            return 'No test was written: tinvar/testing, which writes it, is not handed to this console (bin/tinvar passes it where it is installed).';
        }

        return 'No test was written: the app has no tests/ directory, or no Kernel::fromEnvironment(database:, telemetry:) and Database\\Migrations to build one with, as the skeleton has.';
    }

    /**
     * What $command needs and was not given, asked; and, run bare, the
     * rest of what it takes. Each answer is held to what the argument would
     * be, and the generator holds it again as if typed.
     *
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @return array{list<string>, array<string, string|true>}
     */
    private function ask(string $command, array $positional, array $options): array
    {
        $bare = $positional === [] && $options === [];

        switch ($command) {
            case 'make:page':
                if ($positional === []) {
                    $positional[] = Prompts::text('What is the page called?', placeholder: 'Racks/Show', required: true, validate: self::verdict(static fn (string $name) => Names::classPath($name, 'page name', 'Racks/Show')), hint: "A class under Pages; '/' makes a namespace.", name: 'name');
                }
                $segments = self::tried(static fn (): array => Names::classPath($positional[0], 'page name', 'Racks/Show'));
                $kebab = $segments === null ? [] : array_map(Names::kebab(...), $segments);
                if (!isset($options['path']) && \count($positional) === 1) {
                    $options['path'] = Prompts::text('What path does it answer?', placeholder: '/racks/{rack}', default: '/' . implode('/', $kebab), required: true, validate: self::verdict(function (string $path): void {
                        foreach (Names::routeParameters($path) as $parameter) {
                            $this->modelNamedBy($parameter, $path);
                        }
                    }), hint: "A {parameter} named for one of the app's models takes that model.", name: 'path');
                }
                if ($bare && $kebab !== []) {
                    $options['view'] = Prompts::text('Which view renders it?', default: implode('/', $kebab), required: true, validate: self::verdict(static fn (string $view) => Names::view($view)), name: 'view');
                }
                break;
            case 'make:query':
                if ($positional === []) {
                    $positional[] = Prompts::text('What is the query called?', placeholder: 'Racks/Stats', required: true, validate: self::verdict(static fn (string $name) => Names::classPath($name, 'query name', 'RackStats, or Racks/Stats')), name: 'name');
                }
                if ($bare) {
                    $options = $this->askModel($options, 'Which model does it read?');
                }
                break;
            case 'make:action':
                if ($positional === []) {
                    $positional[] = Prompts::text('What is the class of actions called?', placeholder: 'RackActions', required: true, validate: self::verdict(static fn (string $name) => Names::classPath($name, 'class name', 'RackActions')), name: 'class');
                }
                if (\count($positional) === 1) {
                    $positional[] = Prompts::text('What is the action called?', placeholder: 'moveUnit', required: true, validate: self::verdict(static fn (string $method) => Names::method($method)), hint: 'The method, in lower camel case.', name: 'method');
                }
                if ($bare) {
                    $options = $this->askModel($options, 'Which model does it act on?');
                    if (Prompts::confirm('Write a form request for its input?', default: false, name: 'request')) {
                        $options['request'] = true;
                    }
                }
                break;
            case 'make:resource':
                if ($positional === []) {
                    $positional[] = Prompts::text('What is the resource called?', placeholder: 'Orders/Order', required: true, validate: self::verdict(static fn (string $name) => Names::classPath($name, 'resource name', 'Orders/Order')), hint: "Its model's name; a segment before it is its domain.", name: 'name');
                }
                if (!isset($options['fields']) && \count($positional) === 1) {
                    $options['fields'] = $this->askFields();
                    if (!isset($options['panel']) && Prompts::confirm('Place it in a panel?', default: false, hint: 'An #[AdminResource]: its list, record and forms in a panel, beside its own pages.', name: 'placed')) {
                        $options['panel'] = Prompts::text('Which panel?', placeholder: 'admin', required: true, validate: self::verdict(self::panelName(...)), name: 'panel');
                    }
                }
                break;
            case 'make:model':
                if ($positional === []) {
                    $positional[] = Prompts::text('What is the model called?', placeholder: 'Rack', required: true, validate: self::verdict(static fn (string $name) => Names::classPath($name, 'model name', 'Rack')), name: 'name');
                }
                if ($bare) {
                    $segments = Names::classPath($positional[0], 'model name', 'Rack');
                    $options['table'] = Prompts::text('What is its table called?', default: Names::plural(Str::snake(end($segments))), required: true, validate: self::verdict(static fn (string $table) => Names::table($table)), name: 'table');
                    $columns = Prompts::text('What columns does it have?', placeholder: 'name:string,heightU:int,notes:?string', validate: self::verdict(static fn (string $columns) => self::columns($columns)), hint: 'Each property:type -- ' . implode(', ', self::COLUMN_TYPES) . ", '?' before a type for null; the id is its own.", name: 'columns');
                    if ($columns !== '') {
                        $options['columns'] = $columns;
                    }
                    $extras = ['factory' => 'A factory, for tests and seeding'];
                    if ($this->migrate !== null) {
                        $extras['migration'] = 'A migration, for its table';
                    }
                    foreach (Prompts::multiselect('What else should be written with it?', $extras, name: 'extras') as $extra) {
                        $options[(string) $extra] = true;
                    }
                }
                break;
        }

        return [$positional, $options];
    }

    /**
     * --model, asked as a choice of the application's models, or none.
     *
     * @param array<string, string|true> $options
     * @return array<string, string|true>
     */
    private function askModel(array $options, string $question): array
    {
        if ($this->models === []) {
            return $options;
        }
        $choices = ['' => 'None: a policy on the class decides'];
        foreach ($this->models as $model) {
            $choices[$model] = Str::shortName($model);
        }
        $model = (string) Prompts::select($question, $choices, default: '', hint: 'A model it takes is resolved, scoped and authorized before it runs.', name: 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }

        return $options;
    }

    /**
     * A prompt's validate, from a check that throws what is wrong.
     *
     * @param \Closure(string): mixed $check
     * @return \Closure(mixed): ?string
     */
    private static function verdict(\Closure $check): \Closure
    {
        return static function (mixed $value) use ($check): ?string {
            try {
                $check(\is_scalar($value) ? (string) $value : '');

                return null;
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }
        };
    }

    /**
     * @template T
     * @param \Closure(): T $check
     * @return T|null
     */
    private static function tried(\Closure $check): mixed
    {
        try {
            return $check();
        } catch (\InvalidArgumentException) {
            return null;
        }
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

        // One the developer has just made, under the application's models'
        // namespace (Database\Models, or an older app's Models), and not yet
        // registered.
        $conventional = "{$this->layout->namespace}\\{$this->layout->area('Models')}\\" . ucfirst($name);
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
     * A class under the application's namespace, in the folder its kind
     * goes in (AppLayout::area()): App\Http\Pages\Orders\OrderDetail for
     * the page Orders/OrderDetail.
     *
     * @param list<string> $segments
     */
    private function class(string $area, array $segments): string
    {
        return "{$this->layout->namespace}\\{$this->layout->area($area)}\\" . implode('\\', $segments);
    }

    /**
     * Whether the application keeps its HTTP classes split by domain under
     * Http\, where a class written beside another goes in the same domain.
     */
    private function byDomain(): bool
    {
        return str_starts_with($this->layout->area('Data'), 'Http\\');
    }

    /**
     * A class named $short, in the domain $segments name -- every segment but
     * the last -- where the application is laid out by domain, and flat
     * where it is not: the request for Orders/OrderActions::place is
     * Http\Requests\Orders\PlaceRequest, or Requests\PlaceRequest.
     *
     * @param non-empty-list<string> $segments
     * @return non-empty-list<string>
     */
    private function besideDomain(array $segments, string $short): array
    {
        return $this->byDomain() ? [...\array_slice($segments, 0, -1), $short] : [$short];
    }

    /**
     * The Data class a page or query returns, by its segments. Flat, as
     * before, it is every segment joined: Racks/Show's is RacksShowPageData.
     * By domain, it goes in the page's domain under its own name --
     * Orders/OrderDetail's is Http\Data\Orders\OrderDetailPageData -- unless
     * a Data class of that name is already in another folder, when it is
     * the segments joined, as flat: codegen emits each Data class by its
     * short name, so two of one name would be refused there.
     *
     * @param non-empty-list<string> $segments
     * @return non-empty-list<string>
     */
    private function dataPath(array $segments, string $suffix): array
    {
        $joined = implode('', $segments) . $suffix;
        if (!$this->byDomain()) {
            return [$joined];
        }

        $own = end($segments) . $suffix;
        $path = $this->besideDomain($segments, $own);
        if (!$this->layout->namedElsewhere($this->layout->area('Data'), $own, implode('\\', $path))) {
            return $path;
        }
        // The joined name is the way round, unless it is the same name -- one
        // segment -- or taken as well.
        $fallback = $this->besideDomain($segments, $joined);
        if ($joined === $own || $this->layout->namedElsewhere($this->layout->area('Data'), $joined, implode('\\', $fallback))) {
            throw new GenerationRefused("A Data class named {$own} is in the app already, and so is one named {$joined}: codegen names each by its short name, "
                . 'so two of one name are refused there. Nothing was written. Name it differently.');
        }

        return $fallback;
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
            if (preg_match('/^--([a-z]+(?:-[a-z]+)*)(?:=(.*))?\z/s', $argument, $m) !== 1 || isset($options[$m[1]])) {
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
