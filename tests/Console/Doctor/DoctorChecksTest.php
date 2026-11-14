<?php

declare(strict_types=1);

/**
 * `tinvar doctor`'s checks (ADR 0157), each over an application of the
 * test's own in a temporary directory and an environment handed in --
 * never the process's -- so each says what it would on that machine.
 */

namespace Tinvar\Tests\Console\Doctor;

use Tinvar\Console\Doctor\Checks\BuildCheck;
use Tinvar\Console\Doctor\Checks\ConfigCheck;
use Tinvar\Console\Doctor\Checks\DatabaseCheck;
use Tinvar\Console\Doctor\Checks\HostsCheck;
use Tinvar\Console\Doctor\Checks\MailCheck;
use Tinvar\Console\Doctor\Checks\NodeCheck;
use Tinvar\Console\Doctor\Checks\PermissionsCheck;
use Tinvar\Console\Doctor\Checks\PhpCheck;
use Tinvar\Console\Doctor\Checks\ProductionCheck;
use Tinvar\Console\Doctor\Checks\ProxiesCheck;
use Tinvar\Console\Doctor\Checks\RendererCheck;
use Tinvar\Console\Doctor\Checks\SecretsCheck;
use Tinvar\Console\Doctor\Doctor;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Console\Doctor\Severity;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\PdoConnection;

require_once __DIR__ . '/../../Transfers/Fixtures/app.php';

/** An export on a `{tenant}` host that reads its tenant from nowhere. */
#[\Tinvar\Attributes\Export(\Tinvar\Tests\Transfers\Fixtures\WidgetRow::class, name: 'doctor.tenant.widgets', maxRows: 1_000), \Tinvar\Attributes\Authorize('export', \Tinvar\Tests\Transfers\Fixtures\Widget::class), \Tinvar\Attributes\Domain('{tenant}.{root}')]
final readonly class TenantlessExport
{
    public function __construct(private \Tinvar\Orm\Orm $orm) {}

    public function __invoke(): \Tinvar\Orm\Query
    {
        return \Tinvar\Tests\Transfers\Fixtures\Widget::query($this->orm)->orderBy('sku');
    }
}

/** An export on the admin host. */
#[\Tinvar\Attributes\Export(\Tinvar\Tests\Transfers\Fixtures\WidgetRow::class, name: 'doctor.admin.widgets', maxRows: 1_000), \Tinvar\Attributes\Authorize('export', \Tinvar\Tests\Transfers\Fixtures\Widget::class), \Tinvar\Attributes\Domain('admin.{root}')]
final readonly class AdminExport
{
    public function __construct(private \Tinvar\Orm\Orm $orm) {}

    public function __invoke(): \Tinvar\Orm\Query
    {
        return \Tinvar\Tests\Transfers\Fixtures\Widget::query($this->orm)->orderBy('sku');
    }
}

/** A fresh application directory, removed after the test. */
function app(): string
{
    $root = sys_get_temp_dir() . '/tinvar-doctor-' . bin2hex(random_bytes(4));
    mkdir($root, 0o700, true);
    Made::$roots[] = $root;

    return $root;
}

/** The directories a test made, removed after it. */
final class Made
{
    /** @var list<string> */
    public static array $roots = [];
}

/**
 * A developer's machine unless $env says otherwise: TINVAR_ENV=local, which
 * $env overrides, and beside which a production marker still wins.
 *
 * @param array<string, string> $env
 * @param array<string, mixed> $more
 */
function context(string $root, array $env = [], array $more = []): DoctorContext
{
    return new DoctorContext(...['root' => $root, 'env' => ['TINVAR_ENV' => 'local', ...$env], ...$more]);
}

// ADR 0213: a machine whose environment says nothing is held to a server's
// checks, never read as a developer's.
it('reads an environment that says nothing as a server\'s', function () {
    expect(new DoctorContext(root: app(), env: [])->production())->toBe('TINVAR_ENV is not local, development or testing')
        ->and(new DoctorContext(root: app(), env: ['TINVAR_ENV' => 'local'])->production())->toBeNull()
        ->and(new DoctorContext(root: app(), env: ['TINVAR_ENV' => 'local', 'APP_ENV' => 'production'])->production())->toBe('APP_ENV=production');
});

/**
 * @param list<Finding> $findings
 * @return list<string>
 */
function severities(array $findings): array
{
    return array_map(static fn (Finding $f): string => $f->severity->value, $findings);
}

/** The finding's safe fix, which the test has just said it has. */
function fixOf(Finding $finding): \Tinvar\Console\Doctor\SafeFix
{
    return $finding->safeFix ?? throw new \LogicException('No safe fix.');
}

afterEach(function () {
    foreach (Made::$roots as $root) {
        exec('rm -rf ' . escapeshellarg($root));
    }
    Made::$roots = [];
});

describe('PHP', function () {
    it('passes PHP 8.5 with every extension the manifests and the CLI need', function () {
        $root = app();
        file_put_contents("{$root}/composer.json", json_encode(['require' => ['php' => '>=8.5', 'ext-intl' => '*']]));
        file_put_contents("{$root}/composer.lock", json_encode(['packages' => [['name' => 'tinvar/framework', 'require' => ['ext-dom' => '*']]]]));

        $findings = new PhpCheck('8.5.1', ['Core', 'sockets', 'pcntl', 'posix', 'intl', 'dom', 'pdo_sqlite'])->run(context($root));

        expect(severities($findings))->toBe(['ok'])
            ->and($findings[0]->message)->toContain('dom, intl, pcntl, pdo_sqlite, posix, sockets');
    });

    it('names each missing extension, who needs it, and how to install it, and an old PHP', function () {
        $root = app();
        file_put_contents("{$root}/composer.json", json_encode(['require' => ['ext-intl' => '*']]));

        $findings = new PhpCheck('8.4.9', ['sockets', 'pdo_sqlite'])->run(context($root, ['APP_DATABASE_DRIVER' => 'postgres']));
        $messages = implode("\n", array_map(static fn (Finding $f): string => $f->message . ' / ' . $f->fix, $findings));

        expect(severities($findings))->toBe(['problem', 'problem', 'problem', 'problem', 'problem'])
            ->and($messages)->toContain('PHP is 8.4.9; Tinvar needs 8.5.0')
            ->and($messages)->toContain('The intl extension is not loaded, and composer.json needs it')
            ->and($messages)->toContain('The pcntl extension is not loaded')
            ->and($messages)->toContain('The pdo_pgsql extension is not loaded, and the PostgreSQL database (APP_DATABASE_DRIVER) needs it')
            ->and($messages)->toContain('pecl install posix');
    });
});

describe('Node', function () {
    it('holds node to package.json\'s engines', function () {
        $root = app();
        file_put_contents("{$root}/package.json", json_encode(['engines' => ['node' => '>=22.13']]));

        expect(severities(new NodeCheck(static fn (): string => 'v22.14.0')->run(context($root))))->toBe(['ok'])
            ->and(new NodeCheck(static fn (): string => 'v20.1.0')->run(context($root))[0]->message)->toBe('Node is 20.1.0; package.json asks for 22.13 or later.')
            ->and(new NodeCheck(static fn (): ?string => null)->run(context($root))[0]->fix)->toContain('Install Node 22.13 or later');
    });
});

describe('the app key and the probe token', function () {
    it('takes a key from its variable or its file, and refuses one too short', function () {
        $root = app();
        $key = "{$root}/state/app.key";
        mkdir(\dirname($key), 0o700);
        file_put_contents($key, base64_encode(random_bytes(32)) . "\n");
        chmod($key, 0o600);

        $findings = new SecretsCheck()->run(context($root, ['TINVAR_PROBE_TOKEN' => 'short'], ['keyFile' => $key, 'probeTokenFile' => "{$root}/state/probe"]));

        expect(severities($findings))->toBe(['ok', 'problem'])
            ->and($findings[1]->message)->toContain('TINVAR_PROBE_TOKEN is 5 characters')
            ->and($findings[1]->message)->not->toContain('short');
    });

    it('offers to generate a missing dev secret, and generates it outside the project, private', function () {
        $root = app();
        $key = "{$root}/state/app.key";

        $findings = new SecretsCheck()->run(context($root, [], ['keyFile' => $key, 'probeTokenFile' => "{$root}/state/probe-token"]));

        expect(severities($findings))->toBe(['warning', 'warning'])
            ->and($findings[0]->safeFix)->not->toBeNull();
        expect((fixOf($findings[0])->apply)())->toBe("generated {$key}")
            ->and(is_file($key))->toBeTrue()
            ->and(fileperms($key) & 0o777)->toBe(0o600);
    });

    it('never generates a server\'s secret: it belongs in the secret store', function () {
        $root = app();

        $findings = new SecretsCheck()->run(context($root, ['TINVAR_ENV' => 'production'], ['keyFile' => "{$root}/k", 'probeTokenFile' => "{$root}/p"]));

        expect(severities($findings))->toBe(['problem', 'problem'])
            ->and($findings[0]->safeFix)->toBeNull()
            ->and($findings[0]->fix)->toContain('secret store');
    });

    it('names a secret written into .env, never its value, and a relative _FILE', function () {
        $root = app();
        file_put_contents("{$root}/.env", "APP_DATABASE_DRIVER=sqlite\nAPP_DATABASE_PASSWORD=hunter2hunter2\n");

        $findings = new SecretsCheck()->run(context($root, ['TINVAR_APP_KEY_FILE' => 'keys/app.key', 'TINVAR_PROBE_TOKEN' => str_repeat('p', 40)]));
        $said = implode("\n", array_map(static fn (Finding $f): string => $f->message . $f->fix, $findings));

        expect(severities($findings))->toBe(['problem', 'ok', 'warning'])
            ->and($said)->toContain('TINVAR_APP_KEY_FILE is keys/app.key, which is not an absolute path')
            ->and($said)->toContain('APP_DATABASE_PASSWORD has a value in .env')
            ->and($said)->toContain('APP_DATABASE_PASSWORD_FILE')
            ->and($said)->not->toContain('hunter2');
    });
});

/** A config class of the test's own: a store's DSN, a secret whose variable's name does not say so. */
#[\Tinvar\Attributes\Config('DOCTORTEST_')]
final readonly class StoreConfig
{
    public function __construct(
        #[\SensitiveParameter]
        public ?\Tinvar\Config\Secret $cacheStore = null,
        public string $cacheTtl = '60',
    ) {}
}

describe('config, proxies and hosts', function () {
    it('warns of a secret a config class reads whose variable .env sets, since its name does not say it is one', function () {
        $root = app();
        file_put_contents("{$root}/.env", "DOCTORTEST_CACHE_STORE=redis://:hunter2@cache.internal/1\nDOCTORTEST_CACHE_TTL=30\n");
        $classes = \Tinvar\Discovery\DiscoveredClasses::fromArray(['configs' => [StoreConfig::class]]);

        $findings = new ConfigCheck()->run(context($root, ['DOCTORTEST_CACHE_STORE' => 'redis://:hunter2@cache.internal/1', 'DOCTORTEST_CACHE_TTL' => '30'], ['classes' => static fn () => $classes]));
        $said = implode("\n", array_map(static fn (Finding $f): string => $f->message . $f->fix, $findings));

        expect(severities($findings))->toBe(['ok', 'warning'])
            ->and($said)->toContain('DOCTORTEST_CACHE_STORE is a secret')
            ->and($said)->toContain('DOCTORTEST_CACHE_STORE_FILE')
            ->and($said)->not->toContain('DOCTORTEST_CACHE_TTL')
            ->and($said)->not->toContain('hunter2');
    });

    it('says every refused key of the framework\'s config, by its variable', function () {
        $findings = new ConfigCheck()->run(context(app(), ['TINVAR_TRUSTED_PROXIES' => '0.0.0.0/0', 'TINVAR_STOP_BUDGET' => 'soon']));
        $said = implode("\n", array_map(static fn (Finding $f): string => $f->message, $findings));

        expect(severities($findings))->toBe(['problem', 'problem'])
            ->and($said)->toContain('TINVAR_TRUSTED_PROXIES')
            ->and($said)->toContain('TINVAR_STOP_BUDGET must be whole seconds');
    });

    it('warns of a server with no proxy pinned, and leaves a range with no secret to the boot\'s refusal', function () {
        $root = app();

        expect(severities(new ProxiesCheck()->run(context($root))))->toBe(['ok'])
            ->and(new ProxiesCheck()->run(context($root, ['TINVAR_ENV' => 'production']))[0]->fix)->toContain('deploy:init');

        $refused = new ProxiesCheck()->run(context($root, ['TINVAR_TRUSTED_PROXIES' => '10.213.92.0/24']));
        $backed = new ProxiesCheck()->run(context($root, ['TINVAR_TRUSTED_PROXIES' => '10.213.92.0/24', 'TINVAR_TRUSTED_PROXY_SECRET' => str_repeat('s', 40)]));
        $single = new ProxiesCheck()->run(context($root, ['TINVAR_TRUSTED_PROXIES' => '203.0.113.7']));

        expect(severities($refused))->toBe(['skipped'])
            ->and(severities(new ConfigCheck()->run(context($root, ['TINVAR_TRUSTED_PROXIES' => '10.213.92.0/24']))))->toBe(['problem'])
            ->and($backed[0]->message)->toContain('each proxy proving itself with TINVAR_TRUSTED_PROXY_SECRET')
            ->and(severities($single))->toBe(['ok']);
    });

    it('warns of a server with no primary host', function () {
        $root = app();

        expect(severities(new HostsCheck()->run(context($root))))->toBe(['ok'])
            ->and(severities(new HostsCheck()->run(context($root, ['TINVAR_ENV' => 'production', 'TINVAR_APP_DOMAIN' => 'https://app.example.com']))))->toBe(['ok']);
        $missing = new HostsCheck()->run(context($root, ['TINVAR_ENV' => 'production']));
        expect(severities($missing))->toBe(['warning'])
            ->and($missing[0]->fix)->toContain('TINVAR_APP_DOMAIN');
    });
    it('holds every entry point on a {tenant} host to reading its tenant from it, as check and the boot do', function () {
        $root = app();
        $leaky = new HostsCheck()->run(context($root, [], [
            'classes' => static fn (): \Tinvar\Discovery\DiscoveredClasses => new \Tinvar\Discovery\DiscoveredClasses(transfers: [TenantlessExport::class]),
            'domains' => static fn (): \Tinvar\Routing\Domains => new \Tinvar\Routing\Domains(['root' => 'shop.test']),
        ]));

        expect(severities($leaky))->toBe(['problem'])
            ->and($leaky[0]->message)->toContain('reads its tenant from nowhere');
    });
    it('holds the hosts to the Domains the app\'s Kernel configures, as check and the boot do, not to TINVAR_APP_DOMAIN beside it', function () {
        $root = app();
        $classes = static fn (): \Tinvar\Discovery\DiscoveredClasses => new \Tinvar\Discovery\DiscoveredClasses(transfers: [AdminExport::class]);
        $none = new HostsCheck()->run(context($root, ['TINVAR_APP_DOMAIN' => 'https://shop.test'], ['classes' => $classes, 'domains' => static fn (): ?\Tinvar\Routing\Domains => null]));
        $served = new HostsCheck()->run(context($root, [], ['classes' => $classes, 'domains' => static fn (): \Tinvar\Routing\Domains => new \Tinvar\Routing\Domains(['root' => 'shop.test'])]));

        expect(severities($none))->toBe(['problem'])
            ->and($none[0]->message)->toContain('admin.{root}')
            ->and(severities($served))->toBe(['ok']);
    });
});

describe('mail', function () {
    it('builds the transport the provider names, and names every variable it lacks', function () {
        $root = app();

        expect(severities(new MailCheck()->run(context($root))))->toBe(['ok'])
            ->and(new MailCheck()->run(context($root, ['TINVAR_MAIL_PROVIDER' => 'postmark', 'TINVAR_POSTMARK_TOKEN' => 'pm-token']))[0]->message)->toBe('Mail goes through postmark, with every variable it needs.');
        $missing = new MailCheck()->run(context($root, ['TINVAR_MAIL_PROVIDER' => 'resend']));
        expect(severities($missing))->toBe(['problem'])
            ->and($missing[0]->message)->toContain('TINVAR_RESEND_KEY');
    });

    it('refuses a provider that sends nothing on a server, as the boot does', function () {
        $findings = new MailCheck()->run(context(app(), ['TINVAR_ENV' => 'production', 'TINVAR_MAIL_PROVIDER' => 'log']));

        expect(severities($findings))->toBe(['problem'])
            ->and($findings[0]->message)->toContain('names log, which sends nothing');
    });
});

describe('the database', function () {
    it('holds the database to its migrations, and runs the pending ones only on a developer\'s machine', function () {
        $root = app();
        mkdir("{$root}/migrations");
        file_put_contents("{$root}/migrations/2026_11_01_000001_create_things.php", <<<'PHP'
            <?php
            use Tinvar\Orm\Migrations\Migration;
            use Tinvar\Orm\Schema\Schema;
            use Tinvar\Orm\Schema\Table;
            return new class extends Migration {
                public function up(Schema $schema): void { $schema->create('things', static function (Table $table): void { $table->id(); }); }
                public function down(Schema $schema): void { $schema->drop('things'); }
            };
            PHP);
        $connection = PdoConnection::sqlite("{$root}/app.sqlite", 'doctor-test', create: true);
        $ran = 0;
        $more = [
            'connect' => static fn (bool $create): Connection => $connection,
            'migrations' => "{$root}/migrations",
            'migrate' => static function () use (&$ran): int {
                ++$ran;

                return 0;
            },
        ];

        $dev = new DatabaseCheck()->run(context($root, [], $more));
        $server = new DatabaseCheck()->run(context($root, ['TINVAR_ENV' => 'production'], $more));

        expect(severities($dev))->toBe(['problem'])
            ->and($dev[0]->message)->toBe('1 migration has not run: 2026_11_01_000001_create_things.')
            ->and($dev[0]->safeFix)->not->toBeNull()
            ->and($server[0]->safeFix)->toBeNull()
            ->and($server[0]->fix)->toContain('as part of the deploy');
        (fixOf($dev[0])->apply)();
        expect($ran)->toBe(1);
    });

    it('says a database that cannot be reached, never a password, and one never made', function () {
        $root = app();
        $unreachable = new DatabaseCheck()->run(context($root, [], ['connect' => static fn (bool $create): Connection => throw new \PDOException("SQLSTATE[08006] connection to server at \"db\" failed\nmore")]));
        $missing = new DatabaseCheck()->run(context($root, [], ['connect' => static fn (bool $create): Connection => throw new MigrationRefused('No database at /x.sqlite; `bin/tinvar migrate` makes one.')]));

        expect($unreachable[0]->message)->toBe('The database cannot be reached: SQLSTATE[08006] connection to server at "db" failed')
            ->and($unreachable[0]->fix)->toContain('APP_DATABASE_HOST')
            ->and($missing[0]->message)->toContain('No database at /x.sqlite')
            ->and(severities(new DatabaseCheck()->run(context($root))))->toBe(['skipped']);
    });
});

describe('the build, the renderers and production', function () {
    it('needs a build made for the profile on a server, and none on a developer\'s machine', function () {
        $root = app();

        expect(severities(new BuildCheck()->run(context($root))))->toBe(['ok'])
            ->and(severities(new BuildCheck()->run(context($root, ['TINVAR_ENV' => 'production']))))->toBe(['problem']);

        mkdir("{$root}/dist/rsc", 0o755, true);
        file_put_contents("{$root}/dist/rsc/profile.json", '{"profile":"test"}');
        $mismatched = new BuildCheck()->run(context($root, ['TINVAR_ENV' => 'production']));
        expect(severities($mismatched))->toBe(['problem', 'problem'])
            ->and($mismatched[0]->fix)->toBe('`php tinvar build --profile=production`.')
            ->and($mismatched[1]->message)->toContain('fragments.json is missing');
    });

    it('needs a renderer answering on a server, and only says so on a developer\'s machine', function () {
        $root = app();
        $socket = "{$root}/pool";

        // Unset, the pool is looked for in this user's state directory,
        // never in /tmp (ADR 0212).
        $state = app();
        expect(new RendererCheck()->run(context($root, ['XDG_STATE_HOME' => $state]))[0]->message)
            ->toContain("{$state}/tinvar/")
            ->not->toContain('/tmp/tinvar.sock');
        expect(severities(new RendererCheck()->run(context($root, ['TINVAR_SOCKET' => $socket]))))->toBe(['ok'])
            ->and(severities(new RendererCheck()->run(context($root, ['TINVAR_SOCKET' => $socket, 'TINVAR_ENV' => 'production']))))->toBe(['problem']);
        mkdir($socket);
        expect(new RendererCheck()->run(context($root, ['TINVAR_SOCKET' => $socket]))[0]->message)->toContain('No renderer is listening');
    });

    it('says what the production profile refuses, each with its fix', function () {
        $findings = new ProductionCheck()->run(context(app(), [
            'TINVAR_ENV' => 'production',
            'TINVAR_DEV' => '1',
            'TINVAR_DEV_TOOLBAR' => '/tmp/toolbar',
            'TINVAR_TEST_RUN' => 'abc',
            'TINVAR_PROBE_TRUST_LOOPBACK' => '1',
        ]));
        $said = implode("\n", array_map(static fn (Finding $f): string => $f->message, $findings));

        expect(severities($findings))->toBe(['problem', 'problem', 'problem', 'problem'])
            ->and($said)->toContain('TINVAR_DEV=1 is refused')
            ->and($said)->toContain('TINVAR_DEV_TOOLBAR is refused')
            ->and($said)->toContain('TINVAR_TEST_RUN is set')
            ->and($said)->toContain('TINVAR_PROBE_TRUST_LOOPBACK=1')
            ->and(severities(new ProductionCheck()->run(context(app()))))->toBe(['ok']);
    });

    // ADR 0213: the toolbar is refused wherever TINVAR_ENV does not say
    // local, development or testing, so the doctor asks there too.
    it('says the dev toolbar is refused on a server whose environment names no environment', function () {
        $findings = new ProductionCheck()->run(new DoctorContext(root: app(), env: ['TINVAR_DEV_TOOLBAR' => '/tmp/toolbar']));

        expect(implode("\n", array_map(static fn (Finding $f): string => $f->message, $findings)))
            ->toContain('TINVAR_DEV_TOOLBAR is refused');
    });

    it('finds secrets and a database other users may read', function () {
        $root = app();
        mkdir("{$root}/state", 0o755);
        file_put_contents("{$root}/state/app.key", str_repeat('k', 44));
        chmod("{$root}/state/app.key", 0o644);
        mkdir("{$root}/database");
        touch("{$root}/database/app.sqlite");
        chmod("{$root}/database/app.sqlite", 0o644);

        $findings = new PermissionsCheck()->run(context($root, [], ['keyFile' => "{$root}/state/app.key"]));
        $fixes = array_map(static fn (Finding $f): ?string => $f->fix, $findings);

        expect(severities($findings))->toBe(['problem', 'problem', 'warning'])
            ->and($fixes)->toContain("`chmod 600 {$root}/state/app.key`.")
            ->and($fixes)->toContain("`chmod 700 {$root}/state`.")
            ->and($fixes)->toContain("`chmod 600 {$root}/database/app.sqlite`, or `doctor --fix`.");
        (fixOf($findings[2])->apply)();
        expect(fileperms("{$root}/database/app.sqlite") & 0o777)->toBe(0o600)
            ->and(severities(new PermissionsCheck()->run(context($root, ['TINVAR_ENV' => 'production'], ['keyFile' => "{$root}/state/app.key"]))))->toBe(['problem', 'problem']);
    });
    // Review S8/C5: without ext-posix the owner of the secrets' files went
    // unchecked and the check passed. ADR 0212: never skipped -- it says so.
    it('says it cannot check who owns the secrets where ext-posix is not loaded, rather than passing', function () {
        $root = app();
        mkdir("{$root}/state", 0o700);
        file_put_contents("{$root}/state/app.key", str_repeat('k', 44));
        chmod("{$root}/state/app.key", 0o600);
        $autoload = realpath(\Composer\InstalledVersions::getRootPackage()['install_path']) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; '
            . 'foreach (new Tinvar\Console\Doctor\Checks\PermissionsCheck()->run(new Tinvar\Console\Doctor\DoctorContext(root: ' . var_export($root, true)
            . ', env: ["TINVAR_ENV" => "local"], keyFile: ' . var_export("{$root}/state/app.key", true) . ')) as $f) { echo $f->severity->value, ": ", $f->message, "\n"; }';
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $code], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        expect($output)->toContain('problem: ')
            ->and($output)->toContain('ext-posix is not loaded')
            ->and($output)->not->toContain('ok: ');
    });
    it('finds the database where the app keeps it, not only where the skeleton does', function () {
        $root = app();
        mkdir("{$root}/database");
        touch("{$root}/database/shop.sqlite");
        chmod("{$root}/database/shop.sqlite", 0o644);

        $findings = new PermissionsCheck()->run(context($root, [], ['database' => static fn (): string => "{$root}/database/shop.sqlite"]));

        expect(severities($findings))->toBe(['warning'])
            ->and($findings[0]->fix)->toContain("chmod 600 {$root}/database/shop.sqlite");
    });
});

describe('the doctor', function () {
    it('reports every check at once, counts the problems, and exits 1 on any', function () {
        $checks = [
            new class implements DoctorCheck {
                public function title(): string
                {
                    return 'One';
                }

                public function run(DoctorContext $context): array
                {
                    return [Finding::problem('Broken.', 'Mend it.'), Finding::warning('Odd.', 'Look.')];
                }
            },
            new class implements DoctorCheck {
                public function title(): string
                {
                    return 'Two';
                }

                public function run(DoctorContext $context): array
                {
                    throw new \RuntimeException('it fell over');
                }
            },
        ];
        $out = '';
        $status = new Doctor($checks)->run(context(app()), false, static function (string $text) use (&$out): void {
            $out .= $text;
        });

        expect($status)->toBe(1)
            ->and($out)->toContain("One\n  problem  Broken.\n           fix: Mend it.\n  warning  Odd.")
            ->and($out)->toContain('The check itself failed: it fell over')
            ->and($out)->toContain('2 problems, 1 warning.');
    });

    it('does the safe fixes first with --fix, then reports again', function () {
        $fixed = false;
        $check = new class ($fixed) implements DoctorCheck {
            public function __construct(private bool &$fixed) {}

            public function title(): string
            {
                return 'Secret';
            }

            public function run(DoctorContext $context): array
            {
                return $this->fixed
                    ? [Finding::passed('Generated.')]
                    : [Finding::warning('Missing.', 'key:generate', new \Tinvar\Console\Doctor\SafeFix('generate it', function (): string {
                        $this->fixed = true;

                        return 'generated it';
                    }))];
            }
        };
        $out = '';
        $status = new Doctor([$check])->run(context(app()), true, static function (string $text) use (&$out): void {
            $out .= $text;
        });

        expect($status)->toBe(0)
            ->and($out)->toContain('fixed: generated it')
            ->and($out)->toContain("Secret\n  ok       Generated.")
            ->and($out)->toContain('No problems.')
            ->and(Severity::from('problem'))->toBe(Severity::Problem);
    });

    it('shows a control character in what a finding says, never sends it to the terminal', function () {
        // A finding carries what a database server or an exception said:
        // the escape sequence in one would retitle the window or clear the lines above.
        $report = Doctor::report([['Database', [
            Finding::problem("The database cannot be reached: FATAL \e]0;pwned\x07\e[2J done\xc2\x9b", "Check \e[31mthe host\nthen the port."),
        ]]]);

        expect($report)->not->toMatch('/[\x00-\x09\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/')
            ->and($report)->toContain("FATAL \u{241B}]0;pwned\u{2407}\u{241B}[2J done")
            ->and($report)->toContain("Check \u{241B}[31mthe host\n                then the port.");
    });
});
