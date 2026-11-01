<?php

declare(strict_types=1);

/**
 * Discovery (ADR 0146): every class an application registers, found under
 * its namespace by what it declares, refused whole where anything is
 * wrong, and read from the build's manifest alone in production.
 */

namespace Tinvar\Tests\Discovery;

use Tinvar\Auth\ApplicationClasses;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Discovery\Discovery;
use Tinvar\Discovery\DiscoveryFailed;
use Tinvar\Discovery\Manifest;

/**
 * An application in a directory of its own, under a namespace no other
 * test's is -- PHP never forgets a class -- loaded by an autoloader of its
 * own as Composer's would load it.
 */
final class FixtureApp
{
    public readonly string $root;
    public readonly string $namespace;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/tinvar-discovery-' . bin2hex(random_bytes(6));
        $this->namespace = 'DiscoveryFixture\\App' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/app", 0o755, true);
        $prefix = $this->namespace . '\\';
        $base = "{$this->root}/app/";
        spl_autoload_register(static function (string $class) use ($prefix, $base): void {
            if (str_starts_with($class, $prefix)) {
                $file = $base . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    /** Writes $class -- relative to the namespace -- with $body after its namespace and imports. */
    public function write(string $class, string $body, string $uses = ''): string
    {
        $relative = str_replace('\\', '/', $class);
        $file = "{$this->root}/app/{$relative}.php";
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        $slash = strrpos($class, '\\');
        $namespace = $this->namespace . ($slash === false ? '' : '\\' . substr($class, 0, $slash));
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$uses}\n{$body}\n");

        return $file;
    }

    public function raw(string $relative, string $source): string
    {
        $file = "{$this->root}/app/{$relative}";
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        file_put_contents($file, $source);

        return $file;
    }

    public function discovery(?array $psr4 = null): Discovery
    {
        return new Discovery(
            $this->namespace,
            $psr4 ?? [$this->namespace . '\\' => ["{$this->root}/app"]],
            "{$this->root}/dist/" . Manifest::FILE,
            $this->root,
            recheck: 0.0,
        );
    }

    public function name(string $class): string
    {
        return $this->namespace . '\\' . $class;
    }

    public function remove(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
}

/** @param \Closure(): mixed $run */
function problems(\Closure $run): string
{
    try {
        $run();
    } catch (DiscoveryFailed $e) {
        return $e->getMessage();
    }

    throw new \LogicException('Discovery did not refuse.');
}

beforeEach(function () {
    $this->app = new FixtureApp();
});

afterEach(function () {
    $this->app->remove();
});

it('finds each kind of class by what it declares, in any folder, by namespace', function () {
    $app = $this->app;
    $app->write('Models\\Rack', 'final class Rack extends Model {}', 'use Tinvar\\Orm\\Model;');
    $app->write('Http\\Deep\\Folder\\RackPage', "#[Page('/racks', 'racks')] final class RackPage { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $app->write('Shell', "#[Layout('/', 'shell')] final class Shell { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Layout;');
    $app->write('Oops', "#[ErrorPage([404], 'oops')] final class Oops {}", 'use Tinvar\\Attributes\\ErrorPage;');
    $app->write('Data\\RackData', 'final readonly class RackData extends Data {}', 'use Tinvar\\Data\\Data;');
    $app->write('Queries\\Racks', "#[Query, Tool('Every rack.')] final class Racks { public function __invoke(): void {} }", "use Tinvar\\Attributes\\Query;\nuse Tinvar\\Attributes\\Tool;");
    $app->write('Actions\\RackActions', "final class RackActions { #[Action, ServiceCall('warehouse')] public function move(): void {} }", "use Tinvar\\Attributes\\Action;\nuse Tinvar\\Attributes\\ServiceCall;");
    $app->write('Endpoints\\RackFeed', "final class RackFeed { #[Get('/racks.json')] public function list(): void {} }", 'use Tinvar\\Attributes\\Get;');
    $app->write('Commands\\Count', "#[Command('racks:count')] final class Count extends Base { public function handle(Input \$input, Output \$output): int { return 0; } }", "use Tinvar\\Attributes\\Command;\nuse Tinvar\\Console\\Command as Base;\nuse Tinvar\\Console\\Input;\nuse Tinvar\\Console\\Output;");
    $app->write('Tasks\\Sweep', "#[Schedule('0 * * * *')] final class Sweep { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Schedule;');
    $app->write('Config\\RackConfig', "#[Config(prefix: 'RACK_')] final readonly class RackConfig {}", 'use Tinvar\\Attributes\\Config;');
    $app->write('Data\\RackSort', "#[FieldsOf(RackData::class)] enum RackSort: string { case Name = 'name'; }", 'use Tinvar\\Attributes\\FieldsOf;');
    $app->write('Scenarios\\Demo', "#[Scenario('demo')] final class Demo {}", 'use Tinvar\\Attributes\\Scenario;');
    $app->write('Jobs\\Recount', 'final class Recount implements Job {}', 'use Tinvar\\Jobs\\Job;');
    $app->write('Flows\\Restock', 'final class Restock implements Workflow {}', 'use Tinvar\\Workflows\\Workflow;');
    $app->write('Listeners\\RackListeners', 'final class RackListeners { #[Listener] public function moved(\\stdClass $event): void {} }', 'use Tinvar\\Attributes\\Listener;');
    $app->write('Mail\\RackMoved', 'final class RackMoved implements Mailable { public function email(): Email { throw new \\LogicException(); } }', "use Tinvar\\Mail\\Email;\nuse Tinvar\\Mail\\Mailable;");
    $app->write('Policies\\RackPolicy', '#[Policy(Rack::class)] final class RackPolicy {}', "use Tinvar\\Attributes\\Policy;\nuse {$app->namespace}\\Models\\Rack;");
    $app->write('Shared\\RackShared', 'final class RackShared implements SharedData {}', 'use Tinvar\\Data\\SharedData;');
    $app->write('Hooks\\Carrier', "#[Webhook('/hooks/carrier')] final class Carrier { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Webhook;');
    $app->write('Channels\\RackUpdates', 'final class RackUpdates {}', '');
    // Neither registered nor refused: a part, a helper, an enum of no field's.
    $app->write('Parts\\Base', 'abstract class Base {}');
    $app->write('Parts\\Shape', 'interface Shape {}');
    $app->write('Parts\\Mixin', 'trait Mixin {}');
    $app->write('Parts\\Colour', "enum Colour { case Red; }");
    $app->write('Pricing', 'final class Pricing {}');

    $classes = $app->discovery()->scan();
    $n = $app->name(...);

    expect($classes->routes)->toBe([$n('Http\\Deep\\Folder\\RackPage'), $n('Oops'), $n('Shell')])
        ->and($classes->models)->toBe([$n('Models\\Rack')])
        ->and($classes->payloads)->toBe([$n('Data\\RackData')])
        ->and($classes->queries)->toBe([$n('Queries\\Racks')])
        ->and($classes->tools)->toBe([$n('Queries\\Racks')])
        ->and($classes->actions)->toBe([$n('Actions\\RackActions')])
        ->and($classes->services)->toBe([$n('Actions\\RackActions')])
        ->and($classes->endpoints)->toBe([$n('Endpoints\\RackFeed')])
        ->and($classes->commands)->toBe([$n('Commands\\Count')])
        ->and($classes->tasks)->toBe([$n('Tasks\\Sweep')])
        ->and($classes->configs)->toBe([$n('Config\\RackConfig')])
        ->and($classes->fieldEnums)->toBe([$n('Data\\RackSort')])
        ->and($classes->scenarios)->toBe([$n('Scenarios\\Demo')])
        ->and($classes->jobs)->toBe([$n('Jobs\\Recount')])
        ->and($classes->workflows)->toBe([$n('Flows\\Restock')])
        ->and($classes->listeners)->toBe([$n('Listeners\\RackListeners')])
        ->and($classes->mailables)->toBe([$n('Mail\\RackMoved')])
        ->and($classes->policies)->toBe([$n('Models\\Rack') => $n('Policies\\RackPolicy')])
        ->and($classes->shared)->toBe([$n('Shared\\RackShared')])
        ->and($classes->webhooks)->toBe([$n('Hooks\\Carrier')])
        ->and($classes->channels)->toBe([])
        ->and($classes->all())->not->toContain($n('Parts\\Base'), $n('Parts\\Shape'), $n('Parts\\Mixin'), $n('Parts\\Colour'), $n('Pricing'));
});

it('leaves a class marked #[NotRegistered] alone, and wants the reason', function () {
    $app = $this->app;
    $app->write('Preview', "#[Page('/preview', 'preview'), NotRegistered(reason: 'mounted by hand')] final class Preview { public function __invoke(): void {} }", "use Tinvar\\Attributes\\NotRegistered;\nuse Tinvar\\Attributes\\Page;");
    $app->write('Stray', "#[Panel('admin'), NotRegistered('kept for later')] final class Stray {}", "use Tinvar\\Attributes\\NotRegistered;\nuse Tinvar\\Attributes\\Panel;");

    expect($app->discovery()->scan()->all())->toBe([]);

    $app->write('Mute', "#[Page('/mute', 'mute'), NotRegistered(reason: ' ')] final class Mute { public function __invoke(): void {} }", "use Tinvar\\Attributes\\NotRegistered;\nuse Tinvar\\Attributes\\Page;");

    expect(problems(fn () => $app->discovery()->scan()))->toContain('Mute', '#[NotRegistered] needs a reason');
});

it('never walks vendor, node_modules, tests or fixtures, nor follows a link out', function () {
    $app = $this->app;
    $page = static fn (string $short): string => "#[Page('/{$short}', '{$short}')] final class {$short} { public function __invoke(): void {} }";
    $app->write('Home', $page('Home'), 'use Tinvar\\Attributes\\Page;');
    $app->write('vendor\\Planted', $page('Planted'), 'use Tinvar\\Attributes\\Page;');
    $app->write('node_modules\\Npm', $page('Npm'), 'use Tinvar\\Attributes\\Page;');
    $app->write('tests\\Spec', $page('Spec'), 'use Tinvar\\Attributes\\Page;');
    $app->write('Tests\\Feature', $page('Feature'), 'use Tinvar\\Attributes\\Page;');
    $app->write('Fixtures\\Sample', $page('Sample'), 'use Tinvar\\Attributes\\Page;');
    $outside = sys_get_temp_dir() . '/tinvar-discovery-outside-' . bin2hex(random_bytes(4));
    mkdir($outside);
    file_put_contents("{$outside}/Linked.php", "<?php\nnamespace {$app->namespace}\\Elsewhere;\nuse Tinvar\\Attributes\\Page;\n" . $page('Linked'));
    symlink($outside, "{$app->root}/app/Elsewhere");

    try {
        expect($app->discovery()->scan()->routes)->toBe([$app->name('Home')]);
    } finally {
        exec('rm -rf ' . escapeshellarg($outside));
    }
});

it('refuses a map into what Composer installed, or outside the application', function () {
    $app = $this->app;
    mkdir("{$app->root}/vendor/acme/pages", 0o755, true);

    expect(problems(fn () => $app->discovery([$app->namespace . '\\' => ["{$app->root}/app"], $app->namespace . '\\Vendored\\' => ["{$app->root}/vendor/acme/pages"]])->scan()))
        ->toContain('under what Composer or npm installed');

    expect(problems(fn () => $app->discovery([$app->namespace . '\\' => [sys_get_temp_dir()]])->scan()))->toContain('outside the application');
});

describe("the application's own composer.json", function () {
    /** Writes $app's composer.json, mapping $psr4 (prefix => path relative to it) in its autoload. */
    $composer = static function (FixtureApp $app, array $psr4, string $at = ''): void {
        file_put_contents("{$app->root}{$at}/composer.json", json_encode(['autoload' => ['psr-4' => $psr4]]));
    };
    $kernel = static function (FixtureApp $app): string {
        $app->write('Kernel', 'final class Kernel {}');
        $class = $app->name('Kernel');
        class_exists($class);

        return $class;
    };

    it("is the one nearest the Kernel's file, read whatever package is the root one in this process", function () use ($composer, $kernel) {
        $app = $this->app;
        $class = $kernel($app);
        $app->write('Http\\Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
        $composer($app, [$app->namespace . '\\' => 'app/', 'Unrelated\\' => 'unrelated/']);

        // The workspace -- the root package here -- maps nothing of this application's.
        expect(ApplicationClasses::fromComposer()->under($app->namespace)->prefixes())->toBe([])
            ->and(Discovery::of($class, "{$app->root}/dist")->scan()->routes)->toBe([$app->name('Http\\Home')]);
    });

    it('fails closed where it maps nothing under the namespace, or maps outside the application', function () use ($composer, $kernel) {
        $app = $this->app;
        $class = $kernel($app);
        $composer($app, ['Unrelated\\' => 'app/']);
        expect(problems(fn () => Discovery::of($class, "{$app->root}/dist")->scan()))
            ->toContain("The composer.json at " . realpath($app->root) . " autoloads nothing under {$app->namespace}\\");

        $outside = sys_get_temp_dir() . '/tinvar-discovery-beside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        try {
            $composer($app, [$app->namespace . '\\' => 'app/', $app->namespace . '\\Beside\\' => '../' . basename($outside) . '/']);
            expect(problems(fn () => Discovery::of($class, "{$app->root}/dist")->scan()))->toContain("outside the application at " . realpath($app->root));
        } finally {
            rmdir($outside);
        }
    });

    it('is never read from a Kernel Composer installed', function () use ($composer, $kernel) {
        $app = $this->app;
        $class = $kernel($app);
        $composer($app, [$app->namespace . '\\' => 'app/']);
        // As though the application's directory were a vendor directory Composer loads from.
        $loader = new \Composer\Autoload\ClassLoader($app->root);
        $loader->register();
        try {
            expect(problems(fn () => Discovery::of($class, "{$app->root}/dist")->scan()))
                ->toContain('There is no application to discover the classes of', 'under what Composer installed');
        } finally {
            $loader->unregister();
        }
    });

    it('fails closed where there is no composer.json above the Kernel', function () use ($kernel) {
        $app = $this->app;
        $class = $kernel($app);
        if (is_file(\dirname($app->root) . '/composer.json') || is_file('/composer.json')) {
            $this->markTestSkipped('The temporary directory sits under a composer.json.');
        }

        expect(problems(fn () => Discovery::of($class, "{$app->root}/dist")->scan()))->toContain('there is no composer.json at or above');
    });
});

it("reads the root package's autoload alone, never autoload-dev, and only under the application's namespace", function () {
    $app = $this->app;
    file_put_contents("{$app->root}/composer.json", json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'App\\Database\\' => 'database/', 'Other\\' => 'other/', 'Application\\' => 'application/']],
        'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
    ]));

    expect(ApplicationClasses::fromComposerJson($app->root)->under('App')->prefixes())->toBe([
        'App\\' => ["{$app->root}/app"],
        'App\\Database\\' => ["{$app->root}/database"],
    ]);
});

it('refuses a file that does not parse, without running it', function () {
    $app = $this->app;
    $app->raw('Broken.php', "<?php\nnamespace {$app->namespace};\nthrow new \\LogicException('ran');\nfinal class Broken {\n");

    expect(problems(fn () => $app->discovery()->scan()))->toContain('Broken.php does not parse');
});

it('refuses a file that fails to load, or declares a class its path does not name, or two policies for one class', function () {
    $app = $this->app;
    $app->raw('Throws.php', "<?php\nnamespace {$app->namespace};\nfinal class Throws extends \\{$app->namespace}\\Missing {}\n");
    $app->write('Misnamed', 'final class SomethingElse {}');
    $app->write('Models\\Rack', 'final class Rack extends Model {}', 'use Tinvar\\Orm\\Model;');
    $app->write('One', '#[Policy(Models\\Rack::class)] final class One {}', 'use Tinvar\\Attributes\\Policy;');
    $app->write('Two', '#[Policy(Models\\Rack::class)] final class Two {}', 'use Tinvar\\Attributes\\Policy;');

    $said = problems(fn () => $app->discovery()->scan());

    expect($said)->toContain('Throws (')
        ->toContain('does not load')
        ->toContain('declares ' . $app->name('SomethingElse') . ', and its path names ' . $app->name('Misnamed'))
        ->toContain('has two policies');
});

it('refuses a framework attribute nothing registers, a misspelt one, and a registered class hidden beside another', function () {
    $app = $this->app;
    $app->write('Helper', "#[Panel('admin')] final class Helper { #[Authorize('view')] public function go(): void {} }", "use Tinvar\\Attributes\\Authorize;\nuse Tinvar\\Attributes\\Panel;");
    $app->write('Typo', "#[\\Tinvar\\Attributes\\Paeg('/typo', 'typo')] final class Typo {}");
    $app->write('Host', "final class Host {}\n\n#[Page('/hidden', 'hidden')] final class Hidden { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');

    $said = problems(fn () => $app->discovery()->scan());

    expect($said)->toContain($app->name('Helper') . ' carries #[Panel], #[Authorize] on go(), and nothing registers it')
        ->toContain('Paeg')
        ->toContain($app->name('Hidden') . ' is declared in');
});

it('refuses a #[Policy] on an abstract class, an interface or a trait, which would decide nothing', function () {
    $app = $this->app;
    $app->write('Models\\Thing', 'final class Thing extends Model {}', 'use Tinvar\\Orm\\Model;');
    $app->write('BasePolicy', '#[Policy(Models\\Thing::class)] abstract class BasePolicy {}', 'use Tinvar\\Attributes\\Policy;');
    $app->write('Decides', '#[Policy(Models\\Thing::class)] interface Decides {}', 'use Tinvar\\Attributes\\Policy;');
    $app->write('Deciding', '#[Policy(Models\\Thing::class)] trait Deciding {}', 'use Tinvar\\Attributes\\Policy;');

    $said = problems(fn () => $app->discovery()->scan());

    expect($said)->toContain($app->name('BasePolicy') . ' is abstract and carries #[Policy]')
        ->toContain($app->name('Decides') . ' is an interface and carries #[Policy]')
        ->toContain($app->name('Deciding') . ' is a trait and carries #[Policy]')
        ->toContain('put #[Policy] on the concrete policy class');
});

it('refuses on an abstract class an attribute that registers only the class it is on, and keeps one its subclasses inherit', function () {
    $app = $this->app;
    $app->write('BasePage', "#[Page('/base', 'base')] abstract class BasePage { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $app->write('BaseTask', "#[Schedule('* * * * *')] abstract class BaseTask {}", 'use Tinvar\\Attributes\\Schedule;');
    $app->write('AdminPages', "#[Authorize('admin'), Middleware('auth')] abstract class AdminPages {}", "use Tinvar\\Attributes\\Authorize;\nuse Tinvar\\Attributes\\Middleware;");

    $said = problems(fn () => $app->discovery()->scan());

    expect($said)->toContain($app->name('BasePage') . ' is abstract and carries #[Page]')
        ->toContain($app->name('BaseTask') . ' is abstract and carries #[Schedule]')
        ->not->toContain($app->name('AdminPages'));
});

it('writes a manifest, and says how a stale one differs from a scan', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $discovery = $app->discovery();

    expect($discovery->stale())->toBe(["There is no discovery manifest at {$discovery->manifest}."]);

    $discovery->write();

    expect($discovery->stale())->toBe([]);

    $app->write('About', "#[Page('/about', 'about')] final class About { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');

    expect($discovery->stale())->toBe(['routes: ' . $app->name('About') . ' is in the scan and not in the manifest']);
});

it('boots from the manifest alone in production, and refuses to boot without one', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $discovery = $app->discovery();

    foreach ([['TINVAR_ENV' => 'production'], ['APP_ENV' => 'production'], ['NODE_ENV' => 'production'], ['TINVAR_ENV' => 'staging']] as $env) {
        expect(problems(fn () => $discovery->classes($env)))->toContain('There is no discovery manifest at', 'production boots from it alone');
    }

    $discovery->write();
    // A file that would fail a scan, and is never scanned.
    $app->raw('Broken.php', "<?php\nfinal class {\n");

    expect($discovery->classes(['TINVAR_ENV' => 'production'])->routes)->toBe([$app->name('Home')])
        ->and(problems(fn () => $discovery->classes(['TINVAR_ENV' => 'local'])))->toContain('does not parse');
});

it('refuses a manifest naming a class outside the application, or another application', function () {
    $app = $this->app;
    $file = "{$app->root}/dist/" . Manifest::FILE;
    Manifest::write($file, $app->namespace, new DiscoveredClasses(routes: [\Tinvar\Http\Kernel::class]));

    expect(problems(fn () => $app->discovery()->classes(['TINVAR_ENV' => 'production'])))->toContain('outside ' . $app->namespace);

    Manifest::write($file, 'Elsewhere', new DiscoveredClasses());

    expect(problems(fn () => $app->discovery()->classes(['TINVAR_ENV' => 'production'])))->toContain("not {$app->namespace}\\'s");

    file_put_contents($file, "<?php\nreturn ['version' => 9];\n");

    expect(problems(fn () => $app->discovery()->classes(['TINVAR_ENV' => 'production'])))->toContain('is not a discovery manifest');
});

it('keeps a scan while the files stay as they were, and scans again once one changes', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $discovery = $app->discovery();

    $first = $discovery->classes([]);

    expect($discovery->classes([]))->toBe($first);

    $app->write('About', "#[Page('/about', 'about')] final class About { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');

    expect($discovery->classes([])->routes)->toBe([$app->name('About'), $app->name('Home')]);

    unlink("{$app->root}/app/About.php");

    expect($discovery->classes([])->routes)->toBe([$app->name('Home')]);
});

it('keeps what a scan found for the next process beside the build, while the files are as they were', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    mkdir("{$app->root}/dist");
    $app->discovery()->classes([]);
    $cache = "{$app->root}/dist/discovery.dev.json";

    expect(is_file($cache))->toBeTrue();

    // A cache naming a class outside the application is not believed.
    $data = json_decode((string) file_get_contents($cache), true);
    $data['classes']['routes'][] = \Tinvar\Http\Kernel::class;
    file_put_contents($cache, json_encode($data));
    $fresh = new Discovery($app->namespace, [$app->namespace . '\\' => ["{$app->root}/app"]], "{$app->root}/dist/" . Manifest::FILE, $app->root, recheck: 0.0);

    expect((fn () => $this->cached($this->fingerprint()))->call($fresh))->toBeNull();
});

// The fingerprint a kept scan is believed by looked at the application's
// files and two of the framework's: a framework upgrade that sorted classes
// by other rules -- an attribute class, KnownAttributes -- left the dev
// cache's old answer standing.
it('scans again when what decides how a class is sorted changes, not only the application\'s files', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    $discovery = $app->discovery();
    $before = $discovery->fingerprint();

    foreach ([\Tinvar\Support\KnownAttributes::class, \Tinvar\Attributes\Page::class, \Tinvar\Discovery\DiscoveredClasses::class] as $decides) {
        $file = (string) new \ReflectionClass($decides)->getFileName();
        $mtime = (int) filemtime($file);
        touch($file, $mtime + 7);
        clearstatcache(true, $file);
        try {
            expect($discovery->fingerprint())->not->toBe($before, $decides);
        } finally {
            touch($file, $mtime);
            clearstatcache(true, $file);
        }
        expect($discovery->fingerprint())->toBe($before);
    }
});

it('scans afresh inside freshly(), believing neither the scan it keeps nor the one beside the build', function () {
    $app = $this->app;
    $app->write('Home', "#[Page('/', 'home')] final class Home { public function __invoke(): void {} }", 'use Tinvar\\Attributes\\Page;');
    mkdir("{$app->root}/dist");
    $discovery = new Discovery($app->namespace, [$app->namespace . '\\' => ["{$app->root}/app"]], "{$app->root}/dist/" . Manifest::FILE, $app->root, recheck: 60.0);
    $discovery->classes([]);
    $cache = "{$app->root}/dist/discovery.dev.json";
    $data = json_decode((string) file_get_contents($cache), true);
    $data['classes']['routes'] = [];
    file_put_contents($cache, json_encode($data));
    $next = new Discovery($app->namespace, [$app->namespace . '\\' => ["{$app->root}/app"]], "{$app->root}/dist/" . Manifest::FILE, $app->root, recheck: 60.0);

    // As another process would, trusting the cache beside the build.
    \Closure::bind(static function (): void {
        Discovery::$live = [];
    }, null, Discovery::class)();
    expect($next->classes([])->routes)->toBe([]);

    expect(Discovery::freshly(static fn (): DiscoveredClasses => $next->classes([]))->routes)->toBe([$app->name('Home')])
        ->and(Discovery::freshly(static fn (): DiscoveredClasses => $discovery->classes([]))->routes)->toBe([$app->name('Home')]);
});

it('adds classes registered by hand after the discovered ones, each once', function () {
    $classes = new DiscoveredClasses(
        routes: ['App\\Pages\\Home'],
        tasks: ['App\\Tasks\\Sweep'],
        policies: ['App\\Models\\Rack' => 'App\\Policies\\RackPolicy'],
    );

    $with = $classes->with(
        routes: ['app\\pages\\home', 'App\\Pages\\Extra'],
        tasks: [\Tinvar\Jobs\PruneQueue::class],
        policies: ['App\\Models\\Rack' => 'App\\Policies\\StricterPolicy', 'App\\Models\\Bay' => 'App\\Policies\\BayPolicy'],
    );

    expect($with->routes)->toBe(['App\\Pages\\Home', 'App\\Pages\\Extra'])
        ->and($with->tasks)->toBe(['App\\Tasks\\Sweep', \Tinvar\Jobs\PruneQueue::class])
        ->and($with->policies)->toBe(['App\\Models\\Bay' => 'App\\Policies\\BayPolicy', 'App\\Models\\Rack' => 'App\\Policies\\StricterPolicy'])
        ->and($with->without('App\\Pages\\Extra')->routes)->toBe(['App\\Pages\\Home'])
        ->and(fn () => $classes->with(pages: ['App\\Pages\\Home']))->toThrow(\InvalidArgumentException::class, "'pages' is no kind")
        ->and(fn () => $classes->with(routes: ['not a class']))->toThrow(\InvalidArgumentException::class, 'no class name');
});
