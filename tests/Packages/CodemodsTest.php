<?php

declare(strict_types=1);

/**
 * Package codemods: "run only with --allow-package-codemods, sandboxed,
 * and any diff touching Authorize, Public or NoAuthorization is flagged for
 * review" (tinvar-spec.md).
 */

namespace Tinvar\Tests\Packages\Codemods;

use Tinvar\Packages\AllowedPackage;
use Tinvar\Packages\Codemods;
use Tinvar\Packages\Ecosystem;
use Tinvar\Packages\Lockfiles;
use Tinvar\Packages\PackageLock;
use Tinvar\Packages\PackagePolicy;
use Tinvar\Packages\SupplyChain;
use Tinvar\Tests\Packages\Fixtures\Workspace;

require_once __DIR__ . '/Fixtures/Workspace.php';

const PAGE = <<<'PHP'
<?php

namespace App;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Page;

final class OrderPage
{
    #[Page('/orders/{order}')]
    #[Authorize('view', 'order')]
    public function load(Order $order): string
    {
        return 'old';
    }
}

PHP;

beforeEach(function () {
    $this->workspace = new Workspace();
    $this->policy = new PackagePolicy(packages: [new AllowedPackage('acme/billing', Ecosystem::Composer)]);
    $this->app = $this->workspace->root . '/app';
});

afterEach(function () {
    $this->workspace->remove();
});

/**
 * The workspace with acme/billing installed and locked, shipping $script as
 * its one codemod, and an app of one tracked page and an untracked secret.
 */
function withCodemod(object $test, string $script, string $path = 'codemods/upgrade.php', int $timeout = 5): Codemods
{
    $workspace = $test->workspace;
    $workspace->composer('acme/billing', ['extra' => ['tinvar' => ['codemods' => [$path]]]]);
    $workspace->write();
    $workspace->file('vendor/acme/billing/codemods/upgrade.php', $script);

    $workspace->file('app/src/OrderPage.php', PAGE);
    $workspace->file('app/.gitignore', ".env\n");
    $workspace->file('app/.env', "APP_KEY=secret-in-the-app\n");
    exec('git -C ' . escapeshellarg($test->app) . ' init -q 2>&1 && git -C ' . escapeshellarg($test->app) . ' add -A 2>&1', $output, $status);
    expect($status)->toBe(0, implode("\n", $output));

    $lockfiles = new Lockfiles($workspace->root);
    PackageLock::of($test->policy, [...$lockfiles->composer(), ...$lockfiles->npm()])->write($workspace->root);

    return new Codemods(new SupplyChain($workspace->root, $test->policy), timeout: $timeout);
}

it('runs nothing without --allow-package-codemods', function () {
    $codemods = withCodemod($this, "<?php file_put_contents(\$argv[1] . '/ran', 'yes');\n");

    $codemods->run($this->app, allowed: false);
})->throws(\RuntimeException::class, 'Package codemods run only with --allow-package-codemods');

it('writes what a codemod changed to a patch, and leaves the app alone', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("'old'", "'new'", file_get_contents($file)));
    file_put_contents($argv[1] . '/src/Added.php', "<?php\n");
    PHP);

    $result = $codemods->run($this->app, allowed: true);

    expect($result->ran)->toBe(['acme/billing codemods/upgrade.php'])
        ->and($result->failed)->toBe([])
        ->and($result->flagged)->toBe(['src/Added.php:1: new PHP file'])
        ->and($result->patch)->toContain("--- a/src/OrderPage.php\n+++ b/src/OrderPage.php")
        ->and($result->patch)->toContain("-        return 'old';\n+        return 'new';")
        ->and($result->patch)->toContain("+++ b/src/Added.php")
        ->and(file_get_contents($this->app . '/src/OrderPage.php'))->toBe(PAGE);
});

it('flags a change that drops an authorization check', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("    #[Authorize('view', 'order')]\n", "    #[\\Tinvar\\Attributes\\PublicAccess]\n", file_get_contents($file)));
    PHP);

    $result = $codemods->run($this->app, allowed: true);

    expect($result->needsReview())->toBeTrue()
        ->and($result->flagged)->toBe([
            "src/OrderPage.php:11: -    #[Authorize('view', 'order')]",
            'src/OrderPage.php:11: +    #[\Tinvar\Attributes\PublicAccess]',
            "src/OrderPage.php:12: authorization of App\\OrderPage::load() was #[\\Tinvar\\Attributes\\Authorize('view', 'order')], now #[\\Tinvar\\Attributes\\PublicAccess]",
        ]);
});

// An alias can hide the name: any line of an attribute block that changes
// is flagged, whatever the attribute is called.
it('flags an attribute change, whatever it is called', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("#[Page('/orders/{order}')]", "#[Page('/o/{order}')]", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)
        ->toBe(["src/OrderPage.php:10: -    #[Page('/orders/{order}')]", "src/OrderPage.php:10: +    #[Page('/o/{order}')]"]);
});

// PHP's class names are case-insensitive, and an import can rename one.
it('flags an authorization attribute in any case and under any alias, in a file that had none', function () {
    $this->workspace->file('app/src/OrderList.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\Page;

    final class OrderList
    {
        #[Page('/orders')]
        public function load(): string
        {
            return 'list';
        }
    }

    PHP);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderList.php';
    file_put_contents($file, str_replace(
        ["use Tinvar\\Attributes\\Page;\n", "#[Page('/orders')]"],
        ["use Tinvar\\Attributes\\Page;\nuse Tinvar\\Attributes\\publicaccess as Open;\n", "#[Page('/orders'), Open]"],
        file_get_contents($file),
    ));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/OrderList.php:6: +use Tinvar\Attributes\publicaccess as Open;',
        "src/OrderList.php:9: -    #[Page('/orders')]",
        "src/OrderList.php:10: +    #[Page('/orders'), Open]",
        'src/OrderList.php:11: authorization of App\OrderList::load() was none, now #[\Tinvar\Attributes\publicaccess]',
    ]);
});

// The line that changed names nothing: the group's prefix sits on a line of
// its own, and `Authorize` below it now resolves somewhere else.
it('flags an import that sends an authorization attribute somewhere else', function () {
    $this->workspace->file('app/src/Refunds.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\{
        Authorize,
        Page,
    };

    final class Refunds
    {
        #[Page('/refunds/{order}')]
        #[Authorize('refund', 'order')]
        public function load(Order $order): string
        {
            return 'refunds';
        }
    }

    PHP);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Refunds.php';
    file_put_contents($file, str_replace('use Tinvar\Attributes\{', 'use App\Shim\{', file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/Refunds.php:5: -use Tinvar\Attributes\{',
        'src/Refunds.php:5: +use App\Shim\{',
        "src/Refunds.php:14: authorization of App\\Refunds::load() was #[\\Tinvar\\Attributes\\Authorize('refund', 'order')], now #[\\App\\Shim\\Authorize('refund', 'order')]",
    ]);
});

it('flags an argument changed inside an attribute written over several lines', function () {
    $this->workspace->file('app/src/Refunds.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\Authorize;

    final class Refunds
    {
        #[Authorize(
            'refund',
            'order',
        )]
        public function load(Order $order): string
        {
            return 'refunds';
        }
    }

    PHP);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Refunds.php';
    file_put_contents($file, str_replace("        'refund',\n", "        'browse',\n", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        "src/Refunds.php:10: -        'refund',",
        "src/Refunds.php:10: +        'browse',",
        "src/Refunds.php:13: authorization of App\\Refunds::load() was #[\\Tinvar\\Attributes\\Authorize('refund', 'order')], now #[\\Tinvar\\Attributes\\Authorize('browse', 'order')]",
    ]);
});

// Neither added line is an attribute, or names one: only comparing what
// the method declares, before against after, sees the check go.
it('flags an authorization check commented out by lines that never name it', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("    #[Authorize('view', 'order')]\n", "    /*\n    #[Authorize('view', 'order')]\n    */\n", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        "src/OrderPage.php:14: authorization of App\\OrderPage::load() was #[\\Tinvar\\Attributes\\Authorize('view', 'order')], now none",
    ]);
});

// ClassAttributes reads a class's checks off its ancestors and traits too.
it('flags a class that now inherits from somewhere else', function () {
    $this->workspace->file('app/src/Report.php', <<<'PHP'
    <?php

    namespace App;

    final class Report extends AdminPage
    {
        public function load(): string
        {
            return 'report';
        }
    }

    PHP);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Report.php';
    file_put_contents($file, str_replace('extends AdminPage', 'extends Page', file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/Report.php:5: authorization of App\Report was extends \App\AdminPage, now extends \App\Page',
    ]);
});

it('flags a PHP file a codemod deletes, and what it took with it', function () {
    $codemods = withCodemod($this, "<?php unlink(\$argv[1] . '/src/OrderPage.php');\n");

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/OrderPage.php:1: deleted PHP file',
        "src/OrderPage.php:12: authorization of App\\OrderPage::load() was #[\\Tinvar\\Attributes\\Authorize('view', 'order')], now none",
    ]);
});

// What does not parse cannot be compared: every line it changes is flagged.
it('flags every changed line of a file that no longer parses', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("return 'old';", "return 'old'", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/OrderPage.php:1: not valid PHP after the codemods, so every line they change is flagged',
        "src/OrderPage.php:14: -        return 'old';",
        "src/OrderPage.php:14: +        return 'old'",
    ]);
});

// Inside a hunk a line is a change by its first character alone: a removed
// "-- ..." reads "--- ..." and an added "++ ..." reads "+++ ...", which only
// the file's own header may be taken for.
it('reads a changed line that starts like a diff header as a change', function () {
    $this->workspace->file('app/docs/access.txt', "Who may see what:\n-- OrderPage::load needs #[Authorize('view', 'order')]\n");
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    file_put_contents($argv[1] . '/docs/access.txt', "Who may see what:\n++ OrderPage::load is #[PublicAccess]\n");
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        "docs/access.txt:2: --- OrderPage::load needs #[Authorize('view', 'order')]",
        'docs/access.txt:2: +++ OrderPage::load is #[PublicAccess]',
    ]);
});

it('keeps a codemod from the network, processes, secrets and anything outside its copy', function () {
    putenv('TINVAR_CODEMOD_SECRET=from-the-shell');
    $real = $this->app . '/src/OrderPage.php';
    $codemods = withCodemod($this, <<<PHP
    <?php
    \$seen = [
        'outside' => @file_get_contents('/etc/hosts') !== false,
        'real app' => @file_put_contents('{$real}', 'pwned') !== false,
        'exec' => function_exists('exec') || function_exists('proc_open') || function_exists('shell_exec'),
        'socket' => function_exists('fsockopen') || function_exists('stream_socket_client') || function_exists('curl_init'),
        'url' => @file_get_contents('http://127.0.0.1:9/') !== false || ini_get('allow_url_fopen') === '1',
        'env' => getenv('TINVAR_CODEMOD_SECRET'),
        'ffi' => class_exists('FFI', false) && ini_get('ffi.enable') !== '0',
        'untracked' => file_exists(\$argv[1] . '/.env'),
    ];
    file_put_contents(\$argv[1] . '/seen.json', json_encode(\$seen) . "\\n");
    PHP);

    try {
        $result = $codemods->run($this->app, allowed: true);
    } finally {
        putenv('TINVAR_CODEMOD_SECRET');
    }

    expect($result->failed)->toBe([])
        ->and($result->patch)->toContain('+{"outside":false,"real app":false,"exec":false,"socket":false,"url":false,"env":false,"ffi":false,"untracked":false}')
        ->and(file_get_contents($real))->toBe(PAGE);
});

// A database or cache client is a class, and no ini setting can take a
// class away; the operating system's sandbox is what keeps it off the
// network. A listener in this process sees whether anything got through.
it('keeps a codemod off the network through the clients PHP was built with', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse($error);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    $codemods = withCodemod($this, <<<PHP
    <?php
    \$tried = [];
    foreach (['pgsql:host=127.0.0.1;port={$port};connect_timeout=1', 'mysql:host=127.0.0.1;port={$port}'] as \$dsn) {
        try {
            \$tried[] = \$dsn;
            new PDO(\$dsn, 'u', 'p', [PDO::ATTR_TIMEOUT => 1]);
        } catch (Throwable) {
        }
    }
    if (class_exists('Redis')) {
        try { (new Redis())->connect('127.0.0.1', {$port}, 1.0); } catch (Throwable) {}
    }
    @file_put_contents('tcp://127.0.0.1:{$port}', 'x');
    file_put_contents(\$argv[1] . '/tried', implode("\\n", \$tried));
    PHP);

    $result = $codemods->run($this->app, allowed: true);
    $read = [$server];
    $write = $except = null;
    $pending = stream_select($read, $write, $except, 0);
    fclose($server);

    expect($result->failed)->toBe([])
        ->and($pending)->toBe(0);
});

it('stops a codemod that runs too long, and keeps nothing it did', function () {
    $codemods = withCodemod($this, "<?php file_put_contents(\$argv[1] . '/half', 'x'); while (true) {}\n", timeout: 1);

    $result = $codemods->run($this->app, allowed: true);

    expect($result->ran)->toBe([])
        ->and($result->failed)->toHaveCount(1)
        ->and($result->patch)->toBe('');
});

it('refuses a codemod path that leaves the package', function () {
    $codemods = withCodemod($this, "<?php\n", '../../../evil.php');

    $codemods->run($this->app, allowed: true);
})->throws(\RuntimeException::class, 'declares a codemod that is not a PHP file inside it');

it('runs no codemod from a package whose files changed since it was locked', function () {
    $codemods = withCodemod($this, "<?php\n");
    $this->workspace->file('vendor/acme/billing/codemods/upgrade.php', "<?php file_put_contents(\$argv[1] . '/x', 'y');\n");

    $codemods->run($this->app, allowed: true);
})->throws(\RuntimeException::class, 'its installed files changed');
