<?php

declare(strict_types=1);

/**
 * `tinvar new` (ADR 0156), without installing anything: the questions'
 * flags and defaults, the refusals, and the files it writes from this
 * checkout's skeleton -- the .env, the manifests, tenancy -- with each
 * secret in a file outside the app.
 */

namespace Tinvar\Tests\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Console\NewApp\MonorepoSkeleton;
use Tinvar\Console\NewApp\NewAppCommand;
use Tinvar\Console\NewApp\PackagistSkeleton;
use Tinvar\Console\NewApp\Processes;
use Tinvar\Console\NewApp\SkeletonSource;
use Tinvar\Console\NewApp\Skeletons;
use Tinvar\Console\NewApp\TenancySetup;

/** The repository's checkout, or null where the framework runs alone. */
function workspace(): ?string
{
    return Skeletons::workspace();
}

/** A scratch directory, and its state directory, set for the test. */
function scratch(): string
{
    $work = sys_get_temp_dir() . '/tinvar-new-' . bin2hex(random_bytes(4));
    mkdir($work, 0o700, true);
    Scratch::$work = (string) realpath($work);
    Scratch::$state = getenv('XDG_STATE_HOME');
    putenv('XDG_STATE_HOME=' . Scratch::$work . '/state');

    return Scratch::$work;
}

final class Scratch
{
    public static ?string $work = null;

    public static string|false $state = false;
}

/**
 * A source over this checkout's skeleton that offers $kits.
 *
 * @param array<string, KitDeclaration> $kits
 */
function source(array $kits = [], bool $accounts = false): SkeletonSource
{
    $workspace = workspace();
    \assert($workspace !== null);

    return new class ($workspace, $kits, $accounts) implements SkeletonSource {
        private MonorepoSkeleton $inner;

        /** @param array<string, KitDeclaration> $kits */
        public function __construct(string $workspace, private array $kits, private bool $accounts)
        {
            $this->inner = new MonorepoSkeleton($workspace);
        }

        public function describe(): string
        {
            return 'the test\'s checkout';
        }

        public function fetch(string $directory, array $extra, Processes $processes): void
        {
            $this->inner->fetch($directory, $extra, $processes);
        }

        public function prepareNpm(string $directory, Processes $processes): void {}

        public function shipsAccounts(): bool
        {
            return $this->accounts;
        }

        public function kits(Processes $processes): array
        {
            return $this->kits;
        }
    };
}

/**
 * @param list<string> $arguments
 * @return array{int, string, string}
 */
function made(array $arguments, ?SkeletonSource $source = null): array
{
    $out = '';
    $err = '';
    $status = new NewAppCommand(
        static function (string $t) use (&$out): void {
            $out .= $t;
        },
        static function (string $t) use (&$err): void {
            $err .= $t;
        },
        new Processes(static function (string $t) use (&$out): void {
            $out .= $t;
        }),
        static fn (?string $from): SkeletonSource => $source ?? source(),
        (string) Scratch::$work,
    )->run($arguments);

    return [$status, $out, $err];
}

afterEach(function () {
    if (Scratch::$work !== null) {
        exec('rm -rf ' . escapeshellarg(Scratch::$work));
        Scratch::$work = null;
    }
    putenv(Scratch::$state === false ? 'XDG_STATE_HOME' : 'XDG_STATE_HOME=' . Scratch::$state);
});

it('makes an app from the skeleton with every default: SQLite, one tenant, mail to a log', function () {
    $work = scratch();

    [$status, $out] = made(['my-app', '--no-install']);
    $app = "{$work}/my-app";
    $manifest = json_decode((string) file_get_contents("{$app}/composer.json"), true);
    $env = (string) file_get_contents("{$app}/.env");

    expect($status)->toBe(0)
        ->and($out)->toContain('my-app is made.')
        ->and(is_file("{$app}/app/Kernel.php"))->toBeTrue()
        ->and(is_dir("{$app}/vendor"))->toBeFalse()
        ->and(glob("{$app}/database/*.sqlite") ?: [])->toBe([])
        ->and(is_file("{$app}/.packages/framework/composer.json"))->toBeTrue()
        ->and(is_dir("{$app}/.packages/framework/tests"))->toBeFalse()
        ->and(array_column($manifest['repositories'], 'url'))->toContain('.packages/framework')
        ->and($manifest['minimum-stability'])->toBe('dev')
        ->and($manifest['conflict'])->toHaveKey('nesbot/carbon')
        ->and($manifest['require'])->not->toHaveKey('tinvar/tenancy')
        ->and($env)->toContain("TINVAR_ENV=local\n")
        ->and($env)->toContain('# TINVAR_MAIL_PROVIDER=')
        ->and($env)->not->toContain('APP_DATABASE_DRIVER')
        ->and(fileperms("{$app}/.env") & 0o777)->toBe(0o600)
        ->and(is_file("{$app}/app/Tenancy/Tenants.php"))->toBeFalse();
})->skip(workspace() === null, 'the repository\'s checkout');

it('writes a server database, mail provider and tenancy by subdomain, the password outside the app', function () {
    $work = scratch();

    [$status] = made(['shop-app', '--no-install', '--database=postgres', '--database-host=db.local', '--tenancy=subdomain', '--mail=postmark']);
    $app = "{$work}/shop-app";
    $env = (string) file_get_contents("{$app}/.env");
    $password = glob("{$work}/state/tinvar/shop-app-*.database-password") ?: [];
    $kernel = (string) file_get_contents("{$app}/app/Kernel.php");

    expect($status)->toBe(0)
        ->and($password)->toHaveCount(1)
        ->and(fileperms($password[0]) & 0o777)->toBe(0o600)
        ->and($env)->toContain("APP_DATABASE_DRIVER=postgres\nAPP_DATABASE_HOST=db.local\nAPP_DATABASE_PORT=5432\nAPP_DATABASE_NAME=shop_app\nAPP_DATABASE_USER=shop_app\n")
        ->and($env)->toContain("APP_DATABASE_PASSWORD_FILE={$password[0]}")
        ->and($env)->not->toContain(trim((string) file_get_contents($password[0])))
        ->and($env)->toContain("TINVAR_MAIL_PROVIDER=postmark\n# TINVAR_POSTMARK_TOKEN_FILE=")
        ->and($env)->toContain('TINVAR_APP_DOMAIN=localhost')
        ->and(json_decode((string) file_get_contents("{$app}/composer.json"), true)['require'])->toHaveKey('tinvar/tenancy')
        ->and(is_file("{$app}/.packages/tenancy/composer.json"))->toBeTrue()
        ->and($kernel)->toContain('Tenancy::tree()')
        ->and($kernel)->toContain('->instance(TenantHosts::class, $tenants)')
        ->and($kernel)->toContain('tenants: new Tenancy($container->resolver(), $tenants),')
        ->and((string) file_get_contents("{$app}/app/Tenancy/Tenants.php"))->toContain('new Subdomains(');

    foreach (["{$app}/app/Kernel.php", "{$app}/app/Tenancy/Tenants.php", "{$app}/app/Database/Models/Tenant.php", "{$app}/database/migrations/" . TenancySetup::MIGRATION] as $file) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $lintStatus);
        expect($lintStatus)->toBe(0, "{$file}: " . implode("\n", $lint));
    }
})->skip(workspace() === null, 'the repository\'s checkout');

it('finds a tenant by its own domain where asked', function () {
    $work = scratch();

    expect(made(['shop-app', '--no-install', '--tenancy=domain'])[0])->toBe(0)
        ->and((string) file_get_contents("{$work}/shop-app/app/Tenancy/Tenants.php"))->toContain("->where('host', \$host)")
        ->and((string) file_get_contents("{$work}/shop-app/.env"))->not->toContain('TINVAR_APP_DOMAIN');
})->skip(workspace() === null, 'the repository\'s checkout');

it('includes the accounts kit where the source has one, and refuses --accounts where it has none', function () {
    $work = scratch();
    $kit = new KitDeclaration('tinvar/accounts', 'accounts', 'Accounts', 'Tinvar\\Accounts\\AccountsKit');

    [$refused, , $err] = made(['one-app', '--no-install', '--accounts']);
    expect($refused)->toBe(64)
        ->and($err)->toContain('has no accounts kit yet')
        ->and(file_exists("{$work}/one-app"))->toBeFalse();

    // The kit's package is the source's to serve: here, only named.
    $source = new class (source([]), $kit) implements SkeletonSource {
        public function __construct(private SkeletonSource $inner, private KitDeclaration $kit) {}

        public function describe(): string
        {
            return 'a source with accounts';
        }

        public function fetch(string $directory, array $extra, Processes $processes): void
        {
            $this->inner->fetch($directory, array_values(array_diff($extra, ['tinvar/accounts'])), $processes);
            $manifest = json_decode((string) file_get_contents("{$directory}/composer.json"), true);
            $manifest['require']['tinvar/accounts'] = '0.4.*';
            file_put_contents("{$directory}/composer.json", json_encode($manifest));
        }

        public function prepareNpm(string $directory, Processes $processes): void {}

        public function shipsAccounts(): bool
        {
            return false;
        }

        public function kits(Processes $processes): array
        {
            return ['accounts' => $this->kit];
        }
    };
    expect(made(['two-app', '--no-install', '--accounts'], $source)[0])->toBe(0)
        ->and(json_decode((string) file_get_contents("{$work}/two-app/composer.json"), true)['require'])->toHaveKey('tinvar/accounts');
})->skip(workspace() === null, 'the repository\'s checkout');

it('takes the accounts a skeleton ships as they are, and will not take them out', function () {
    $work = scratch();

    [$refused, , $err] = made(['one-app', '--no-install', '--no-accounts'], source(accounts: true));
    expect($refused)->toBe(64)
        ->and($err)->toContain('ships accounts wired')
        ->and(made(['two-app', '--no-install', '--accounts'], source(accounts: true))[0])->toBe(0)
        ->and(is_dir("{$work}/two-app/app/Auth/Account"))->toBeTrue()
        ->and((new MonorepoSkeleton((string) workspace()))->shipsAccounts())->toBeTrue();
})->skip(workspace() === null, 'the repository\'s checkout');

it('refuses a directory that is not empty, and leaves it as it was', function () {
    $work = scratch();
    mkdir("{$work}/taken");
    file_put_contents("{$work}/taken/notes.txt", "mine\n");

    [$status, , $err] = made(['taken', '--no-install']);

    expect($status)->toBe(64)
        ->and($err)->toContain('is not empty')
        ->and(scandir("{$work}/taken"))->toBe(['.', '..', 'notes.txt'])
        ->and(made(['My App', '--no-install'])[0])->toBe(64)
        ->and(made(['my-app', '--database=oracle'])[2])->toContain('--database is sqlite, postgres, mysql')
        ->and(made(['my-app', '--frobnicate'])[0])->toBe(64);
});

it('takes the skeleton from this checkout while nothing is published, and from Packagist on request', function () {
    expect(Skeletons::choose('packagist'))->toBeInstanceOf(PackagistSkeleton::class)
        ->and(static fn () => Skeletons::choose('github'))->toThrow(\InvalidArgumentException::class);
    if (workspace() !== null) {
        expect(Skeletons::choose(null))->toBeInstanceOf(MonorepoSkeleton::class)
            ->and(MonorepoSkeleton::isWorkspace((string) workspace()))->toBeTrue();
    }
});

it('finds every line tenancy edits in the skeleton\'s Kernel, once each', function () {
    $kernel = (string) file_get_contents((string) workspace() . '/packages/skeleton/app/Kernel.php');

    foreach (TenancySetup::ANCHORS as $name => $anchor) {
        expect(substr_count($kernel, $anchor))->toBe(1, "the skeleton's Kernel holds the {$name} line tenancy edits once");
    }
})->skip(workspace() === null, 'the repository\'s checkout');
