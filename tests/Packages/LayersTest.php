<?php

declare(strict_types=1);

/**
 * The layer rule: support uses nothing of Tinvar's, the database uses only
 * support, the framework uses either, and the testing layer and codegen all
 * three, but not each other (ADR 0054). Each layer is a package, and a file
 * is in the layer of the package whose directory holds it (ADR 0055).
 *
 * Every way PHP lets a file name a class counts -- an import, a fully
 * qualified name, a name through an alias or below the namespace, a
 * `::class`, a class name in a string -- and so does a qualified name in a
 * comment or docblock.
 */

namespace Tinvar\Tests\Packages\Layers;

use Tinvar\Packages\Layers;
use Tinvar\Packages\SourceReferences;

/** The core's layers, lowest first (ADR 0114). */
const CORE = ['http', 'auth', 'data', 'reads', 'render', 'identity', 'entry', 'requests', 'operations'];

/** The framework's namespaces, as check() finds them, for comments' shortened names. */
const NAMESPACES = ['Tinvar', 'Tinvar\Orm', 'Tinvar\Orm\Schema', 'Tinvar\Http', 'Tinvar\Live', 'Tinvar\Testing', 'Tinvar\Tenancy', 'Tinvar\Support'];

/** @return list<string> */
function crossed(string $source): array
{
    return Layers::framework()->checkSource($source, 'Example.php', NAMESPACES);
}

/**
 * A map with a namespace shared class by class, as the framework's was
 * before its packages were split: the map can still say so.
 */
function shared(): Layers
{
    return new Layers(
        ['database' => ['Tinvar\\Orm\\', 'Tinvar\\Attributes\\Table', 'Tinvar\\Attributes\\Column'], 'framework' => ['Tinvar\\']],
        ['framework' => ['database']],
    );
}

/**
 * A workspace of files under a temporary root, read by $layers' check().
 *
 * @param array<string, string> $files path => contents
 * @return list<string>
 */
function checked(Layers $layers, array $files): array
{
    $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
    foreach ($files as $path => $contents) {
        if (!is_dir(\dirname("{$root}/{$path}"))) {
            mkdir(\dirname("{$root}/{$path}"), 0o777, true);
        }
        file_put_contents("{$root}/{$path}", $contents);
    }

    try {
        return $layers->check($root);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
}

/** A file in the database layer, with $body in its class. */
function orm(string $imports = '', string $body = '', string $docblock = ''): string
{
    return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Tinvar\\Orm;

        {$imports}

        {$docblock}
        final class Example
        {
            {$body}
        }
        PHP;
}

describe('the layers', function () {
    it('passes the repository as it is', function () {
        expect(Layers::framework()->check(\dirname(__DIR__, 4)))->toBe([]);
    });

    it('puts a class in the layer of its most specific member', function (string $name, ?string $layer) {
        expect(Layers::framework()->layerOf($name))->toBe($layer);
    })->with([
        'the ORM' => ['Tinvar\Orm\Orm', 'database'],
        'below the ORM' => ['Tinvar\Orm\Schema\Table', 'database'],
        'an attribute the ORM reads' => ['Tinvar\Orm\Attributes\Table', 'database'],
        'its name before the split' => ['Tinvar\Attributes\Table', 'framework'],
        'an attribute it does not read' => ['Tinvar\Attributes\Panel', 'framework'],
        'the container' => ['Tinvar\Container\Container', 'support'],
        'its attribute' => ['Tinvar\Container\Dependency', 'support'],
        'a lazy value' => ['Tinvar\Support\Lazy', 'support'],
        'Data' => ['Tinvar\Data\Mapper', 'framework'],
        'the tenant walk' => ['Tinvar\Tenancy\TenantHierarchy', 'tenancy'],
        'the tree port the ORM bounds' => ['Tinvar\Orm\TenantBounds', 'database'],
        'tenancy' => ['Tinvar\Tenancy\TenantHosts', 'tenancy'],
        'the port tenancy implements' => ['Tinvar\Http\TenantResolver', 'framework'],
        'codegen' => ['Tinvar\Codegen\TypeScriptGenerator', 'codegen'],
        'the testing layer' => ['Tinvar\Testing\ModelLeaks', 'testing'],
        'a namespace by its name' => ['Tinvar\Orm', 'database'],
        'a leading backslash' => ['\Tinvar\Orm\Model', 'database'],
        'in any case' => ['tinvar\orm\model', 'database'],
        'a name that only starts like a member' => ['Tinvar\Ormish\Thing', 'framework'],
        'a class that only starts like a member' => ['Tinvar\Data\LazyList', 'framework'],
        'not Tinvar' => ['Shop\Database\Models\Catalog\Product', null],
        'nothing' => ['PDO', null],
    ]);

    it('lets a layer use itself and the layers below it it names, never one above or beside it', function () {
        $layers = Layers::framework();

        expect($layers->names())->toBe(['support', 'database', 'framework', 'tenancy', 'billing', 'testing', 'codegen'])
            ->and($layers->mayUse('database', 'support'))->toBeTrue()
            ->and($layers->mayUse('database', 'database'))->toBeTrue()
            ->and($layers->mayUse('framework', 'database'))->toBeTrue()
            ->and($layers->mayUse('testing', 'framework'))->toBeTrue()
            ->and($layers->mayUse('codegen', 'support'))->toBeTrue()
            ->and($layers->mayUse('database', 'framework'))->toBeFalse()
            ->and($layers->mayUse('support', 'database'))->toBeFalse()
            ->and($layers->mayUse('framework', 'testing'))->toBeFalse()
            ->and($layers->mayUse('framework', 'codegen'))->toBeFalse()
            ->and($layers->mayUse('codegen', 'testing'))->toBeFalse()
            ->and($layers->mayUse('testing', 'codegen'))->toBeFalse()
            ->and($layers->mayUse('tenancy', 'framework'))->toBeTrue()
            ->and($layers->mayUse('tenancy', 'database'))->toBeTrue()
            ->and($layers->mayUse('framework', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('database', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('testing', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('codegen', 'tenancy'))->toBeFalse()
            ->and($layers->mayUse('tenancy', 'testing'))->toBeFalse()
            ->and($layers->mayUse('database', 'nowhere'))->toBeFalse()
            ->and($layers->mayUse('nowhere', 'nowhere'))->toBeFalse();
    });

    it('refuses a map in which a layer would use one not below it', function (array $uses, string $message) {
        expect(fn () => new Layers(['low' => ['A\\'], 'high' => ['B\\']], $uses))
            ->toThrow(\InvalidArgumentException::class, $message);
    })->with([
        'one above' => [['low' => ['high']], "Layer 'low' may use only layers listed before it, and 'high' is not one."],
        'itself' => [['low' => ['low']], "Layer 'low' may use only layers listed before it, and 'low' is not one."],
        'none there is' => [['high' => ['middle']], "Layer 'high' may use only layers listed before it, and 'middle' is not one."],
        'for none there is' => [['middle' => ['low']], 'No such layer: middle.'],
    ]);
});

// ADR 0077: the framework's own package, held apart into modules.
describe('the modules', function () {
    it('passes the framework as it is', function () {
        expect(Layers::modules()->check(\dirname(__DIR__, 4)))->toBe([]);
    });

    it('reads the framework\'s package alone', function () {
        expect(Layers::modules()->sources())->toBe(['packages/framework/src' => 'Tinvar\\'])
            ->and(Layers::modules()->packages())->toBe([]);
    });

    it('puts a class in its module, an attribute in the module it belongs to', function (string $name, string $module) {
        expect(Layers::modules()->layerOf($name))->toBe($module);
    })->with([
        'the queue' => ['Tinvar\Queue\DatabaseQueue', 'queue'],
        'the worker, which runs jobs' => ['Tinvar\Jobs\Worker', 'jobs'],
        'the sync queue, which runs them too' => ['Tinvar\Jobs\SyncQueue', 'jobs'],
        'the dispatcher' => ['Tinvar\Jobs\Dispatcher', 'jobs'],
        '#[Queue]' => ['Tinvar\Attributes\Queue', 'jobs'],
        'the event bus' => ['Tinvar\Events\Events', 'events'],
        '#[Listener]' => ['Tinvar\Attributes\Listener', 'events'],
        'the mailer' => ['Tinvar\Mail\Mailer', 'mail'],
        'the webhook endpoint' => ['Tinvar\Webhooks\WebhookEndpoint', 'webhooks'],
        '#[Webhook]' => ['Tinvar\Attributes\Webhook', 'webhooks'],
        'an export\'s runner' => ['Tinvar\Transfers\TransferRunner', 'transfers'],
        '#[Export], which the build rules read' => ['Tinvar\Attributes\Export', 'data'],
        'the user lookup, which the handoff asks too' => ['Tinvar\Auth\UserLookup', 'auth'],
        'the nonce store, which the handoff claims in too' => ['Tinvar\Http\DatabaseNonceStore', 'http'],
        'the request attributes' => ['Tinvar\Http\RequestAttributes', 'http'],
        'the HTTP kernel' => ['Tinvar\Http\Kernel', 'kernel'],
        'what an application shows the testing layer' => ['Tinvar\Http\TestableApplication', 'kernel'],
        'the console' => ['Tinvar\Console\Console', 'kernel'],
        'a cron expression, which #[Schedule] is written in' => ['Tinvar\Console\Cron', 'http'],
        '#[Schedule], which tasks anywhere carry' => ['Tinvar\Attributes\Schedule', 'data'],
        'the server' => ['Tinvar\Server\ServeCommand', 'kernel'],
        'the ORM' => ['Tinvar\Orm\Orm', 'database'],
        'tenancy' => ['Tinvar\Tenancy\TenantHosts', 'tenancy'],
    ]);

    it('lets a module use the core and jobs, never one beside it, and only the kernel use them all', function () {
        $modules = Layers::modules();

        expect($modules->names())->toBe(['support', 'database', 'queue', ...CORE, 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'search', 'notifications', 'calls', 'tools', 'services', 'gateway', 'api', 'audit', 'accounts', 'kernel', 'tenancy', 'billing', 'testing', 'codegen'])
            ->and($modules->mayUse('queue', 'database'))->toBeTrue()
            ->and($modules->mayUse('queue', 'http'))->toBeFalse()
            ->and($modules->mayUse('queue', 'jobs'))->toBeFalse()
            ->and($modules->mayUse('http', 'queue'))->toBeTrue()
            ->and($modules->mayUse('operations', 'jobs'))->toBeFalse()
            ->and($modules->mayUse('requests', 'kernel'))->toBeFalse()
            ->and($modules->mayUse('jobs', 'queue'))->toBeTrue()
            ->and($modules->mayUse('jobs', 'http'))->toBeTrue()
            ->and($modules->mayUse('jobs', 'operations'))->toBeTrue()
            ->and($modules->mayUse('jobs', 'events'))->toBeFalse()
            ->and($modules->mayUse('events', 'jobs'))->toBeTrue()
            ->and($modules->mayUse('mail', 'jobs'))->toBeTrue()
            ->and($modules->mayUse('mail', 'webhooks'))->toBeFalse()
            ->and($modules->mayUse('webhooks', 'mail'))->toBeFalse()
            ->and($modules->mayUse('webhooks', 'events'))->toBeFalse()
            ->and($modules->mayUse('transfers', 'mail'))->toBeFalse()
            // ADR 0153: the accounts kit on jobs and mail, and the API whose
            // tokens it issues to a confirmed session, and nothing beside them.
            ->and($modules->mayUse('accounts', 'mail'))->toBeTrue()
            ->and($modules->mayUse('accounts', 'jobs'))->toBeTrue()
            ->and($modules->mayUse('accounts', 'api'))->toBeTrue()
            ->and($modules->mayUse('api', 'accounts'))->toBeFalse()
            ->and($modules->mayUse('accounts', 'webhooks'))->toBeFalse()
            ->and($modules->mayUse('accounts', 'kernel'))->toBeFalse()
            ->and($modules->mayUse('mail', 'accounts'))->toBeFalse()
            // ADR 0162: and on the audit log, where it records sign-ins; never the other way.
            ->and($modules->mayUse('accounts', 'audit'))->toBeTrue()
            ->and($modules->mayUse('audit', 'accounts'))->toBeFalse()
            // ADR 0098, 0099: workflows on jobs; tools and services on calls,
            // and neither on the other; the gateway's contract on the core.
            ->and($modules->mayUse('workflows', 'jobs'))->toBeTrue()
            ->and($modules->mayUse('workflows', 'tools'))->toBeFalse()
            ->and($modules->mayUse('calls', 'requests'))->toBeTrue()
            ->and($modules->mayUse('calls', 'jobs'))->toBeFalse()
            ->and($modules->mayUse('calls', 'tools'))->toBeFalse()
            ->and($modules->mayUse('tools', 'calls'))->toBeTrue()
            ->and($modules->mayUse('tools', 'services'))->toBeFalse()
            ->and($modules->mayUse('services', 'tools'))->toBeFalse()
            ->and($modules->mayUse('gateway', 'jobs'))->toBeFalse()
            // ADR 0159: the API's tokens and calls on calls, beside the
            // tools and services, and the kernel over them.
            ->and($modules->mayUse('api', 'calls'))->toBeTrue()
            ->and($modules->mayUse('api', 'services'))->toBeFalse()
            ->and($modules->mayUse('services', 'api'))->toBeFalse()
            ->and($modules->mayUse('entry', 'api'))->toBeFalse()
            ->and($modules->mayUse('kernel', 'api'))->toBeTrue()
            ->and($modules->mayUse('kernel', 'transfers'))->toBeTrue()
            ->and($modules->mayUse('kernel', 'webhooks'))->toBeTrue()
            ->and($modules->mayUse('push', 'jobs'))->toBeTrue()
            ->and($modules->mayUse('push', 'webhooks'))->toBeFalse()
            ->and($modules->mayUse('notifications', 'mail'))->toBeTrue()
            ->and($modules->mayUse('notifications', 'push'))->toBeTrue()
            ->and($modules->mayUse('mail', 'notifications'))->toBeFalse()
            ->and($modules->mayUse('kernel', 'push'))->toBeTrue()
            ->and($modules->mayUse('kernel', 'tenancy'))->toBeFalse();
    });

    it('fails the core naming a module, a module naming one beside it, and the queue naming jobs', function () {
        $file = static fn (string $namespace, string $class, string $line): string => "<?php\nnamespace {$namespace};\n\n{$line}\n\nfinal class {$class} {}\n";
        $crossed = static fn (string $source): array => Layers::modules()->checkSource($source, 'Example.php');

        expect($crossed($file('Tinvar\\Identity', 'Example', 'use Tinvar\\Jobs\\JobRunner;')))->toBe(['Example.php:4: identity uses jobs, Tinvar\Jobs\JobRunner (use)'])
            ->and($crossed($file('Tinvar\\Http', 'Example', 'use Tinvar\\Webhooks\\WebhookEndpoint;')))->toBe(['Example.php:4: http uses webhooks, Tinvar\Webhooks\WebhookEndpoint (use)'])
            ->and($crossed($file('Tinvar\\Actions', 'Example', 'use Tinvar\\Http\\Kernel;')))->toBe(['Example.php:4: entry uses kernel, Tinvar\Http\Kernel (use)'])
            ->and($crossed($file('Tinvar\\Render', 'Example', "const BUS = 'Tinvar\\\\Events\\\\';")))->toBe(['Example.php:4: render uses events, Tinvar\Events (string)'])
            ->and($crossed($file('Tinvar\\Queue', 'Example', 'use Tinvar\\Jobs\\Envelope;')))->toBe(['Example.php:4: queue uses jobs, Tinvar\Jobs\Envelope (use)'])
            ->and($crossed($file('Tinvar\\Queue', 'Example', 'use Tinvar\\Http\\RequestScope;')))->toBe(['Example.php:4: queue uses requests, Tinvar\Http\RequestScope (use)'])
            ->and($crossed($file('Tinvar\\Mail', 'Example', 'use Tinvar\\Webhooks\\OutboundWebhooks;')))->toBe(['Example.php:4: mail uses webhooks, Tinvar\Webhooks\OutboundWebhooks (use)'])
            ->and($crossed($file('Tinvar\\Jobs', 'Example', 'use Tinvar\\Events\\Events;')))->toBe(['Example.php:4: jobs uses events, Tinvar\Events\Events (use)'])
            ->and($crossed($file('Tinvar\\Attributes', 'Schedule', 'use Tinvar\\Console\\Console;')))->toBe(['Example.php:4: data uses kernel, Tinvar\Console\Console (use)'])
            ->and($crossed($file('Tinvar\\Mail', 'Example', 'use Tinvar\\Jobs\\Dispatcher;')))->toBe([])
            ->and($crossed($file('Tinvar\\Http', 'Kernel', 'use Tinvar\\Webhooks\\WebhookEndpoint;')))->toBe([])
            ->and($crossed($file('Tinvar\\Observability', 'Example', 'use Tinvar\\Queue\\Queue;')))->toBe([]);
    });
});

// ADR 0114: the core, held apart into layers inside the framework.
describe('the core', function () {
    it('puts each class in its layer, by its namespace or by its own name', function (string $name, string $layer) {
        expect(Layers::modules()->layerOf($name))->toBe($layer);
    })->with([
        'a request' => ['Tinvar\Http\Request', 'http'],
        'the middleware interface, a class named like a namespace' => ['Tinvar\Http\Middleware', 'http'],
        'the endpoints\' limits' => ['Tinvar\Http\Endpoints\Limits', 'http'],
        'an attribute the gates read' => ['Tinvar\Attributes\Authorize', 'http'],
        'a namespace no layer names' => ['Tinvar\Shiny\Thing', 'http'],
        'the user asking' => ['Tinvar\Auth\AuthContext', 'auth'],
        'middleware reading it' => ['Tinvar\Http\Middleware\RequireAuthentication', 'auth'],
        'the throttle, keyed by it' => ['Tinvar\Http\Throttle', 'auth'],
        'Data' => ['Tinvar\Data\Data', 'data'],
        'config' => ['Tinvar\Config\FrameworkConfig', 'data'],
        'the hub\'s URL' => ['Tinvar\Config\HubUrl', 'data'],
        'the file types, moved to storage' => ['Tinvar\Storage\FileTypes', 'data'],
        'the head tag, moved to the protocol' => ['Tinvar\Protocol\HeadTag', 'data'],
        'a signed token' => ['Tinvar\Render\SignedToken', 'data'],
        'an attribute with no layer of its own' => ['Tinvar\Attributes\Page', 'data'],
        'the page registry' => ['Tinvar\Routing\RouteRegistry', 'reads'],
        'the build rules' => ['Tinvar\Auth\BuildRules', 'reads'],
        'the query cache' => ['Tinvar\Cache\QueryCache', 'reads'],
        'the renderer\'s channel' => ['Tinvar\Render\RenderChannel', 'render'],
        'telemetry' => ['Tinvar\Observability\Telemetry', 'render'],
        'where work runs as a span, which a loader and a call out are given' => ['Tinvar\Observability\Spans', 'data'],
        'the realms' => ['Tinvar\Identity\Realms', 'identity'],
        'the action runner' => ['Tinvar\Actions\ActionRunner', 'entry'],
        'the entry checks' => ['Tinvar\Http\EntryContext', 'entry'],
        'the page router' => ['Tinvar\Routing\Router', 'entry'],
        'the fragment endpoint' => ['Tinvar\Http\Endpoints\FragmentEndpoint', 'requests'],
        'the layer rule' => ['Tinvar\Packages\Layers', 'operations'],
    ]);

    it('lets each layer use those below it and none above, and every module use them all', function () {
        $modules = Layers::modules();

        foreach (CORE as $i => $layer) {
            foreach (CORE as $j => $other) {
                expect($modules->mayUse($layer, $other))->toBe($j <= $i, "{$layer} using {$other}");
            }
            foreach (['support', 'database', 'queue'] as $below) {
                expect($modules->mayUse($layer, $below))->toBeTrue();
            }
            foreach (['jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'search', 'notifications', 'calls', 'tools', 'services', 'gateway', 'kernel'] as $module) {
                expect($modules->mayUse($module, $layer))->toBeTrue("{$module} using {$layer}")
                    ->and($modules->mayUse($layer, $module))->toBeFalse("{$layer} using {$module}");
            }
        }
    });

    it('fails a layer naming one above it, in code or in a comment', function () {
        $file = static fn (string $namespace, string $line): string => "<?php\nnamespace {$namespace};\n\n{$line}\n\nfinal class Example {}\n";
        $crossed = static fn (string $source): array => Layers::modules()->checkSource($source, 'Example.php');

        expect($crossed($file('Tinvar\\Http', 'use Tinvar\\Auth\\AuthContext;')))->toBe(['Example.php:4: http uses auth, Tinvar\Auth\AuthContext (use)'])
            ->and($crossed($file('Tinvar\\Auth', 'use Tinvar\\Data\\Encoder;')))->toBe(['Example.php:4: auth uses data, Tinvar\Data\Encoder (use)'])
            ->and($crossed($file('Tinvar\\Data', 'use Tinvar\\Routing\\RouteRegistry;')))->toBe(['Example.php:4: data uses reads, Tinvar\Routing\RouteRegistry (use)'])
            ->and($crossed($file('Tinvar\\Query', 'use Tinvar\\Render\\RenderChannel;')))->toBe(['Example.php:4: reads uses render, Tinvar\Render\RenderChannel (use)'])
            ->and($crossed($file('Tinvar\\Actions', 'use Tinvar\\Http\\Endpoints\\ActionEndpoint;')))->toBe(['Example.php:4: entry uses requests, Tinvar\Http\Endpoints\ActionEndpoint (use)'])
            ->and($crossed($file('Tinvar\\Cache', '// A write reaches the page through Tinvar\\Http\\EntryContext::check().')))->toBe(['Example.php:4: data uses entry, Tinvar\Http\EntryContext (comment)'])
            ->and($crossed($file('Tinvar\\Http', 'use Tinvar\\Http\\Middleware;')))->toBe([])
            ->and($crossed($file('Tinvar\\Http\\Endpoints', 'use Tinvar\\Actions\\ActionRunner;')))->toBe([])
            ->and($crossed($file('Tinvar\\Shiny', 'use Tinvar\\Auth\\AuthContext;')))->toBe(['Example.php:4: http uses auth, Tinvar\Auth\AuthContext (use)']);
    });

    it('takes a class over a namespace of the same name, and a namespace over the one around it', function () {
        $layers = new Layers(
            ['low' => ['A\\', 'A\\B'], 'high' => ['A\\B\\']],
            ['high' => ['low']],
        );

        expect($layers->layerOf('A\\B'))->toBe('low')
            ->and($layers->layerOf('A\\B\\C'))->toBe('high')
            ->and($layers->layerOf('A\\C'))->toBe('low')
            ->and((new Layers(['low' => ['A\\'], 'high' => ['A\\B\\']], ['high' => ['low']]))->layerOf('A\\B'))->toBe('high');
    });
});

describe('a database class', function () {
    it('passes using only support and itself', function () {
        $source = orm(
            "use Tinvar\\Support\\Str;\nuse Tinvar\\Orm\\Attributes\\Table;\nuse Tinvar\\Support\\Lazy;",
            'public function a(): string { return Schema\Table::class . \Tinvar\Orm\Model::class . namespace\Model::class; }',
        );

        expect(crossed($source))->toBe([]);
    });

    it('fails importing a framework class', function () {
        expect(crossed(orm('use Tinvar\Http\Request;')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails importing a tenancy class, the tenant walk among them', function () {
        expect(crossed(orm('use Tinvar\Tenancy\TenantHierarchy;')))
            ->toBe(['Example.php:7: database uses tenancy, Tinvar\Tenancy\TenantHierarchy (use)']);
    });

    it('fails naming one fully qualified, in a type, a new or a ::class', function (string $body) {
        expect(crossed(orm(body: $body)))->toHaveCount(1)
            ->and(crossed(orm(body: $body))[0])->toContain('database uses framework, Tinvar\Http\Request (code)');
    })->with([
        'a type' => ['public function a(\Tinvar\Http\Request $request): void {}'],
        'a new' => ['public function a(): object { return new \Tinvar\Http\Request(); }'],
        '::class' => ['public function a(): string { return \Tinvar\Http\Request::class; }'],
        'an instanceof' => ['public function a(object $o): bool { return $o instanceof \Tinvar\Http\Request; }'],
        'a static call' => ['public function a(): mixed { return \Tinvar\Http\Request::capture(); }'],
        'an attribute' => ['#[\Tinvar\Http\Request] public function a(): void {}'],
        'a trait' => ['use \Tinvar\Http\Request;'],
    ]);

    it('fails naming one through an imported namespace', function () {
        $violations = crossed(orm('use Tinvar\Http;', 'public function a(): string { return Http\Request::class; }'));

        expect($violations)->toBe([
            'Example.php:7: database uses framework, Tinvar\Http (use)',
            'Example.php:12: database uses framework, Tinvar\Http\Request (code)',
        ]);
    });

    it('fails on an alias at the import, wherever the alias is used', function () {
        expect(crossed(orm('use Tinvar\Http\Request as Incoming;', 'public function a(): string { return Incoming::class; }')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails on the one name of a group that crosses', function () {
        expect(crossed(orm('use Tinvar\Orm\Attributes\{Table, Column as Field};')))->toBe([])
            ->and(crossed(orm('use Tinvar\{Orm\Model, Http\Request as Incoming, Support\Str};')))
            ->toBe(['Example.php:7: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('fails importing a framework function or constant', function () {
        expect(crossed(orm("use function Tinvar\\Http\\respond;\nuse const Tinvar\\Http\\LIMIT;")))->toBe([
            'Example.php:7: database uses framework, Tinvar\Http\respond (use)',
            'Example.php:8: database uses framework, Tinvar\Http\LIMIT (use)',
        ]);
    });

    // ADR 0077: a prefix loads nothing, but code that asks whether a class
    // is under one knows of that namespace. `Tinvar\` alone is under every
    // layer, so in none of them.
    it('fails naming one in a string, a namespace prefix among them, but not Tinvar\ alone', function () {
        $body = <<<'PHP'
            public function a(): array
            {
                return ['Tinvar\Http\Request', "\\Tinvar\\Live\\Invalidator", str_starts_with('x', 'Tinvar\Http\\'), str_starts_with('x', 'Tinvar\\')];
            }
            PHP;

        expect(crossed(orm(body: $body)))->toBe([
            'Example.php:14: database uses framework, Tinvar\Http\Request (string)',
            'Example.php:14: database uses framework, Tinvar\Live\Invalidator (string)',
            'Example.php:14: database uses framework, Tinvar\Http (string)',
        ]);
    });

    // ADR 0077: two unqualified forms in comments read as code.
    it('fails a member of a class named short in a comment, by its class beside the file or the one class with that name', function () {
        $docblock = <<<'PHP'
            /**
             * Read through Request::attribute(), with RequestScope::reset()
             * after; Kernel::LIVE_MINTER names nothing it can find, and
             * Worker::process() is two classes' name. An example passes
             * #[TenantFrom(Host::class)], as an application writes it.
             */
            PHP;
        $classes = ['Tinvar\Http\Request', 'Tinvar\Http\RequestScope', 'Tinvar\Orm\Worker', 'Tinvar\Http\Worker', 'Tinvar\Tenancy\Host', 'Tinvar\Orm\Example'];

        expect(Layers::framework()->checkSource(orm(docblock: $docblock), 'Example.php', NAMESPACES, $classes))->toBe([
            'Example.php:10: database uses framework, Tinvar\Http\Request (comment)',
            'Example.php:10: database uses framework, Tinvar\Http\RequestScope (comment)',
        ]);
    });

    it('passes a member named short through an import, which is counted at the import', function () {
        $source = orm('use Tinvar\Support\Str;', '// Str::lower() and Model::find(), its own.');

        expect(Layers::framework()->checkSource($source, 'Example.php', NAMESPACES, ['Tinvar\Support\Str', 'Tinvar\Orm\Model']))->toBe([]);
    });

    it('fails naming one in a docblock or a comment, shortened as these comments write them', function () {
        $docblock = <<<'PHP'
            /**
             * Told after the commit (see Live\Invalidator), checked by the
             * testing layer's \Tinvar\Testing\ModelLeaks.
             *
             * @param Tinvar\Http\Request $request
             */
            PHP;

        expect(crossed(orm(docblock: $docblock, body: '// Reset with Http\RequestScope.')))->toBe([
            'Example.php:10: database uses framework, Tinvar\Live\Invalidator (comment)',
            'Example.php:11: database uses testing, Tinvar\Testing\ModelLeaks (comment)',
            'Example.php:13: database uses framework, Tinvar\Http\Request (comment)',
            'Example.php:17: database uses framework, Tinvar\Http\RequestScope (comment)',
        ]);
    });

    it('passes a comment naming its own layer, or what is not Tinvar', function () {
        $docblock = "/**\n * Built from Schema\\Table, as App\\Models\\User is in an application, and see Orm\\Model.\n */";

        expect(crossed(orm(docblock: $docblock)))->toBe([]);
    });

    it('fails an import in a braced namespace as at the top of a file', function () {
        $source = "<?php\nnamespace Tinvar\\Orm {\n    use Tinvar\\Http\\Request;\n\n    final class Example {}\n}\n";

        expect(crossed($source))->toBe(['Example.php:3: database uses framework, Tinvar\Http\Request (use)']);
    });

    it('is read whole past a closure and a class body', function () {
        $body = <<<'PHP'
            use Concerns;

            public function a(int $x): \Closure
            {
                return function () use ($x): object { return new \Tinvar\Http\Request($x); };
            }
            PHP;

        expect(crossed(orm(body: $body)))
            ->toBe(['Example.php:16: database uses framework, Tinvar\Http\Request (code)']);
    });
});

describe('the other layers', function () {
    // ADR 0056: tenancy is a package an application opts into, so nothing
    // below it -- nor testing or codegen beside it -- may name it, in code,
    // a string or a comment.
    it('fails the framework, testing or codegen naming tenancy, and passes tenancy using the framework', function () {
        $file = static fn (string $namespace, string $use): string => "<?php\nnamespace {$namespace};\n\nuse {$use};\n\nfinal class Example {}\n";

        expect(crossed($file('Tinvar\\Http', 'Tinvar\\Tenancy\\Host')))->toBe(['Example.php:4: framework uses tenancy, Tinvar\Tenancy\Host (use)'])
            ->and(crossed($file('Tinvar\\Testing', 'Tinvar\\Tenancy\\TenantHierarchy')))->toBe(['Example.php:4: testing uses tenancy, Tinvar\Tenancy\TenantHierarchy (use)'])
            ->and(crossed($file('Tinvar\\Codegen', 'Tinvar\\Tenancy\\TenantSource')))->toBe(['Example.php:4: codegen uses tenancy, Tinvar\Tenancy\TenantSource (use)'])
            ->and(crossed("<?php\nnamespace Tinvar\\Http;\n\n/** Read through Tinvar\\Tenancy\\ResolveTenant. */\nfinal class Example {}\n"))->toHaveCount(1)
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Http\\TenantResolver')))->toBe([])
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Orm\\TenantSubtrees')))->toBe([])
            ->and(crossed($file('Tinvar\\Tenancy', 'Tinvar\\Testing\\Client')))->toBe(['Example.php:4: tenancy uses testing, Tinvar\Testing\Client (use)']);
    });

    it('fails support using the database', function () {
        $source = "<?php\nnamespace Tinvar\\Support;\n\nuse Tinvar\\Orm\\Model;\n\nfinal class Example {}\n";

        expect(crossed($source))->toBe(['Example.php:4: support uses database, Tinvar\Orm\Model (use)']);
    });

    it('takes a shared namespace class by class', function () {
        $table = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Table {}\n";
        $panel = "<?php\nnamespace Tinvar\\Attributes;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Panel {}\n";

        expect(shared()->checkSource($table, 'Example.php', NAMESPACES))->toBe(['Example.php:4: database uses framework, Tinvar\Http\Request (use)'])
            ->and(shared()->checkSource($panel, 'Example.php', NAMESPACES))->toBe([]);
    });

    it('fails a class of a shared namespace named bare, with no import to catch', function () {
        $source = <<<'PHP'
            <?php
            namespace Tinvar\Attributes;

            #[Panel]
            final class Column
            {
                use Concerns;

                public function a(\ReflectionClass $r, Panel $panel): array
                {
                    $this->Panel();
                    $x = self::Panel;
                    f(Panel: 1);
                    return ClassAttributes::of($r, self::class);
                }

                public function b(mixed $kind): int
                {
                    switch ($kind) {
                        case Kind::Admin:
                            return 1;
                    }

                    return 0;
                }
            }

            enum Local { case Panel; }
            PHP;
        $classes = ['Tinvar\Attributes\Panel', 'Tinvar\Attributes\ClassAttributes', 'Tinvar\Attributes\Concerns', 'Tinvar\Attributes\Kind', 'Tinvar\Attributes\Column'];

        expect(shared()->checkSource($source, 'Column.php', NAMESPACES, $classes))->toBe([
            'Column.php:4: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:7: database uses framework, Tinvar\Attributes\Concerns (code)',
            'Column.php:9: database uses framework, Tinvar\Attributes\Panel (code)',
            'Column.php:14: database uses framework, Tinvar\Attributes\ClassAttributes (code)',
            'Column.php:20: database uses framework, Tinvar\Attributes\Kind (code)',
        ]);
    });

    // ADR 0077: PHPStan reads a bare type in a docblock as a class beside
    // the file, so in a shared namespace the rule does too.
    it('fails a class of a shared namespace named bare in a docblock type, and not in its prose', function () {
        $source = <<<'PHP'
            <?php
            namespace Tinvar\Attributes;

            /**
             * Panel by panel, as a Panel would say.
             *
             * @param array<string, Panel>|null $panels the Panel each is in
             * @return \Closure(Kind): Column what Panel says
             */
            final class Column {}
            PHP;
        $classes = ['Tinvar\Attributes\Panel', 'Tinvar\Attributes\Kind', 'Tinvar\Attributes\Column'];

        expect(shared()->checkSource($source, 'Column.php', NAMESPACES, $classes))->toBe([
            'Column.php:7: database uses framework, Tinvar\Attributes\Panel (comment)',
            'Column.php:8: database uses framework, Tinvar\Attributes\Kind (comment)',
        ]);
    });

    it('passes the framework using the database and support', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Orm\\Orm;\nuse Tinvar\\Support\\Str;\n\nfinal class Example {}\n";

        expect(crossed($source))->toBe([]);
    });

    it('fails the framework naming the testing layer, which is built on it', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nfinal class Example { public function a(): string { return \\Tinvar\\Testing\\Testing::class; } }\n";

        expect(crossed($source))->toBe(['Example.php:4: framework uses testing, Tinvar\Testing\Testing (code)']);
    });

    it('fails the framework naming codegen, which is built on it', function () {
        $source = "<?php\nnamespace Tinvar\\Generators;\n\n// As \\Tinvar\\Codegen\\TypeScriptGenerator does.\nfinal class Example {}\n";

        expect(crossed($source))->toBe(['Example.php:4: framework uses codegen, Tinvar\Codegen\TypeScriptGenerator (comment)']);
    });

    it('puts a file in the layer its path names, and fails one declaring another class', function () {
        $source = "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n";

        expect(Layers::framework()->checkSource($source, 'src/Orm/Escape.php', NAMESPACES, [], 'Tinvar\Orm\Escape'))->toBe([
            'src/Orm/Escape.php: declares Tinvar\Http\Escape, where its path names Tinvar\Orm\Escape',
            'src/Orm/Escape.php:4: database uses framework, Tinvar\Http\Request (use)',
        ]);
    });

    it('puts a file in its package\'s layer, and fails a class of another layer\'s namespace there', function () {
        $source = "<?php\nnamespace Tinvar\\Orm;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n";

        expect(Layers::framework()->checkSource($source, 'packages/framework/src/Orm/Escape.php', NAMESPACES, [], 'Tinvar\Orm\Escape', 'framework'))->toBe([
            'packages/framework/src/Orm/Escape.php: Tinvar\Orm\Escape is in the database layer, and its file is in the framework package',
        ]);
    });

    it('reads each package whole, and fails a file in none of its PSR-4 directories', function () {
        $layers = new Layers(
            ['support' => ['Tinvar\\Support\\'], 'database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']],
            ['database' => ['support'], 'framework' => ['support', 'database']],
            ['packages/support/src/Support' => 'Tinvar\\Support\\', 'packages/database/src/Orm' => 'Tinvar\\Orm\\', 'packages/framework/src' => 'Tinvar\\'],
            ['packages/support/src' => 'support', 'packages/database/src' => 'database', 'packages/framework/src' => 'framework'],
        );

        expect(checked($layers, [
            'packages/support/src/Support/Str.php' => "<?php\nnamespace Tinvar\\Support;\n\nfinal class Str {}\n",
            'packages/support/src/Stray.php' => "<?php\nnamespace Tinvar\\Support;\n\nfinal class Stray {}\n",
            'packages/database/src/Orm/Model.php' => "<?php\nnamespace Tinvar\\Orm;\n\nuse Tinvar\\Support\\Str;\n\nfinal class Model {}\n",
            'packages/framework/src/Orm/Escape.php' => "<?php\nnamespace Tinvar\\Orm;\n\nfinal class Escape {}\n",
            'packages/framework/src/Http/Kernel.php' => "<?php\nnamespace Tinvar\\Http;\n\nuse Tinvar\\Orm\\Model;\n\nfinal class Kernel {}\n",
        ]))->toBe([
            'packages/support/src/Stray.php: in the support package, and in none of its PSR-4 directories, so its layer cannot be checked.',
            'packages/framework/src/Orm/Escape.php: Tinvar\Orm\Escape is in the database layer, and its file is in the framework package',
        ]);
    });

    // Composer autoloads through a link, and a directory iterator does not
    // walk into one: a database class behind a link to a directory would
    // load and never be read. A link is refused, wherever it points.
    it('fails a link in a package rather than skipping what is behind it', function () {
        $layers = new Layers(
            ['support' => ['Tinvar\\Support\\'], 'database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']],
            ['database' => ['support'], 'framework' => ['support', 'database']],
            ['packages/database/src/Orm' => 'Tinvar\\Orm\\', 'packages/framework/src' => 'Tinvar\\'],
            ['packages/database/src' => 'database', 'packages/framework/src' => 'framework'],
        );
        $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
        mkdir("{$root}/packages/database/src/Orm", 0o777, true);
        mkdir("{$root}/packages/framework/src/Http", 0o777, true);
        mkdir("{$root}/outside", 0o777, true);
        file_put_contents("{$root}/packages/framework/src/Http/Request.php", "<?php\nnamespace Tinvar\\Http;\n\nfinal class Request {}\n");
        file_put_contents("{$root}/outside/Escape.php", "<?php\nnamespace Tinvar\\Orm\\Linked;\n\nuse Tinvar\\Http\\Request;\n\nfinal class Escape {}\n");
        symlink("{$root}/outside", "{$root}/packages/database/src/Orm/Linked");
        symlink("{$root}/outside/Escape.php", "{$root}/packages/database/src/Orm/Escape.php");

        try {
            $violations = $layers->check($root);
            sort($violations);

            expect($violations)->toBe([
                'packages/database/src/Orm/Escape.php: a link, so what it points at is not read as the package\'s own; its layer cannot be checked.',
                'packages/database/src/Orm/Linked: a link, so what it points at is not read as the package\'s own; its layer cannot be checked.',
            ]);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    });

    it('fails a source in none of the packages, and a package that is not there', function () {
        $layers = new Layers(
            ['framework' => ['Tinvar\\']],
            [],
            ['elsewhere/src' => 'Tinvar\\'],
            ['packages/framework/src' => 'framework'],
        );

        expect($layers->check(sys_get_temp_dir()))->toBe([
            'elsewhere/src: a source in none of the packages, so its layer cannot be checked.',
            'packages/framework/src: not a directory, so its layers cannot be checked.',
        ]);
    });

    it('fails a file in no layer rather than skipping it', function () {
        expect(crossed("<?php\nnamespace Elsewhere;\n\nfinal class Example {}\n"))
            ->toBe(['Example.php: Elsewhere\Example is in no layer; name it in Layers.']);
    });

    it('fails a source it cannot read rather than passing it', function () {
        $layers = new Layers(['only' => ['Tinvar\\']], sources: ['nowhere/src' => 'Tinvar\\']);

        expect($layers->check(sys_get_temp_dir()))->toBe(['nowhere/src: not a directory, so its layers cannot be checked.']);
    });

    it('reads a directory with every namespace it declares known to its comments', function () {
        $root = sys_get_temp_dir() . '/tinvar-layers-' . bin2hex(random_bytes(4));
        mkdir($root . '/src/Orm', 0o777, true);
        mkdir($root . '/src/Live', 0o777, true);
        file_put_contents($root . '/src/Orm/Example.php', "<?php\nnamespace Tinvar\\Orm;\n\n/** Told by Live\\Invalidator. */\nfinal class Example {}\n");
        file_put_contents($root . '/src/Live/Invalidator.php', "<?php\nnamespace Tinvar\\Live;\n\nfinal class Invalidator {}\n");

        try {
            $layers = new Layers(['database' => ['Tinvar\\Orm\\'], 'framework' => ['Tinvar\\']], ['framework' => ['database']], ['src' => 'Tinvar\\']);

            $violations = $layers->check($root);
            sort($violations);

            expect($violations)->toBe(['src/Orm/Example.php:4: database uses framework, Tinvar\Live\Invalidator (comment)']);
        } finally {
            unlink($root . '/src/Orm/Example.php');
            unlink($root . '/src/Live/Invalidator.php');
            rmdir($root . '/src/Orm');
            rmdir($root . '/src/Live');
            rmdir($root . '/src');
            rmdir($root);
        }
    });
});

describe('the references in a file', function () {
    it('knows what it declares, past ::class and an anonymous class', function () {
        $source = "<?php\nnamespace Tinvar\\Orm;\n\n\$a = Model::class;\n\$b = new class {};\nenum Kind {}\n";

        expect(SourceReferences::of($source)->declared)->toBe('Tinvar\Orm\Kind')
            ->and(SourceReferences::of($source)->namespace)->toBe('Tinvar\Orm');
    });
});
