<?php

declare(strict_types=1);

/**
 * `tinvar new` (ADR 0157), without installing anything: the questions'
 * flags and defaults, the refusals, and the files it writes from this
 * checkout's skeleton -- the .env, the manifests, tenancy -- with each
 * secret in a file outside the app.
 */

namespace Tinvar\Tests\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Console\NewApp\AppDirectory;
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
        // No package is named that does not exist: AccountsKit is tinvar/framework's.
        ->and($err)->not->toContain('composer require tinvar/accounts')
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
    [$status, $out] = made(['two-app', '--no-install', '--accounts'], $source);
    expect($status)->toBe(0)
        ->and(json_decode((string) file_get_contents("{$work}/two-app/composer.json"), true)['require'])->toHaveKey('tinvar/accounts')
        // Nothing installed it, so the next steps say to.
        ->and($out)->toContain('bin/tinvar kit:install tinvar/accounts');
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

it('refuses an empty directory other users may write into, where they could swap the code it runs', function () {
    $work = scratch();
    foreach (['shared' => 0o777, 'grouped' => 0o775] as $name => $mode) {
        mkdir("{$work}/{$name}");
        chmod("{$work}/{$name}", $mode);
    }

    [$status, , $err] = made(['shared', '--no-install']);

    expect($status)->toBe(64)
        ->and($err)->toContain('writable by other users')
        ->and(scandir("{$work}/shared"))->toBe(['.', '..'])
        ->and(made(['grouped', '--no-install'])[0])->toBe(64);

    // A link, even to a directory of this user's own, is not taken for it.
    mkdir("{$work}/target", 0o755);
    symlink("{$work}/target", "{$work}/linked");
    expect(made(['linked', '--no-install'])[2])->toContain('is a link');
})->skip(workspace() === null, 'the repository\'s checkout');

it('refuses a directory under one every user may write into and is not sticky, and takes one under /tmp\'s kind', function () {
    $work = scratch();
    mkdir("{$work}/open");
    chmod("{$work}/open", 0o777);
    mkdir("{$work}/sticky");
    chmod("{$work}/sticky", 0o1777);

    [$status, , $err] = made(['open/my-app', '--no-install']);

    expect($status)->toBe(64)
        ->and($err)->toContain('writable by every user, and not sticky')
        ->and(file_exists("{$work}/open/my-app"))->toBeFalse()
        ->and(made(['sticky/my-app', '--no-install'])[0])->toBe(0)
        ->and(fileperms("{$work}/sticky/my-app") & 0o777)->toBe(0o755);
})->skip(workspace() === null, 'the repository\'s checkout');

it('makes the directory itself, and refuses one that appeared in its place after the answers', function () {
    $work = scratch();
    mkdir("{$work}/late", 0o755);
    file_put_contents("{$work}/late/planted.php", "<?php\n");

    expect(static fn () => AppDirectory::claim("{$work}/late"))->toThrow(\RuntimeException::class, 'is not empty')
        ->and(AppDirectory::claim("{$work}/fresh/app"))->toBe("{$work}/fresh/app")
        ->and(fileperms("{$work}/fresh/app") & 0o777)->toBe(0o755);
})->skip(workspace() === null, 'the repository\'s checkout');

it('hands the new app\'s commands none of what its .env sets, so its own answers reach them', function () {
    $work = scratch();
    $previous = ['TINVAR_MAIL_PROVIDER' => getenv('TINVAR_MAIL_PROVIDER'), 'TINVAR_SOCKET' => getenv('TINVAR_SOCKET')];
    putenv('TINVAR_MAIL_PROVIDER=ses');
    putenv('TINVAR_SOCKET=/run/another-app/renderer.sock');

    $processes = new class (static function (string $t): void {}) extends Processes {
        /** @var list<array{list<string>, array<string, string>|null}> */
        public array $ran = [];

        public function run(array $command, string $directory, ?array $env = null): int
        {
            $this->ran[] = [$command, $env];

            return 0;
        }

        public function has(string $program): bool
        {
            return false;
        }
    };

    try {
        $status = new NewAppCommand(
            static function (string $t): void {},
            static function (string $t): void {},
            $processes,
            static fn (?string $from): SkeletonSource => source(),
            $work,
        )->run(['my-app', '--mail=postmark', '--deploy=fly']);
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    $deploy = array_values(array_filter($processes->ran, static fn (array $run): bool => \in_array('deploy:init', $run[0], true)));
    expect($status)->toBe(0)
        ->and($deploy)->toHaveCount(1)
        ->and($deploy[0][1])->toBeArray()
        ->and($deploy[0][1])->not->toHaveKey('TINVAR_MAIL_PROVIDER')
        ->and($deploy[0][1])->not->toHaveKey('TINVAR_ENV')
        ->and($deploy[0][1])->not->toHaveKey('TINVAR_SOCKET')
        ->and($deploy[0][1])->toHaveKey('PATH');
})->skip(workspace() === null, 'the repository\'s checkout');

it('resolves the path once, following only a link of this user\'s or root\'s, and writes where it checked', function () {
    $work = scratch();
    mkdir("{$work}/real", 0o755);
    symlink("{$work}/real", "{$work}/via");
    $uid = (int) posix_geteuid();

    expect(AppDirectory::claim("{$work}/via/my-app", $uid))->toBe("{$work}/real/my-app")
        ->and(is_dir("{$work}/real/my-app"))->toBeTrue()
        // The same link, taken as another user's: it decides where the app goes.
        ->and(AppDirectory::problem("{$work}/via/other-app", $uid + 4242))->toContain('is a link another user made')
        ->and(file_exists("{$work}/real/other-app"))->toBeFalse();
})->skip(!\function_exists('posix_geteuid'), 'posix');

it('refuses a directory above whose group or every user may swap what is in it, unless sticky or the group is the user\'s alone', function () {
    expect(AppDirectory::refusesAbove(0, 0o40755, 501))->toBeNull()
        ->and(AppDirectory::refusesAbove(0, 0o41777, 501))->toBeNull()
        // The user's own, and its group provably theirs alone: taken.
        ->and(AppDirectory::refusesAbove(501, 0o40775, 501, true))->toBeNull()
        // The user's own, but its group not provably theirs alone -- staff, say: refused.
        ->and(AppDirectory::refusesAbove(501, 0o40775, 501))->toContain('writable by its group, which is not provably yours alone')
        ->and(AppDirectory::refusesAbove(501, 0o40775, 501, false))->toContain('not provably yours alone')
        ->and(AppDirectory::refusesAbove(0, 0o40775, 501))->toContain('writable by its group, not yours')
        ->and(AppDirectory::refusesAbove(0, 0o40777, 501))->toContain('writable by every user')
        ->and(AppDirectory::refusesAbove(501, 0o40777, 501))->toContain('writable by every user')
        ->and(AppDirectory::refusesAbove(502, 0o40755, 501))->toContain('another user\'s')
        // No user to compare: the modes alone, a group's write refused.
        ->and(AppDirectory::refusesAbove(502, 0o40755, null))->toBeNull()
        ->and(AppDirectory::refusesAbove(502, 0o41777, null))->toBeNull()
        ->and(AppDirectory::refusesAbove(502, 0o40777, null))->toContain('writable by every user')
        ->and(AppDirectory::refusesAbove(502, 0o40775, null))->toContain('writable by its group');
});

it('takes as the user\'s alone only a personal group: named as they are, with no other member by any reckoning', function () {
    $carson = ['name' => 'carson', 'uid' => 501, 'gid' => 501];
    $own = ['name' => 'carson', 'members' => [], 'gid' => 501];

    expect(AppDirectory::personal($own, $carson, []))->toBeTrue()
        ->and(AppDirectory::personal([...$own, 'members' => ['carson', 'root']], $carson, ['root']))->toBeTrue()
        // A shared group, whatever it lists: staff holds every local user on macOS.
        ->and(AppDirectory::personal(['name' => 'staff', 'members' => ['root'], 'gid' => 20], $carson, []))->toBeFalse()
        ->and(AppDirectory::personal([...$own, 'members' => ['mallory']], $carson, []))->toBeFalse()
        ->and(AppDirectory::personal($own, $carson, ['alice']))->toBeFalse()
        // Anything that could not be read is no.
        ->and(AppDirectory::personal(false, $carson, []))->toBeFalse()
        ->and(AppDirectory::personal($own, false, []))->toBeFalse()
        ->and(AppDirectory::personal($own, $carson, null))->toBeFalse();
});

it('refuses a group-writable directory of the user\'s own whose group is shared, saying to chmod g-w it', function () {
    $uid = posix_geteuid();
    $group = posix_getgrgid(posix_getegid());
    $user = posix_getpwuid($uid);
    $work = scratch();
    mkdir("{$work}/grouped", 0o755);
    chmod("{$work}/grouped", 0o775);

    // The user's primary group is shared wherever it is not named as they are.
    if (\is_array($group) && \is_array($user) && $group['name'] !== $user['name']) {
        expect(AppDirectory::personalGroup(posix_getegid(), $uid))->toBeFalse()
            ->and(AppDirectory::problem("{$work}/grouped/app"))->toContain('not provably yours alone')
            ->and(AppDirectory::problem("{$work}/grouped/app"))->toContain("chmod g-w,o-w {$work}/grouped");
    }
    chmod("{$work}/grouped", 0o755);
    expect(AppDirectory::problem("{$work}/grouped/app"))->toBeNull();
})->skip(!\function_exists('posix_geteuid'), 'posix');

it('runs Composer for the published skeleton from an empty directory of its own, never one another user could plant a composer.json in', function () {
    // Composer reads the composer.json of the directory it runs in, and
    // loads the plugins that project's vendor/ holds and allows: one planted
    // in /tmp, or in the sticky directory an app is made under, would run
    // as the developer.
    $processes = new class (static function (string $t): void {}) extends Processes {
        /** @var list<array{list<string>, string, int, list<string>}> each run, of this and of each quiet copy of it */
        public static array $ran = [];

        public function run(array $command, string $directory, ?array $env = null): int
        {
            clearstatcache();
            self::$ran[] = [$command, $directory, fileperms($directory) & 0o777, array_values(array_diff(scandir($directory) ?: [], ['.', '..']))];

            return 0;
        }
    };
    $source = new PackagistSkeleton();

    $source->fetch(sys_get_temp_dir() . '/tinvar-new-' . bin2hex(random_bytes(4)) . '/my-app', [], $processes);
    $source->kits($processes);

    expect($processes::$ran)->toHaveCount(1 + \count(PackagistSkeleton::KNOWN_KITS));
    foreach ($processes::$ran as [$command, $directory, $mode, $contents]) {
        expect($command[0])->toBe('composer')
            ->and(rtrim($directory, '/'))->not->toBe(rtrim(sys_get_temp_dir(), '/'))
            ->and($mode)->toBe(0o700)
            ->and($contents)->toBe([])
            // Removed once Composer is done with it.
            ->and(file_exists($directory))->toBeFalse();
    }
});

it('asks Packagist for each kit a package of the framework declares, by the name it declares', function () {
    $processes = new class (static function (string $t): void {}) extends Processes {
        public function run(array $command, string $directory, ?array $env = null): int
        {
            return 0;
        }
    };
    $declared = [];
    foreach (glob(\dirname(__DIR__, 4) . '/*/composer.json') ?: [] as $file) {
        $kit = KitDeclaration::of(json_decode((string) file_get_contents($file), true));
        if ($kit !== null) {
            $declared[$kit->name] = $kit->package;
        }
    }

    expect($declared)->not->toBe([])
        ->and(array_map(static fn (KitDeclaration $kit): string => $kit->package, new PackagistSkeleton()->kits($processes)))->toBe($declared);
});

// ADR 0175: setup downloads FrankenPHP, but `new` runs it without next
// steps of its own, so `new` says to run frankenphp:install where setup
// left no binary for serve to find.
it('says to install FrankenPHP where the app has none to serve with', function () {
    $app = scratch() . '/app';
    mkdir($app);
    $path = getenv('PATH');
    $env = getenv('TINVAR_FRANKENPHP');
    putenv('PATH=/nonexistent');
    putenv('TINVAR_FRANKENPHP');
    try {
        expect(NewAppCommand::frankenPhpStep($app))->toContain('bin/tinvar frankenphp:install');

        mkdir("{$app}/tools");
        file_put_contents("{$app}/tools/frankenphp", '#!/bin/sh');
        chmod("{$app}/tools/frankenphp", 0o755);
        expect(NewAppCommand::frankenPhpStep($app))->toBeNull();
    } finally {
        putenv("PATH={$path}");
        putenv($env === false ? 'TINVAR_FRANKENPHP' : "TINVAR_FRANKENPHP={$env}");
    }
});
