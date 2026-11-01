<?php

declare(strict_types=1);

/**
 * make:resource: a model and everything that lists, shows, makes, changes
 * and deletes its rows, written in one run -- and what it refuses.
 *
 * Each test generates into an application of its own, under a namespace no
 * other test uses, never into the shop. What is written is held to what
 * the shop's own code is held to: it parses, pint and prettier leave it as
 * it is, phpstan at level 8 finds nothing, it registers through the real
 * route, query and action registries and passes the build rules, its views
 * type-check against what codegen writes for it, and its migration makes
 * exactly the table make:migration would. And, generated into a copy of
 * the skeleton, the tests it writes pass through that app's own Kernel.
 */

namespace Tinvar\Tests\Generators\MakeResource;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\BuildRules;
use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Console\Prompts;
use Tinvar\Data\Data;
use Tinvar\Discovery\Discovery;
use Tinvar\Generators\AppLayout;
use Tinvar\Generators\MakeCommand;
use Tinvar\Generators\Registrations;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;

const ROOT = __DIR__ . '/../../../..';

/** The fields the tests make an order with: one of every kind. */
const ORDER_FIELDS = 'title:string,notes:?text,status:enum(Draft,InReview,Published),quantity:int,weight:?float,'
    . 'gift:bool,shippedAt:?timestamp,customer:belongsTo';

/** An application laid out by domain (ADR 0148), to generate into. */
final class App
{
    public readonly string $root;

    public readonly string $namespace;

    /** @var list<string> */
    public array $out = [];

    /** @var list<string> */
    public array $err = [];

    private readonly \Closure $autoload;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/tinvar-resource-' . bin2hex(random_bytes(6));
        $this->namespace = 'Res' . bin2hex(random_bytes(4));
        foreach (['app/Http', 'resources/pages', 'resources/types', 'resources/entries', 'database/migrations'] as $directory) {
            mkdir("{$this->root}/{$directory}", 0o755, true);
        }
        file_put_contents("{$this->root}/composer.json", json_encode(['autoload' => ['psr-4' => ["{$this->namespace}\\" => 'app/']]], JSON_UNESCAPED_SLASHES));
        $root = $this->root;
        $namespace = $this->namespace;
        $this->autoload = static function (string $class) use ($root, $namespace): void {
            if (str_starts_with($class, "{$namespace}\\")) {
                $file = "{$root}/app/" . str_replace('\\', '/', substr($class, \strlen($namespace) + 1)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    /**
     * Runs make:resource, or another generator, as bin/tinvar would: with
     * the app's models as discovery finds them, its route table, and its
     * migrations directory.
     *
     * @param list<string> $arguments
     */
    public function __invoke(array $arguments, string $command = 'make:resource'): int
    {
        $this->out = [];
        $this->err = [];
        $classes = $this->classes();

        return new MakeCommand(
            AppLayout::fromComposer($this->root, $this->namespace, "{$this->root}/resources/pages", "{$this->root}/resources/types"),
            $classes->models,
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            },
            new Registrations(is_file("{$this->root}/resources/entries/rsc.tsx") ? 'resources/entries/rsc.tsx' : null, $classes->routes),
            null,
            "{$this->root}/database/migrations",
        )->run($command, $arguments);
    }

    public function classes(): \Tinvar\Discovery\DiscoveredClasses
    {
        return new Discovery($this->namespace, ["{$this->namespace}\\" => ["{$this->root}/app"]], "{$this->root}/dist/discovery.php", $this->root)->scan();
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
        remove($this->root);
    }
}

function remove(string $directory): void
{
    if (is_link($directory)) {
        unlink($directory);

        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = (string) $item;
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }
    rmdir($directory);
}

/**
 * A tool's exit status and everything it printed.
 *
 * @param list<string> $command
 * @param array<string, string>|null $environment
 * @return array{int, string}
 */
function tool(array $command, ?string $cwd = null, ?array $environment = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? ROOT, $environment);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

/** A route table as an application keeps one, with no layouts, for the views to be registered in. */
function routeTable(App $app): void
{
    file_put_contents("{$app->root}/resources/entries/rsc.tsx", <<<'TSX'
        import type { ReactNode } from 'react';

        type Page = (props: { data: never; params: never }) => ReactNode;

        const ROUTES: Record<string, { Page: Page; layouts: readonly never[] }> = {
          // The pages.
        };

        export { ROUTES };

        TSX);
}

beforeEach(function () {
    $this->app = new App();
});

afterEach(function () {
    $this->app->remove();
    Prompts::restore();
});

it('writes a resource that lints, registers, passes the build rules, type-checks and migrates as make:migration would', function () {
    $app = $this->app;
    routeTable($app);

    expect($app(['Accounts/Customer', '--fields=name:string,email:string']))->toBe(0, implode("\n", $app->err))
        ->and($app(['Orders/Order', '--fields=' . ORDER_FIELDS]))->toBe(0, implode("\n", $app->err))
        // No line of text to name a row by, a relation that may be empty, and
        // names as long as they may be, which prettier breaks lines for.
        ->and($app(['Shipping/InternationalConsignment', '--fields=deliveredToTheRecipientAtTheirDoorstepOn:?timestamp,recipient:?belongsTo(Customer),'
            . 'insured:?bool,declaredValueInTheSmallestUnitOfCurrency:float,carrier:enum(PostOffice,Courier),handlingInstructionsForTheCourierTeam:?string']))->toBe(0, implode("\n", $app->err));

    $migrations = glob("{$app->root}/database/migrations/*.php") ?: [];
    expect($migrations)->toHaveCount(3);
    $files = array_values(array_filter($app->files(), static fn (string $file): bool => !str_starts_with($file, 'database/migrations/') && !str_contains($file, 'Consignment') && !str_contains($file, 'consignments/')));
    expect($files)->toBe([
        'app/Database/Factories/Accounts/CustomerFactory.php',
        'app/Database/Factories/Orders/OrderFactory.php',
        'app/Database/Models/Accounts/Customer.php',
        'app/Database/Models/Orders/Order.php',
        'app/Database/Models/Orders/OrderStatus.php',
        'app/Http/Actions/Accounts/CustomerActions.php',
        'app/Http/Actions/Orders/OrderActions.php',
        'app/Http/Data/Accounts/CustomerData.php',
        'app/Http/Data/Accounts/CustomerFormData.php',
        'app/Http/Data/Accounts/CustomerListData.php',
        'app/Http/Data/Accounts/CustomerOptionData.php',
        'app/Http/Data/Orders/OrderData.php',
        'app/Http/Data/Orders/OrderFormData.php',
        'app/Http/Data/Orders/OrderListData.php',
        'app/Http/Data/Orders/OrderOptionData.php',
        'app/Http/Pages/Accounts/CustomerCreate.php',
        'app/Http/Pages/Accounts/CustomerEdit.php',
        'app/Http/Pages/Accounts/CustomerFormOptions.php',
        'app/Http/Pages/Accounts/CustomerIndex.php',
        'app/Http/Pages/Accounts/CustomerShow.php',
        'app/Http/Pages/Orders/OrderCreate.php',
        'app/Http/Pages/Orders/OrderEdit.php',
        'app/Http/Pages/Orders/OrderFormOptions.php',
        'app/Http/Pages/Orders/OrderIndex.php',
        'app/Http/Pages/Orders/OrderShow.php',
        'app/Http/Queries/Accounts/ListCustomers.php',
        'app/Http/Queries/Accounts/ShowCustomer.php',
        'app/Http/Queries/Orders/ListOrders.php',
        'app/Http/Queries/Orders/ShowOrder.php',
        'app/Http/Requests/Accounts/SaveCustomerRequest.php',
        'app/Http/Requests/Orders/SaveOrderRequest.php',
        'app/Policies/Accounts/CustomerPolicy.php',
        'app/Policies/Orders/OrderPolicy.php',
        'composer.json',
        'resources/components/accounts/customers/CustomerDeleteForm.tsx',
        'resources/components/accounts/customers/CustomerForm.tsx',
        'resources/components/orders/OrderDeleteForm.tsx',
        'resources/components/orders/OrderForm.tsx',
        'resources/entries/rsc.tsx',
        'resources/pages/accounts/customers/create.tsx',
        'resources/pages/accounts/customers/edit.tsx',
        'resources/pages/accounts/customers/index.tsx',
        'resources/pages/accounts/customers/show.tsx',
        'resources/pages/orders/create.tsx',
        'resources/pages/orders/edit.tsx',
        'resources/pages/orders/index.tsx',
        'resources/pages/orders/show.tsx',
    ]);

    // The owner's shapes: a #[Table] model with a UUID key, CarbonImmutable
    // stamps and a backed enum; foreign(Model::class) and timestamp() in the
    // migration; every entry point naming the model or its row.
    $order = $app->read('app/Database/Models/Orders/Order.php');
    $migration = (string) file_get_contents((string) (glob("{$app->root}/database/migrations/*_create_orders.php") ?: [''])[0]);
    $parcels = (string) file_get_contents((string) (glob("{$app->root}/database/migrations/*_create_international_consignments.php") ?: [''])[0]);
    expect($order)->toContain("#[Table('orders')]")
        ->and($order)->toContain('#[Id] public private(set) string $id;')
        ->and($order)->toContain('#[Column] public OrderStatus $status;')
        ->and($order)->toContain('#[Column] public ?CarbonImmutable $shippedAt = null;')
        ->and($order)->toContain('#[BelongsTo] public Customer $customer;')
        ->and($order)->toContain('#[Column] public CarbonImmutable $createdAt;')
        ->and($app->read('app/Database/Models/Orders/OrderStatus.php'))->toContain("case InReview = 'in_review';")
        ->and($migration)->toContain('$table->foreign(Customer::class);')
        ->and($migration)->toContain("\$table->timestamp('shipped_at')->nullable();")
        ->and($migration)->toContain("\$table->enum('status', OrderStatus::class);")
        ->and($migration)->toContain('$table->timestamps();')
        ->and($parcels)->toContain("\$table->foreign(Customer::class, 'recipient_id')->nullable()->nullOnDelete();")
        ->and($app->read('app/Http/Pages/Orders/OrderShow.php'))->toContain("#[Page('/orders/{order}', view: 'orders/show', name: 'orders.show'), Authorize('view', 'order')]")
        ->and($app->read('app/Http/Pages/Orders/OrderCreate.php'))->toContain("Authorize('viewAny', Customer::class)")
        ->and($app->read('app/Http/Queries/Orders/ListOrders.php'))->toContain("#[Query, Authorize('viewAny', Order::class)]")
        ->and($app->read('app/Http/Actions/Orders/OrderActions.php'))->toContain("#[Action, Authorize('create', Order::class), Authorize('view', 'customer')]")
        ->and($app->read('app/Http/Actions/Orders/OrderActions.php'))->toContain("#[Action, Authorize('delete', 'order')]")
        ->and($app->read('app/Policies/Orders/OrderPolicy.php'))->toContain('#[Policy(Order::class)]')
        ->and($app->read('resources/pages/orders/index.tsx'))->toContain("from '@meridian/ui';")
        ->and($app->read('resources/entries/rsc.tsx'))->toContain("  'orders/edit': { Page: OrdersEditPage as Page, layouts: [] },")
        ->and($app->out)->toContain('No test was written: the app has no tests/ directory, or no Kernel::fromEnvironment(database:, telemetry:) and Database\Migrations to build one with, as the skeleton has.');

    foreach ($app->files() as $file) {
        if (str_ends_with($file, '.php')) {
            [$status, $output] = tool([PHP_BINARY, '-l', "{$app->root}/{$file}"]);
            expect($status)->toBe(0, $output);
        }
    }
    [$status, $output] = tool([ROOT . '/vendor/bin/pint', '--test', '--config', ROOT . '/pint.json', "{$app->root}/app", "{$app->root}/database"]);
    expect($status)->toBe(0, $output);
    [$status, $output] = tool([ROOT . '/node_modules/.bin/prettier', '--check', '--config', ROOT . '/.prettierrc.json', "{$app->root}/resources/pages", "{$app->root}/resources/components"]);
    expect($status)->toBe(0, $output);
    file_put_contents("{$app->root}/phpstan.neon", "parameters:\n    level: 8\n    paths:\n        - app\n    tmpDir: .phpstan\n");
    [$status, $output] = tool([
        ROOT . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G',
        '-c', "{$app->root}/phpstan.neon", '--autoload-file=' . ROOT . '/vendor/autoload.php',
    ]);
    expect($status)->toBe(0, $output);

    // Registered by being there (ADR 0146), through the real registries,
    // which run the build rules over each.
    $classes = $app->classes();
    $routes = new RouteRegistry($classes->models);
    $routes->registerAll($classes->routes);
    $actions = new ActionRegistry(models: $classes->models);
    $actions->registerAll($classes->actions);
    expect(array_map(static fn ($page): string => $page->template, $routes->pages()))->toContain('/orders', '/orders/new', '/orders/{order}', '/orders/{order}/edit')
        ->and((new QueryRegistry())->registerAll($classes->queries))->toBe(['accounts.listCustomers', 'accounts.showCustomer', 'orders.listOrders', 'orders.showOrder', 'shipping.listInternationalConsignments', 'shipping.showInternationalConsignment'])
        ->and(array_map(static fn ($action): string => $action->name, $actions->all()))->toContain('OrderActions.create', 'OrderActions.update', 'OrderActions.destroy')
        ->and(array_keys($classes->policies))->toBe([$app->class('Database\Models\Accounts\Customer'), $app->class('Database\Models\Orders\Order'), $app->class('Database\Models\Shipping\InternationalConsignment')])
        ->and((new BuildRules($classes->models))->check([...$classes->routes, ...$classes->queries, ...$classes->actions], $classes->payloads))->toBe([]);

    // The views and components type-check against what codegen writes.
    $types = (new TypeScriptGenerator())->files(
        dataClasses: array_values(array_filter($classes->payloads, static fn (string $class): bool => is_subclass_of($class, Data::class))),
        queryClasses: $classes->queries,
        routes: array_map(RouteTypes::of(...), [...$routes->layouts(), ...$routes->pages()]),
        actions: $actions->all(),
    );
    mkdir("{$app->root}/resources/types/actions");
    file_put_contents("{$app->root}/resources/types/generated.ts", $types['module']);
    file_put_contents("{$app->root}/resources/types/modules.d.ts", $types['ambient']);
    foreach ($types['actions'] as $class => $contents) {
        file_put_contents("{$app->root}/resources/types/actions/{$class}.ts", $contents);
    }
    // The workspace's packages, as the app's own node_modules would hold them.
    symlink((string) realpath(ROOT . '/node_modules'), "{$app->root}/node_modules");
    file_put_contents("{$app->root}/tsconfig.json", json_encode([
        'extends' => (string) realpath(ROOT . '/tsconfig.base.json'),
        'compilerOptions' => ['noEmit' => true, 'types' => ['react', 'react-dom'], 'paths' => ['@actions/*' => ['./resources/types/actions/*']]],
        'include' => ['resources/pages/**/*.tsx', 'resources/components/**/*.tsx', 'resources/types/**/*.ts'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    [$status, $output] = tool([ROOT . '/node_modules/.bin/tsc', '-p', "{$app->root}/tsconfig.json", '--pretty', 'false']);
    expect($status)->toBe(0, $output);

    // The migrations make the tables, and make:migration finds nothing
    // the models say that they did not make.
    $connection = PdoConnection::sqlite("{$app->root}/database.sqlite", create: true);
    (new Migrator($connection, "{$app->root}/database/migrations"))->migrate();
    $generator = new MigrationGenerator($connection, Dialect::for($connection));
    expect($generator->generate($classes->models, '2026-11-01'))->toBeNull(implode("\n", $generator->warnings));
});

// The skeleton, copied and given two resources, runs the tests make:resource
// wrote for them through its own Kernel, in a process of its own: the
// skeleton's App\ namespace, loaded there and nowhere else.
it('writes tests that pass through the skeleton\'s own Kernel', function () {
    $skeleton = ROOT . '/packages/skeleton';
    if (!is_dir("{$skeleton}/app")) {
        $this->markTestSkipped('The skeleton is not beside this package.');
    }
    // Under the workspace, where Pest names a test file's class by its path
    // from the workspace -- a temporary directory's may not make one.
    $work = ROOT . '/.phpunit.cache/make-resource-' . bin2hex(random_bytes(6));
    mkdir($work, 0o755, true);
    try {
        foreach (['app', 'database', 'resources', 'tests'] as $directory) {
            copyTree("{$skeleton}/{$directory}", "{$work}/{$directory}");
        }
        copy("{$skeleton}/composer.json", "{$work}/composer.json");
        foreach (glob("{$work}/database/*.sqlite*") ?: [] as $file) {
            unlink($file);
        }
        $root = (string) realpath(ROOT);
        file_put_contents("{$work}/bootstrap.php", <<<PHP
            <?php

            require '{$root}/vendor/autoload.php';
            spl_autoload_register(static function (string \$class): void {
                if (str_starts_with(\$class, 'App\\\\')) {
                    \$file = '{$work}/app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                    if (is_file(\$file)) {
                        require \$file;
                    }
                }
            });

            PHP);
        file_put_contents("{$work}/generate.php", <<<PHP
            <?php

            require '{$work}/bootstrap.php';
            [, \$name, \$fields] = \$argv;
            \$classes = (new Tinvar\\Discovery\\Discovery('App', ['App\\\\' => ['{$work}/app']], '{$work}/dist/discovery.php', '{$work}'))->scan();
            \$layout = Tinvar\\Generators\\AppLayout::fromComposer('{$work}', 'App', '{$work}/resources/pages', '{$work}/resources/types');
            \$registrations = new Tinvar\\Generators\\Registrations('resources/entries/rsc.tsx', \$classes->routes);
            exit((new Tinvar\\Generators\\MakeCommand(\$layout, \$classes->models, registrations: \$registrations, migrations: '{$work}/database/migrations'))->run('make:resource', [\$name, "--fields={\$fields}"]));

            PHP);
        file_put_contents("{$work}/phpunit.xml", <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="{$work}/tests/bootstrap.php" cacheDirectory="{$work}/.phpunit.cache">
              <testsuites><testsuite name="app"><directory>{$work}/tests/Feature</directory></testsuite></testsuites>
            </phpunit>
            XML);
        file_put_contents("{$work}/tests/bootstrap.php", "<?php\n\nrequire '{$work}/bootstrap.php';\nrequire '{$work}/tests/Pest.php';\n");

        foreach ([['Accounts/Customer', 'name:string,email:string'], ['Orders/Order', ORDER_FIELDS]] as [$name, $fields]) {
            [$status, $output] = tool([PHP_BINARY, "{$work}/generate.php", $name, $fields]);
            expect($status)->toBe(0, $output)
                ->and($output)->toContain('wrote tests/Feature/' . explode('/', $name)[0] . '/' . explode('/', $name)[1] . 'ResourceTest.php');
        }

        [$status, $output] = tool([PHP_BINARY, "{$root}/vendor/bin/pest", "--configuration={$work}/phpunit.xml", '--colors=never'], $root);
        expect($status)->toBe(0, $output)
            ->and($output)->toContain('OrderResourceTest')
            ->and($output)->toContain('it changes one');
    } finally {
        remove($work);
    }
});

it('refuses, with nobody to ask, to guess the fields, and writes nothing', function () {
    $app = $this->app;

    expect($app(['Orders/Order']))->toBe(MakeCommand::USAGE)
        ->and(implode("\n", $app->err))->toContain('make:resource needs its fields. Pass them, as in --fields=')
        ->and($app->files())->toBe(['composer.json']);
});

it('refuses fields that are not ones, before writing anything', function (string $fields, string $says) {
    $app = $this->app;
    expect($app(['Accounts/Customer', '--fields=name:string']))->toBe(0, implode("\n", $app->err));
    $before = $app->files();

    expect($app(['Orders/Order', "--fields={$fields}"]))->toBe(MakeCommand::USAGE)
        ->and(implode("\n", $app->err))->toContain($says)
        ->and($app->files())->toBe($before);
})->with([
    'an unknown type' => ['title:varchar', "'varchar' is not a type a field may be"],
    'a written field' => ['createdAt:timestamp', 'which every resource has already'],
    'a field twice' => ['title:string,title:text', 'names title twice'],
    'an empty enum' => ['status:enum()', 'is an enum of no values'],
    'a case that is no name' => ['status:enum(draft)', "'draft' is not a case status may take"],
    'a relation to no model' => ['owner:belongsTo', "names 'owner', which is not one of the application's models"],
    'a relation by its key' => ['customerId:belongsTo(Customer)', 'Name it customer:belongsTo'],
    'two fields in one column' => ['customer:belongsTo,customerId:string', 'would both be stored in the column customer_id'],
    'a quote in a name' => ["title':string", "is not a column's property name"],
    'an unclosed enum' => ['status:enum(Draft', 'leaves a parenthesis open'],
]);

it('refuses a resource whose model, table or files are there already, writing nothing', function () {
    $app = $this->app;
    expect($app(['Accounts/Customer', '--fields=name:string']))->toBe(0, implode("\n", $app->err));
    $before = $app->files();

    expect($app(['Billing/Customer', '--fields=name:string']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain('The app has a model named Customer already')
        ->and($app->files())->toBe($before);

    // A view that is in the way: nothing else is written either.
    mkdir("{$app->root}/resources/pages/orders", 0o755, true);
    file_put_contents("{$app->root}/resources/pages/orders/show.tsx", "export default null;\n");
    $before = $app->files();
    expect($app(['Orders/Order', '--fields=title:string']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain('resources/pages/orders/show.tsx already exists')
        ->and($app->files())->toBe($before);
});

it('refuses a resource whose routes another page already answers or is named', function () {
    $app = $this->app;
    mkdir("{$app->root}/app/Http/Pages", 0o755, true);
    file_put_contents("{$app->root}/app/Http/Pages/Orders.php", "<?php\n\nnamespace {$app->namespace}\\Http\\Pages;\n\nuse Tinvar\\Attributes\\NoAuthorization;\nuse Tinvar\\Attributes\\Page;\n\n#[Page('/orders/{id}', view: 'legacy/orders'), NoAuthorization(reason: 'a fixture')]\nfinal class Orders {}\n");
    $before = $app->files();

    expect($app(['Orders/Order', '--fields=title:string']))->toBe(MakeCommand::FAILED)
        ->and(implode("\n", $app->err))->toContain("{$app->namespace}\\Http\\Pages\\Orders already answers /orders/{id}")
        ->and($app->files())->toBe($before);

    // Somewhere else, with --path, it is the app's.
    expect($app(['Orders/Order', '--fields=title:string', '--path=/admin/orders']))->toBe(0, implode("\n", $app->err))
        ->and($app->read('app/Http/Pages/Orders/OrderEdit.php'))->toContain("#[Page('/admin/orders/{order}/edit'");
});

it('asks for the name and each field, one at a time, where a person is there', function () {
    $app = $this->app;
    expect($app(['Accounts/Customer', '--fields=name:string']))->toBe(0, implode("\n", $app->err));

    $prompts = Prompts::fake([
        'Orders/Order',
        'title', 'string', false,
        'status', 'enum', 'Draft,Published', false,
        'customer', 'belongsTo', $app->class('Database\Models\Accounts\Customer'), true,
        '',
    ]);

    expect($app([]))->toBe(0, implode("\n", $app->err));
    $prompts->assertAsked('What is the resource called?')
        ->assertAsked('What is its first field called?')
        ->assertAsked('Which values may status take?')
        ->assertAsked('Which model does customer belong to?');
    expect($app->read('app/Database/Models/Orders/Order.php'))->toContain("#[BelongsTo(onDelete: 'set null')] public ?Customer \$customer;")
        ->and($app->read('app/Database/Models/Orders/OrderStatus.php'))->toContain("case Published = 'published';");
});

// bin/tinvar hands the generators the shop. Only a refusal is run here: a
// test never writes into the shop's committed tree.
it('is wired into bin/tinvar, against the shop, refusing what is there', function () {
    [$status, $output] = tool([PHP_BINARY, ROOT . '/bin/tinvar', 'make:resource', 'Catalog/Product', '--fields=name:string']);
    expect($status)->toBe(MakeCommand::FAILED)
        ->and($output)->toContain('The app has a model named Product already');

    [, $output] = tool([PHP_BINARY, ROOT . '/bin/tinvar', 'help']);
    expect($output)->toContain('make:resource <Name> --fields=');
});

/** Copies a directory, its files and those below it. */
function copyTree(string $from, string $to): void
{
    mkdir($to, 0o755, true);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . substr((string) $item, \strlen($from));
        $item->isDir() ? mkdir($target, 0o755, true) : copy((string) $item, $target);
    }
}
