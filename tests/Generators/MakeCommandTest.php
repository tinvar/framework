<?php

declare(strict_types=1);

/**
 * The generators: what make:page, make:query, make:action and make:model
 * write, and what they refuse to.
 *
 * Each test generates into an application of its own -- a composer.json,
 * an app/ and a resources/ under a new temporary directory, with a
 * namespace no other test uses -- and never into the shop. What is written
 * is held to what the shop's own code is held to: it parses, pint and
 * prettier leave it as it is, phpstan at level 8 finds nothing, and it
 * registers through the real route, query and action registries, which run
 * the build rules over it.
 */

namespace Tinvar\Tests\Generators\MakeCommand;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\AuthorizationException;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\InvalidAnswer;
use Tinvar\Console\Prompts\Key;
use Tinvar\Data\Data;
use Tinvar\Generators\AppLayout;
use Tinvar\Generators\GenerationRefused;
use Tinvar\Generators\MakeCommand;
use Tinvar\Generators\Registrations;
use Tinvar\Discovery\Discovery;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Factory;
use Tinvar\Orm\Migrations\MigrateCommand;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;

const ROOT = __DIR__ . '/../../../..';

/** An application to generate into, in a directory of its own. */
final class App
{
    public readonly string $root;

    /** Unique to this application, so its classes can be loaded beside every other test's. */
    public readonly string $namespace;

    /** @var list<string> */
    public array $out = [];

    /** @var list<string> */
    public array $err = [];

    private readonly \Closure $autoload;

    /** @param array<string, string> $psr4 extra psr-4 entries, prefix => directory */
    public function __construct(array $psr4 = [])
    {
        $this->root = sys_get_temp_dir() . '/tinvar-make-' . bin2hex(random_bytes(6));
        $this->namespace = 'Gen' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/resources/pages", 0o755, true);
        mkdir("{$this->root}/resources/types", 0o755, true);
        file_put_contents("{$this->root}/composer.json", json_encode([
            'autoload-dev' => ['psr-4' => [
                "{$this->namespace}\\" => 'app/',
                "{$this->namespace}\\Database\\Factories\\" => 'database/factories/',
                ...$psr4,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $root = $this->root;
        $namespace = $this->namespace;
        $this->autoload = static function (string $class) use ($root, $namespace): void {
            foreach (["{$namespace}\\Database\\Factories\\" => 'database/factories', "{$namespace}\\" => 'app'] as $prefix => $directory) {
                if (str_starts_with($class, $prefix)) {
                    $file = "{$root}/{$directory}/" . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }

                    return;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    public function layout(): AppLayout
    {
        return AppLayout::fromComposer($this->root, $this->namespace, "{$this->root}/resources/pages", "{$this->root}/resources/types");
    }

    /**
     * @param list<string> $arguments
     * @param list<class-string<Model>> $models
     */
    public function __invoke(string $command, array $arguments, array $models = [], ?Registrations $registrations = null, ?\Closure $migrate = null): int
    {
        $this->out = [];
        $this->err = [];

        return new MakeCommand(
            $this->layout(),
            $models,
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            },
            $registrations ?? new Registrations(),
            $migrate,
        )->run($command, $arguments);
    }

    /** @return class-string */
    public function class(string $relative): string
    {
        /** @var class-string */
        return "{$this->namespace}\\{$relative}";
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents("{$this->root}/{$relative}");
    }

    /** @return list<string> every file under the application, relative to it */
    public function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr((string) $file, \strlen($this->root) + 1);
        }
        sort($files);

        return $files;
    }

    public function remove(): void
    {
        spl_autoload_unregister($this->autoload);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = (string) $item;
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
    }
}

/**
 * A tool's exit status and everything it printed.
 *
 * @param list<string> $command
 * @return array{int, string}
 */
function tool(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

beforeEach(function () {
    $this->app = new App();
});

afterEach(function () {
    $this->app->remove();
    Prompts::restore();
});

it('writes a model, its factory, pages, queries and actions that lint and pass the build rules through the real registries', function () {
    $app = $this->app;

    expect($app('make:model', ['Rack', '--columns=name:string,heightU:int,load:float,active:bool,notes:?string', '--factory']))->toBe(0, implode("\n", $app->err))
        ->and($app('make:model', ['OrderLine']))->toBe(0, implode("\n", $app->err));
    $rack = $app->class('Models\Rack');
    $models = [$rack, $app->class('Models\OrderLine')];

    $made = [
        ['make:page', ['Racks/Show', '--path=/racks/{rack}']],
        ['make:page', ['Racks/Index', '--path=/racks']],
        ['make:page', ['Racks/Units/History', '--path=/racks/{rack}/units/{unit}/history', '--view=admin/inventory/racks-and-units/unit-history-detail']],
        ['make:page', ['Rack', '--path=/r/{rack}']],
        ['make:query', ['Racks/Stats', '--model=Rack']],
        ['make:query', ['Summary']],
        ['make:action', ['RackActions', 'moveUnit', '--model=Rack', '--request']],
        ['make:action', ['Admin/SystemActions', 'purge']],
    ];
    foreach ($made as [$command, $arguments]) {
        expect($app($command, $arguments, $models))->toBe(0, "{$command}: " . implode("\n", $app->err));
    }

    expect($app->files())->toBe([
        'app/Actions/Admin/SystemActions.php',
        'app/Actions/RackActions.php',
        'app/Data/RackPageData.php',
        'app/Data/RacksIndexPageData.php',
        'app/Data/RacksShowPageData.php',
        'app/Data/RacksStatsData.php',
        'app/Data/RacksUnitsHistoryPageData.php',
        'app/Data/SummaryData.php',
        'app/Models/OrderLine.php',
        'app/Models/Rack.php',
        'app/Pages/Rack.php',
        'app/Pages/Racks/Index.php',
        'app/Pages/Racks/Show.php',
        'app/Pages/Racks/Units/History.php',
        'app/Queries/Racks/Stats.php',
        'app/Queries/Summary.php',
        'app/Requests/MoveUnitRequest.php',
        'composer.json',
        'database/factories/RackFactory.php',
        'resources/pages/admin/inventory/racks-and-units/unit-history-detail.tsx',
        'resources/pages/rack.tsx',
        'resources/pages/racks/index.tsx',
        'resources/pages/racks/show.tsx',
    ]);

    // The spec's shapes: a route parameter naming a model is the model, and
    // the check names it; one naming none is a string.
    $show = $app->read('app/Pages/Racks/Show.php');
    expect($show)->toContain("#[Page('/racks/{rack}', view: 'racks/show', name: 'racks.show'), Authorize('view', 'rack')]")
        ->and($show)->toContain('public function __invoke(Rack $rack): RacksShowPageData')
        ->and($app->read('app/Pages/Racks/Units/History.php'))->toContain('public function __invoke(Rack $rack, string $unit): RacksUnitsHistoryPageData')
        ->and($app->read('app/Pages/Rack.php'))->toContain("use {$rack} as ModelsRack;")
        ->and($app->read('app/Pages/Racks/Index.php'))->toContain("Authorize('view', Index::class)")
        ->and($app->read('resources/pages/racks/show.tsx'))->toContain("import type { PageProps } from '../../types/generated';")
        ->and($app->read('resources/pages/racks/show.tsx'))->toContain("export default function Page({ data, params }: PageProps<'racks/show'>) {")
        ->and($app->read('resources/pages/rack.tsx'))->toContain("from '../types/generated'")
        ->and($app->read('app/Queries/Racks/Stats.php'))->toContain("#[Query, Authorize('view', 'rack')]")
        ->and($app->read('app/Actions/RackActions.php'))->toContain("#[Action, Authorize('update', 'rack')]")
        ->and($app->read('app/Actions/RackActions.php'))->toContain('public function moveUnit(Rack $rack, MoveUnitRequest $request): ActionResult')
        ->and($app->read('app/Models/Rack.php'))->toContain("#[Table('racks')]")
        ->and($app->read('app/Models/Rack.php'))->toContain('#[Column] public ?string $notes = null;')
        ->and($app->read('app/Models/OrderLine.php'))->toContain("#[Table('order_lines')]")
        ->and($app->read('database/factories/RackFactory.php'))->toContain('@extends Factory<Rack>');

    foreach ($app->files() as $file) {
        if (str_ends_with($file, '.php')) {
            [$status, $output] = tool([PHP_BINARY, '-l', "{$app->root}/{$file}"]);
            expect($status)->toBe(0, $output);
        }
    }

    [$status, $output] = tool([ROOT . '/vendor/bin/pint', '--test', '--config', ROOT . '/pint.json', "{$app->root}/app", "{$app->root}/database"]);
    expect($status)->toBe(0, $output);

    [$status, $output] = tool([ROOT . '/node_modules/.bin/prettier', '--check', '--config', ROOT . '/.prettierrc.json', "{$app->root}/resources/pages"]);
    expect($status)->toBe(0, $output);

    file_put_contents("{$app->root}/phpstan.neon", "parameters:\n    level: 8\n    paths:\n        - app\n        - database\n    tmpDir: .phpstan\n");
    [$status, $output] = tool([
        ROOT . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G',
        '-c', "{$app->root}/phpstan.neon", '--autoload-file=' . ROOT . '/vendor/autoload.php',
    ]);
    expect($status)->toBe(0, $output);

    // Registration runs the build rules -- authorize-every-model-input,
    // query-must-declare-authorization -- and refuses what fails them.
    $pages = array_map($app->class(...), ['Pages\Racks\Show', 'Pages\Racks\Index', 'Pages\Racks\Units\History', 'Pages\Rack']);
    $queries = array_map($app->class(...), ['Queries\Racks\Stats', 'Queries\Summary']);
    $actions = array_map($app->class(...), ['Actions\RackActions', 'Actions\Admin\SystemActions']);

    expect(\count((new RouteRegistry())->registerAll($pages)))->toBe(4)
        ->and((new QueryRegistry())->registerAll($queries))->toBe(['racks.stats', 'summary'])
        ->and(\count((new ActionRegistry())->registerAll($actions)))->toBe(2);

    // And the half registration cannot see: the rules that need every
    // model, and the Data objects and form requests.
    $payloads = array_map($app->class(...), [
        'Data\RacksShowPageData', 'Data\RacksIndexPageData', 'Data\RacksUnitsHistoryPageData', 'Data\RackPageData',
        'Data\RacksStatsData', 'Data\SummaryData', 'Requests\MoveUnitRequest',
    ]);
    expect((new BuildRules($models))->check([...$pages, ...$queries, ...$actions], $payloads))->toBe([]);

    // The views type-check against what codegen writes for them: PageProps
    // of each view, imported from where each one says.
    $routes = new RouteRegistry();
    $routes->registerAll($pages);
    $registered = new ActionRegistry();
    $registered->registerAll($actions);
    $types = (new TypeScriptGenerator())->files(
        dataClasses: array_values(array_filter($payloads, static fn (string $class): bool => is_subclass_of($class, Data::class))),
        queryClasses: $queries,
        routes: array_map(RouteTypes::of(...), [...$routes->layouts(), ...$routes->pages()]),
        actions: $registered->all(),
    );
    mkdir("{$app->root}/resources/types/actions");
    file_put_contents("{$app->root}/resources/types/generated.ts", $types['module']);
    file_put_contents("{$app->root}/resources/types/modules.d.ts", $types['ambient']);
    foreach ($types['actions'] as $class => $contents) {
        file_put_contents("{$app->root}/resources/types/actions/{$class}.ts", $contents);
    }
    $root = (string) realpath(ROOT);
    file_put_contents("{$app->root}/tsconfig.json", json_encode([
        'extends' => "{$root}/tsconfig.base.json",
        'compilerOptions' => [
            'noEmit' => true,
            'types' => [],
            'paths' => [
                'react' => ["{$root}/node_modules/@types/react"],
                TypeScriptGenerator::ROUTE_RUNTIME => ["{$root}/packages/react/src/route.d.ts"],
                TypeScriptGenerator::ACTION_RUNTIME => ["{$root}/packages/react/src/action.d.ts"],
            ],
        ],
        'include' => ['resources/**/*.ts', 'resources/**/*.tsx'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    [$status, $output] = tool([ROOT . '/node_modules/.bin/tsc', '-p', "{$app->root}/tsconfig.json", '--pretty', 'false']);
    expect($status)->toBe(0, $output);
});

// "Typed properties are the schema": the model is all make:migration needs
// to make its table, and the factory fills a row of it.
// ADR 0148: an application laid out as the skeleton is -- its HTTP classes
// under Http\, its models under Database\Models\ -- gets each class there,
// in the domain its first segments name.
it('writes into Http\ and Database\Models\, by domain, for an application laid out so', function () {
    $app = $this->app;
    mkdir("{$app->root}/app/Http", 0o755, true);

    expect($app('make:model', ['Orders/Order', '--factory']))->toBe(0, implode("\n", $app->err));
    $order = $app->class('Database\Models\Orders\Order');
    $models = [$order];

    foreach ([
        ['make:page', ['Orders/OrderDetail', '--path=/orders/{order}']],
        ['make:page', ['Racks/Show', '--path=/racks']],
        ['make:query', ['Orders/OrderTotals', '--model=Order']],
        ['make:action', ['Orders/OrderActions', 'place', '--model=Order', '--request']],
        ['make:action', ['NoteActions', 'add', '--request']],
    ] as [$command, $arguments]) {
        expect($app($command, $arguments, $models))->toBe(0, "{$command}: " . implode("\n", $app->err));
    }

    expect($app->files())->toBe([
        'app/Database/Models/Orders/Order.php',
        'app/Http/Actions/NoteActions.php',
        'app/Http/Actions/Orders/OrderActions.php',
        'app/Http/Data/Orders/OrderDetailPageData.php',
        'app/Http/Data/Orders/OrderTotalsData.php',
        'app/Http/Data/Racks/RacksShowPageData.php',
        'app/Http/Pages/Orders/OrderDetail.php',
        'app/Http/Pages/Racks/Show.php',
        'app/Http/Queries/Orders/OrderTotals.php',
        'app/Http/Requests/AddRequest.php',
        'app/Http/Requests/Orders/PlaceRequest.php',
        'composer.json',
        'database/factories/Orders/OrderFactory.php',
        'resources/pages/orders/order-detail.tsx',
        'resources/pages/racks/show.tsx',
    ]);

    // The route parameter is the model where it is now, and the query's wire
    // name is its namespace under Queries, as Str::queryName() has it.
    expect($app->read('app/Http/Pages/Orders/OrderDetail.php'))->toContain("namespace {$app->namespace}\\Http\\Pages\\Orders;")
        ->and($app->read('app/Http/Pages/Orders/OrderDetail.php'))->toContain('Order $order')
        ->and($app->read('app/Http/Queries/Orders/OrderTotals.php'))->toContain('orders.orderTotals: what a server component awaits');

    $classes = new Discovery($app->namespace, ["{$app->namespace}\\" => ["{$app->root}/app"]], "{$app->root}/dist/discovery.php", $app->root)->scan();
    expect($classes->models)->toBe([$order])
        ->and($classes->queries)->toBe([$app->class('Http\Queries\Orders\OrderTotals')])
        ->and($classes->actions)->toBe([$app->class('Http\Actions\NoteActions'), $app->class('Http\Actions\Orders\OrderActions')]);
});

it('keeps writing a kind where an older application already keeps it, flat', function () {
    $app = $this->app;
    mkdir("{$app->root}/app/Http", 0o755, true);
    mkdir("{$app->root}/app/Models", 0o755, true);
    mkdir("{$app->root}/app/Pages", 0o755, true);

    expect($app('make:model', ['Rack']))->toBe(0, implode("\n", $app->err))
        ->and($app('make:page', ['Racks/Show', '--path=/racks']))->toBe(0, implode("\n", $app->err))
        ->and($app('make:query', ['Racks/Stats']))->toBe(0, implode("\n", $app->err));

    // Models and pages where they are; queries and Data, which it keeps
    // nowhere yet, under Http\ beside the folder it made for them.
    expect($app->files())->toBe([
        'app/Http/Data/Racks/RacksShowPageData.php',
        'app/Http/Data/Racks/RacksStatsData.php',
        'app/Http/Queries/Racks/Stats.php',
        'app/Models/Rack.php',
        'app/Pages/Racks/Show.php',
        'composer.json',
        'resources/pages/racks/show.tsx',
    ]);
});

it('writes a model make:migration makes a table for, and a factory that fills a row of it', function () {
    $app = $this->app;
    expect($app('make:model', ['Rack', '--columns=name:string,heightU:int,load:float,active:bool,notes:?string', '--factory']))->toBe(0);
    $rack = $app->class('Models\Rack');
    $database = "{$app->root}/database.sqlite";

    $migrations = new MigrateCommand(
        static fn (bool $create): Connection => PdoConnection::sqlite($database, create: true),
        "{$app->root}/database/migrations",
        [$rack],
        static function (string $line): void {},
        static function (string $line): void {},
    );
    expect($migrations->run('make:migration', ['create_racks']))->toBe(0)
        ->and($migrations->run('migrate', []))->toBe(0);

    $orm = new Orm(PdoConnection::sqlite($database));
    /** @var class-string<Factory<Model>> $factory */
    $factory = $app->class('Database\Factories\RackFactory');
    $made = $factory::new()->create($orm);

    // Read back through another ORM, whose identity map has not seen it.
    $row = (new Orm(PdoConnection::sqlite($database)))->find($rack, $made->id);
    expect($row)->toBeInstanceOf($rack)
        ->and([$row->name, $row->heightU, $row->load, $row->active, $row->notes])->toBe(['name 1', 1, 1.0, false, null]);
});

// The other answer the build rules accept, #[NoAuthorization], would let
// everyone in; nothing reads its reason, so a placeholder there would ship.
it('names the class itself where there is no model, so nobody is let in until a policy is registered', function () {
    $app = $this->app;
    $said = [];
    foreach ([['make:page', ['Dashboard', '--path=/dashboard']], ['make:query', ['Totals']], ['make:action', ['ReportActions', 'rebuild']]] as [$command, $arguments]) {
        expect($app($command, $arguments))->toBe(0);
        $said[] = end($app->out);
    }
    expect($said)->toBe([
        "Until a policy with a view() ability is registered for {$app->namespace}\\Pages\\Dashboard, every request to the page is refused.",
        "Until a policy with a view() ability is registered for {$app->namespace}\\Queries\\Totals, every call to the query is refused.",
        "Until a policy with a rebuild() ability is registered for {$app->namespace}\\Actions\\ReportActions, every call to the action is refused.",
    ]);

    $checks = [
        ...Authorize::declaredOn($app->class('Pages\Dashboard'), '__invoke'),
        ...Authorize::declaredOn($app->class('Queries\Totals'), '__invoke'),
        ...Authorize::declaredOn($app->class('Actions\ReportActions'), 'rebuild'),
    ];
    expect(array_map(static fn (Authorize $check): array => [$check->ability, $check->argument], $checks))->toBe([
        ['view', $app->class('Pages\Dashboard')],
        ['view', $app->class('Queries\Totals')],
        ['rebuild', $app->class('Actions\ReportActions')],
    ]);

    $gate = new PolicyGate(new PolicyRegistry(), new AuthContext());
    foreach ($checks as $check) {
        expect(static fn () => $gate->allows($check, []))->toThrow(AuthorizationException::class, 'No policy is registered');
    }

    expect($app->read('app/Pages/Dashboard.php'))->not->toContain('NoAuthorization')
        ->and(strtolower($app->read('app/Pages/Dashboard.php') . $app->read('app/Queries/Totals.php') . $app->read('app/Actions/ReportActions.php')))->not->toContain('todo');
});

// The build reads each view's calls into its route's allowlist (ADR 0024's
// manifest), so there is no list for a developer to keep, and saying there
// was sent them looking for one.
it('says what is left to do for a query, and nothing the build does', function () {
    $app = $this->app;
    expect($app('make:model', ['Rack']))->toBe(0)
        ->and($app('make:query', ['Racks/Stats', '--model=Rack'], [$app->class('Models\Rack')]))->toBe(0, implode("\n", $app->err));

    expect($app->out)->toContain(
        "Next: run `tinvar codegen` for its `@queries` function. The build allows 'racks.stats' on each route whose views call it.",
    )
        ->and(implode("\n", $app->out))->not->toContain('among the queries');

    // The class's docblock says the same, read as one paragraph.
    $docblock = str_replace("\n * ", ' ', $app->read('app/Queries/Racks/Stats.php'));
    expect($docblock)->toContain("the build reads their calls into the route's allowlist")
        ->and($docblock)->not->toContain('among its queries');
});

it('refuses, and writes nothing, when any file it would write is there', function () {
    $app = $this->app;
    mkdir("{$app->root}/database/factories", 0o755, true);
    file_put_contents("{$app->root}/database/factories/RackFactory.php", "<?php // the developer's\n");

    expect($app('make:model', ['Rack', '--factory']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain('database/factories/RackFactory.php already exists')
        ->and(is_file("{$app->root}/app/Models/Rack.php"))->toBeFalse()
        ->and($app->read('database/factories/RackFactory.php'))->toBe("<?php // the developer's\n");

    // A second run of the same command finds the first one's files.
    expect($app('make:query', ['Totals']))->toBe(0)
        ->and($app('make:query', ['Totals']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain('app/Queries/Totals.php, app/Data/TotalsData.php');

    // A view of another kind by the same name would be imported beside it.
    mkdir("{$app->root}/resources/pages/racks");
    file_put_contents("{$app->root}/resources/pages/racks/show.jsx", 'export default function Page() {}');
    expect($app('make:page', ['Racks/Show', '--path=/racks/{rack}']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain('resources/pages/racks/show.jsx already exists')
        ->and(is_dir("{$app->root}/app/Pages"))->toBeFalse();

    // Nor through a link left where a file would go.
    symlink('/nonexistent-target', "{$app->root}/app/Queries/Other.php");
    expect($app('make:query', ['Other']))->toBe(MakeCommand::FAILED)
        ->and(is_file("{$app->root}/app/Data/OtherData.php"))->toBeFalse();
});

it('refuses a name, a route or a view that is not one, and writes nothing', function (string $command, array $arguments, string $says) {
    $app = $this->app;
    $before = $app->files();

    expect($app($command, $arguments))->toBe(MakeCommand::USAGE)
        ->and(implode("\n", $app->err))->toContain($says)
        ->and($app->files())->toBe($before);
})->with([
    'a parent directory' => ['make:page', ['../Evil', '--path=/x'], 'is not a page name'],
    'a parent directory, inside' => ['make:page', ['Racks/../../Evil', '--path=/x'], 'is not a page name'],
    'a backslashed parent' => ['make:query', ['Racks\\..\\Evil'], 'is not a query name'],
    'an absolute name' => ['make:model', ['/etc/Passwd'], 'is not a model name'],
    'a lower-case name' => ['make:model', ['rack'], 'is not a model name'],
    'a reserved word' => ['make:page', ['Racks/List', '--path=/racks'], 'is not a page name'],
    'a TypeScript reserved word' => ['make:action', ['RackActions', 'delete'], 'is not a method name'],
    'a method that is not one' => ['make:action', ['RackActions', 'move-unit'], 'is not a method name'],
    'a view climbing out' => ['make:page', ['Show', '--path=/x', '--view=../../../etc/cron'], 'is not a view'],
    'an absolute view' => ['make:page', ['Show', '--path=/x', '--view=/etc/cron'], 'is not a view'],
    'a view with a dot' => ['make:page', ['Show', '--path=/x', '--view=racks/show.php'], 'is not a view'],
    'a route climbing out' => ['make:page', ['Show', '--path=/racks/../admin'], "segment '..'"],
    'a relative route' => ['make:page', ['Show', '--path=racks'], 'is not a route'],
    'a route with a quote' => ['make:page', ['Show', "--path=/racks/'x"], "segment ''x'"],
    'a parameter that is not one' => ['make:page', ['Show', '--path=/racks/{1rack}'], "segment '{1rack}'"],
    'a parameter twice' => ['make:page', ['Show', '--path=/racks/{rack}/{rack}'], 'twice'],
    'no route' => ['make:page', ['Show'], 'needs --path'],
    'a table that is not one' => ['make:model', ['Rack', "--table=racks'); DROP TABLE x; --"], 'is not a table name'],
    'a column that is not one' => ['make:model', ['Rack', '--columns=bad-name:string'], "is not a column's property name"],
    'a column type it does not take' => ['make:model', ['Rack', '--columns=name:resource'], 'is not a column type'],
    'a type nullable twice' => ['make:model', ['Rack', '--columns=name:??string'], 'is not a column type'],
    'the id as a column' => ['make:model', ['Rack', '--columns=id:string'], 'which is the id'],
    'a property the ORM keeps' => ['make:model', ['Rack', '--columns=original:string'], 'which every model already has'],
    'an unknown option' => ['make:query', ['Totals', '--shared'], "Unknown argument '--shared'"],
    'an unknown model' => ['make:query', ['Totals', '--model=Nope'], "'Nope' is not one of the application's models"],
    'too many arguments' => ['make:model', ['Rack', 'Unit'], 'Usage: tinvar make:model'],
]);

it('refuses a route parameter that names a model by its id', function () {
    $app = $this->app;
    $app('make:model', ['Rack']);

    expect($app('make:page', ['Racks/Show', '--path=/racks/{rackId}'], [$app->class('Models\Rack')]))->toBe(MakeCommand::USAGE)
        ->and(implode("\n", $app->err))->toContain('{rackId} in /racks/{rackId} names Rack by its id. Name it {rack}')
        ->and(is_dir("{$app->root}/app/Pages"))->toBeFalse();
});

it('writes nowhere a psr-4 entry outside the project, or a link out of the application, would take it', function () {
    $app = new App(['Evil\\' => '../elsewhere/']);
    try {
        // Only the application's own entries count: Evil\ is not one.
        expect($app->layout())->toBeInstanceOf(AppLayout::class);
    } finally {
        $app->remove();
    }

    $outside = new App();
    $app = new App();
    $composer = json_decode($app->read('composer.json'), true);
    $composer['autoload-dev']['psr-4']["{$app->namespace}\\Pages\\"] = '../elsewhere/';
    file_put_contents("{$app->root}/composer.json", json_encode($composer));
    try {
        expect(static fn () => $app->layout())->toThrow(GenerationRefused::class, 'outside the project');
    } finally {
        $app->remove();
    }

    // app/Queries is a link to a directory outside the application.
    $app = $this->app;
    mkdir("{$app->root}/app");
    symlink($outside->root, "{$app->root}/app/Queries");
    try {
        expect($app('make:query', ['Totals']))->toBe(MakeCommand::FAILED)
            ->and(implode("\n", $app->err))->toContain('is not inside')
            ->and(scandir($outside->root))->toBe(['.', '..', 'composer.json', 'resources'])
            ->and(is_file("{$app->root}/app/Data/TotalsData.php"))->toBeFalse();
    } finally {
        unlink("{$app->root}/app/Queries");
        $outside->remove();
    }
});

// bin/tinvar hands the generators the shop. Only refusals are run here: a
// test never writes into the shop's committed tree.
it('is wired into bin/tinvar, against the shop, refusing what is there', function () {
    $product = ROOT . '/examples/shop/app/Database/Models/Catalog/Product.php';
    $before = hash_file('sha256', $product);

    [$status, $output] = tool([PHP_BINARY, ROOT . '/bin/tinvar', 'make:model', 'Catalog/Product']);
    expect($status)->toBe(MakeCommand::FAILED)
        ->and($output)->toContain('examples/shop/app/Database/Models/Catalog/Product.php already exists')
        ->and(hash_file('sha256', $product))->toBe($before);

    [$status, $output] = tool([PHP_BINARY, ROOT . '/bin/tinvar', 'make:page', 'Catalog', '--path=/products/{productId}']);
    expect($status)->toBe(MakeCommand::USAGE)
        ->and($output)->toContain('names Product by its id');

    [$status, $output] = tool([PHP_BINARY, ROOT . '/bin/tinvar', 'help']);
    expect($output)->toContain('make:page <Name> --path=/racks/{rack}')
        ->and($output)->toContain('make:action <Class> <method>');
});

/**
 * Writes a layout and a route table into $app as an application keeps
 * them, and the Registrations that point at the table. No Kernel list:
 * the classes a generator writes are discovered (ADR 0146).
 */
function registered(App $app): Registrations
{
    $ns = $app->namespace;
    mkdir("{$app->root}/app/Pages", 0o755, true);
    mkdir("{$app->root}/resources/entries", 0o755, true);
    file_put_contents("{$app->root}/app/Pages/Layout.php", "<?php\n\nnamespace {$ns}\\Pages;\n\nuse Tinvar\\Attributes\\Layout as LayoutAttribute;\n\n#[LayoutAttribute('/', view: 'app/layout')]\nfinal class Layout {}\n");
    file_put_contents("{$app->root}/resources/entries/rsc.tsx", <<<'TSX'
        import type { ReactNode } from 'react';
        import AppLayout from '../pages/app/layout';

        type Page = (props: { data: never; params: never }) => ReactNode;

        const ROUTES: Record<string, { Page: Page; layouts: readonly 'app'[] }> = {
          // The pages.
        };

        export { AppLayout, ROUTES };

        TSX);

    return new Registrations(routeTable: 'resources/entries/rsc.tsx', routes: ["{$ns}\\Pages\\Layout"]);
}

it('writes classes discovery registers, edits no list, and puts a page\'s view in the route table inside its layouts', function () {
    $app = $this->app;
    $registrations = registered($app);

    expect($app('make:model', ['Shelf'], registrations: $registrations))->toBe(0, implode("\n", $app->err))
        ->and($app('make:page', ['Shelves/Show', '--path=/shelves/{shelf}'], [$app->class('Models\Shelf')], $registrations))->toBe(0, implode("\n", $app->err))
        ->and($app->out)->toContain("registered 'shelves/show' in resources/entries/rsc.tsx, in app")
        ->and($app->out)->toContain("Next: run `tinvar codegen` for PageProps<'shelves/show'>.")
        ->and(implode("\n", $app->out))->not->toContain('Kernel')
        ->and($app('make:query', ['Stats'], registrations: $registrations))->toBe(0)
        ->and($app('make:action', ['ShelfActions', 'move'], registrations: $registrations))->toBe(0)
        ->and($app->read('resources/entries/rsc.tsx'))->toContain("import AppLayout from '../pages/app/layout';\nimport ShelvesShowPage from '../pages/shelves/show';\n")
        ->and($app->read('resources/entries/rsc.tsx'))->toContain("  // The pages.\n  'shelves/show': { Page: ShelvesShowPage as Page, layouts: ['app'] },\n};");

    // What each wrote is registered by being there.
    $classes = new Discovery($app->namespace, ["{$app->namespace}\\" => ["{$app->root}/app"]], "{$app->root}/dist/discovery.php", $app->root)->scan();

    expect($classes->routes)->toBe([$app->class('Pages\Layout'), $app->class('Pages\Shelves\Show')])
        ->and($classes->models)->toBe([$app->class('Models\Shelf')])
        ->and($classes->queries)->toBe([$app->class('Queries\Stats')])
        ->and($classes->actions)->toBe([$app->class('Actions\ShelfActions')]);
});

it('edits no route table outside the application', function () {
    $app = $this->app;
    registered($app);
    $outside = sys_get_temp_dir() . '/tinvar-outside-' . bin2hex(random_bytes(4)) . '.tsx';
    file_put_contents($outside, "const ROUTES = {\n};\n");
    try {
        $registrations = new Registrations(routeTable: $outside, routes: [$app->class('Pages\Layout')]);

        expect($app('make:page', ['Bin', '--path=/bin'], registrations: $registrations))->toBe(0)
            ->and(file_get_contents($outside))->toBe("const ROUTES = {\n};\n")
            ->and(implode("\n", $app->out))->toContain('is not inside');
    } finally {
        unlink($outside);
    }
});

// Run bare where a person is there, a generator asks what it was not
// given -- and, as the owner put it, "tinvar make:model asks the name,
// whether to make a migration, and so on" -- each answer held to what the
// argument would be.
it('asks make:model\'s name, table, columns, factory and migration when run bare', function () {
    $app = $this->app;
    $migrations = [];
    $prompts = Prompts::fake(['name' => 'Rack', 'table' => 'server_racks', 'columns' => 'label:string,heightU:int', 'extras' => ['factory', 'migration']]);

    expect($app('make:model', [], registrations: registered($app), migrate: static function (string $name) use (&$migrations): int {
        $migrations[] = $name;

        return 0;
    }))->toBe(0, implode("\n", $app->err))
        ->and($app->files())->toContain('app/Models/Rack.php')->toContain('database/factories/RackFactory.php')
        ->and($app->read('app/Models/Rack.php'))->toContain("#[Table('server_racks')]")->toContain('public string $label')
        ->and($migrations)->toBe(['create_server_racks'])
        ->and(implode("\n", $app->out))->not->toContain('make:migration');
    $prompts->assertAsked('What is the model called?')->assertAsked('What else should be written with it?')->assertAllAnswered();
});

it('offers no migration where nothing could load the model to diff it, and says to make one', function () {
    $app = $this->app;
    Prompts::fake(['name' => 'Rack', 'extras' => ['migration']]);

    expect(fn () => $app('make:model', []))->toThrow(InvalidAnswer::class, "'migration', which is not one of 'factory'");

    Prompts::fake(['name' => 'Rack']);
    expect($app('make:model', ['--migration']))->toBe(0)
        ->and(implode("\n", $app->out))->toContain('`tinvar make:migration create_racks`');
});

it('asks only what is missing when given some of it: a page\'s path, an action\'s method', function () {
    $app = $this->app;
    $prompts = Prompts::fake(['path' => '/racks/{rack}/units', 'method' => 'moveUnit']);

    expect($app('make:page', ['Racks/Units']))->toBe(0, implode("\n", $app->err))
        ->and($app->read('app/Pages/Racks/Units.php'))->toContain("'/racks/{rack}/units'")
        ->and($app('make:action', ['RackActions']))->toBe(0, implode("\n", $app->err))
        ->and($app->read('app/Actions/RackActions.php'))->toContain('public function moveUnit(');
    $prompts->assertNotAsked('view')->assertNotAsked('model')->assertNotAsked('request')->assertAllAnswered();
    expect(array_column($prompts->asked(), 'name'))->toBe(['path', 'method']);
});

it('asks a query\'s model and an action\'s model and request from the app\'s models, or none', function () {
    $app = $this->app;
    expect($app('make:model', ['Rack']))->toBe(0);
    $models = [$app->class('Models\Rack')];
    Prompts::fake(['name' => 'Racks/Stats', 'model' => $app->class('Models\Rack'), 'class' => 'RackActions', 'method' => 'retire', 'request' => true]);

    expect($app('make:query', [], $models))->toBe(0, implode("\n", $app->err))
        ->and($app->read('app/Queries/Racks/Stats.php'))->toContain('Rack $rack')
        ->and($app('make:action', [], $models))->toBe(0, implode("\n", $app->err))
        ->and($app->files())->toContain('app/Requests/RetireRequest.php');

    Prompts::fake(['name' => 'Totals', 'model' => '']);
    expect($app('make:query', [], $models))->toBe(0)
        ->and(end($app->out))->toContain('Until a policy with a view() ability is registered');
});

it('holds each answer to what the argument would be', function () {
    $app = $this->app;
    $app('make:model', ['Rack']);
    $models = [$app->class('Models\Rack')];

    Prompts::fake(['name' => 'rack']);
    expect(fn () => $app('make:model', []))->toThrow(InvalidAnswer::class, "'rack' is not a model name");

    Prompts::fake(['path' => '/racks/{rackId}']);
    expect(fn () => $app('make:page', ['Racks/Show'], $models))->toThrow(InvalidAnswer::class, 'names Rack by its id');

    Prompts::fake(['name' => 'Shelf', 'columns' => 'id:string']);
    expect(fn () => $app('make:model', []))->toThrow(InvalidAnswer::class, 'which is the id');
});

it('asks on a terminal, the page\'s path filled in from its name', function () {
    $app = $this->app;
    $terminal = Prompts::fakeTerminal(['Racks/Show', Key::ENTER, Key::ENTER, Key::ENTER]);

    expect($app('make:page', []))->toBe(0, implode("\n", $app->err))
        ->and($app->read('app/Pages/Racks/Show.php'))->toContain("'/racks/show'")
        ->and($terminal->screen())->toContain('What is the page called?')->toContain('│ /racks/show')->toContain('│ racks/show');
});

it('asks nothing where nobody is there: a missing argument is the usage error it always was', function () {
    $app = $this->app;

    expect($app('make:model', []))->toBe(MakeCommand::USAGE)
        ->and($app->err)->toBe(['Usage: tinvar make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory] [--migration]'])
        ->and($app('make:page', ['Show']))->toBe(MakeCommand::USAGE);
});
