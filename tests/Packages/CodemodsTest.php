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

// PHP counts a lone carriage return as a line break, and GNU diff does not:
// six of them on the first line put every line below six further down for
// AuthorizationMap than in the hunk, and on Linux the import added below
// -- which sends Abilities, as the attribute's unresolved text names it,
// to another class -- was checked against a blank line and went
// unflagged. macOS's diff counts them as PHP does, so what is asked here
// is the same on both: the file is flagged whole, whichever diff ran.
it('flags every changed line of a PHP file holding a carriage return that ends no line', function () {
    $this->workspace->file('app/src/Refunds.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\Authorize;

    final class Refunds
    {
        #[Authorize(Abilities::REFUND)]
        public function refund(Order $order): string
        {
            return 'refunded';
        }
    }

    PHP);
    $this->workspace->file('app/src/Abilities.php', "<?php\n\nnamespace App;\n\nfinal class Abilities\n{\n    public const REFUND = 'refund-any-order';\n}\n");
    $this->workspace->file('app/src/Customer/Abilities.php', "<?php\n\nnamespace App\\Customer;\n\nfinal class Abilities\n{\n    public const REFUND = 'refund-own-order';\n}\n");
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Refunds.php';
    $code = str_replace("<?php\n", "<?php\r\r\r\r\r\r\n", file_get_contents($file));
    file_put_contents($file, str_replace("use Tinvar\\Attributes\\Authorize;\n", "use Tinvar\\Attributes\\Authorize;\nuse App\\Customer\\Abilities;\n", $code));
    PHP);

    $flagged = $codemods->run($this->app, allowed: true)->flagged;

    expect($flagged)->toContain('src/Refunds.php:1: holds a carriage return that ends no line, which PHP counts as a line break and diff may not, so every line the codemods change is flagged')
        ->and(array_values(array_filter($flagged, static fn (string $flag): bool => str_ends_with($flag, ': +use App\Customer\Abilities;'))))->toHaveCount(1);
});

// An attribute's argument can be a constant declared anywhere -- in the
// class, beside it, in another file, as an enum case -- on a line that is
// no attribute and names none. Its text in the attribute stays the same
// while what it says changes.
it('flags a constant or an enum case an attribute could name, wherever it is declared', function () {
    $this->workspace->file('app/src/Refunds.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\Authorize;

    const AUDIT = 'refunds.audit';

    final class Refunds
    {
        public const string ABILITY = 'refund';

        #[Authorize(self::ABILITY, 'order')]
        public function load(Order $order): string
        {
            return 'refunds';
        }
    }

    enum Ability: string
    {
        case Refund = 'refund';
    }

    PHP);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Refunds.php';
    file_put_contents($file, str_replace("'refund", "'browse", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        "src/Refunds.php:7: -const AUDIT = 'refunds.audit';",
        "src/Refunds.php:7: +const AUDIT = 'browses.audit';",
        "src/Refunds.php:11: -    public const string ABILITY = 'refund';",
        "src/Refunds.php:11: +    public const string ABILITY = 'browse';",
        "src/Refunds.php:22: -    case Refund = 'refund';",
        "src/Refunds.php:22: +    case Refund = 'browse';",
    ]);
});

const AUDIT_PAGE = <<<'PHP'
<?php

namespace App;

use Tinvar\Attributes\Authorize;

final class Audit
{
    #[Authorize(AUDIT, 'order')]
    public function load(Order $order): string
    {
        return 'audit';
    }
}

PHP;

// A global constant can be declared by any code, before the file that
// seems to declare it, and by means no line names: define() qualified,
// handed to call_user_func(), called through a variable or a first-class
// callable, given a value made elsewhere, or named by no string at all.
// Authorization resting on one is flagged whenever anything changes, and
// a file that names define() has every changed line flagged.
it('flags authorization resting on a global constant, however a codemod declares it', function (string $boot, bool $namesDefine) {
    $this->workspace->file('app/src/Audit.php', AUDIT_PAGE);
    $this->workspace->file('app/src/boot.php', $boot);
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/boot.php';
    file_put_contents($file, str_replace("'refunds.audit'", "'browse'", file_get_contents($file)));
    PHP);

    $flagged = $codemods->run($this->app, allowed: true)->flagged;
    $header = 'src/boot.php:1: names define() or call_user_func(), which can declare a constant where nothing shows it, so every line the codemods change is flagged';
    $changed = array_values(array_filter($flagged, static fn (string $f): bool => str_starts_with($f, 'src/boot.php:') && str_contains($f, ": +") && str_contains($f, "'browse'")));

    expect($flagged)->toContain("src/Audit.php:10: authorization of App\\Audit::load() rests on the constant AUDIT, which a codemod can change where nothing shows it")
        ->and(\in_array($header, $flagged, true))->toBe($namesDefine)
        ->and($changed !== [])->toBe($namesDefine);
})->with([
    'define() qualified' => ["<?php\n\n\\define('App\\AUDIT', 'refunds.audit');\n", true],
    'call_user_func()' => ["<?php\n\ncall_user_func('define', 'App\\AUDIT', 'refunds.audit');\n", true],
    'a variable function' => ["<?php\n\n\$declare = 'define';\n\$declare('App\\AUDIT', 'refunds.audit');\n", true],
    'a first-class callable' => ["<?php\n\n\$declare = define(...);\n\$declare('App\\AUDIT', 'refunds.audit');\n", true],
    'a value made apart' => ["<?php\n\n\$ability = 'refunds.audit';\ndefine('App\\AUDIT', \$ability);\n", true],
    'a name no string spells' => ["<?php\n\n\$declare = strrev('enifed');\n\$declare('App\\AUDIT', 'refunds.audit');\n", false],
]);

// Class constants and enum cases are compile-time, and declared where the
// patch shows them: followed to what they rest on, and flagged only where
// that is a global constant -- or a class the app declares without them.
it('follows class constants and enum cases to what they rest on', function () {
    $this->workspace->file('app/src/Abilities.php', <<<'PHP'
    <?php

    namespace App;

    interface Abilities
    {
        public const string REFUND = 'refund';
        public const string BROWSE = self::REFUND . '.browse', AUDIT = PREFIX . '.audit';
    }

    enum Ability: string
    {
        case Export = 'export';
    }

    PHP);
    $this->workspace->file('app/src/Reports.php', <<<'PHP'
    <?php

    namespace App;

    use Tinvar\Attributes\Authorize;

    final class Reports
    {
        #[Authorize(ability: Abilities::BROWSE)]
        public function refunds(): string
        {
            return 'refunds';
        }

        #[Authorize(Ability::Export->value, \Tinvar\Attributes\Page::class)]
        public function exports(): string
        {
            return 'exports';
        }

        #[Authorize(Abilities::AUDIT)]
        public function audits(): string
        {
            return 'audits';
        }

        #[Authorize(Abilities::MISSING)]
        public function missing(): string
        {
            return 'missing';
        }
    }

    PHP);
    $codemods = withCodemod($this, "<?php file_put_contents(\$argv[1] . '/notes.txt', \"changed\\n\");\n");

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/Reports.php:22: authorization of App\Reports::audits() rests on the constant PREFIX, which a codemod can change where nothing shows it',
        'src/Reports.php:28: authorization of App\Reports::missing() rests on App\Abilities::MISSING, which a codemod can change where nothing shows it',
    ]);
});

// A second declaration of a class, in a file that loads before the first
// -- one the application includes itself, say -- is the class from then
// on: its authorization is whatever the second one says, which here is
// nothing, and neither file's own attributes changed.
it('flags a class declared in a second file, which can stand in for the first', function () {
    $this->workspace->file('app/src/Helpers.php', "<?php\n\nnamespace App;\n\nfunction money(int \$cents): string\n{\n    return (string) \$cents;\n}\n");
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/Helpers.php';
    file_put_contents($file, file_get_contents($file) . "\nfinal class OrderPage\n{\n    public function load(Order \$order): string\n    {\n        return 'old';\n    }\n}\n");
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/Helpers.php:10: App\OrderPage is declared in more than one file, and whichever loads first decides its authorization',
        'src/OrderPage.php:8: App\OrderPage is declared in more than one file, and whichever loads first decides its authorization',
    ]);
});

// The same second declaration, in a file no PHP name marks and whose open
// tag is the short one: with short_open_tag on, PHP's default when no
// php.ini sets it, `<?namespace` runs. Only `<?php`, `<?=` and `<?` before
// whitespace counted as PHP, so the file went unread, the line that
// requires it names nothing, and nothing was flagged. It is read as PHP
// whatever the php.ini of the process checking it says about short tags.
it('reads a file opened by a short tag as PHP', function () {
    $this->workspace->file('app/src/Helpers.php', "<?php\n\nnamespace App;\n\nfunction money(int \$cents): string\n{\n    return (string) \$cents;\n}\n");
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    @mkdir($argv[1] . '/src/View');
    file_put_contents($argv[1] . '/src/View/order.tpl', "<?namespace App;\nfinal class OrderPage\n{\n    public function load(Order \$order): string\n    {\n        return 'old';\n    }\n}\n");
    $file = $argv[1] . '/src/Helpers.php';
    file_put_contents($file, str_replace("namespace App;\n", "namespace App;\n\nrequire_once __DIR__ . '/View/order.tpl';\n", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)->toBe([
        'src/View/order.tpl:1: new PHP file',
        'src/OrderPage.php:8: App\OrderPage is declared in more than one file, and whichever loads first decides its authorization',
        'src/View/order.tpl:2: App\OrderPage is declared in more than one file, and whichever loads first decides its authorization',
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

// A Unix socket is not on the network: a socket file a database listens
// on, its peer authentication taking whoever connects for the user running
// the codemod, is reached through the filesystem. bubblewrap's network
// namespace leaves it open, and a sandbox that showed the codemod the whole
// filesystem, read-only, let a static PHP's PDO read the live database
// through /run/postgresql. A listener where such sockets live -- /tmp, and
// /run where this user may make one -- sees whether anything got through.
it('keeps a codemod off the Unix sockets outside its copy, through the clients PHP was built with', function () {
    $listeners = [];
    $directories = [];
    foreach (['/tmp', '/run'] as $parent) {
        $directory = $parent . '/tvcm-' . bin2hex(random_bytes(4));
        if (!is_writable($parent) || !@mkdir($directory, 0o777)) {
            continue;
        }
        chmod($directory, 0o777);
        $directories[] = $directory;
        $listener = stream_socket_server("unix://{$directory}/.s.PGSQL.5432", $errno, $error);
        expect($listener)->not->toBeFalse($error);
        chmod("{$directory}/.s.PGSQL.5432", 0o777);
        $listeners[] = $listener;
    }
    $dirs = var_export($directories, true);

    $codemods = withCodemod($this, <<<PHP
    <?php
    foreach ({$dirs} as \$dir) {
        foreach (["pgsql:host={\$dir};dbname=live;connect_timeout=1", "mysql:unix_socket={\$dir}/.s.PGSQL.5432;dbname=live"] as \$dsn) {
            try {
                new PDO(\$dsn, null, null, [PDO::ATTR_TIMEOUT => 1]);
            } catch (Throwable) {
            }
        }
        if (class_exists('Redis')) {
            try { (new Redis())->connect("{\$dir}/.s.PGSQL.5432"); } catch (Throwable) {}
        }
    }
    PHP);

    try {
        $result = $codemods->run($this->app, allowed: true);
        $read = $listeners;
        $write = $except = null;
        $pending = $listeners === [] ? 0 : stream_select($read, $write, $except, 0);
    } finally {
        foreach ($listeners as $listener) {
            fclose($listener);
        }
        foreach ($directories as $directory) {
            @unlink("{$directory}/.s.PGSQL.5432");
            @rmdir($directory);
        }
    }

    expect($listeners)->not->toBe([])
        ->and($pending)->toBe(0, 'a codemod connected to a Unix socket outside its copy')
        ->and($result->failed)->toBe([]);
});

/**
 * What bubblewrap is given to bind, read-only or not: each path after a
 * --ro-bind or --bind.
 *
 * @param list<string> $arguments
 * @return list<string>
 */
function bound(array $arguments): array
{
    $mounts = [];
    foreach ($arguments as $at => $argument) {
        if (\in_array($argument, ['--ro-bind', '--bind'], true)) {
            $mounts[] = $arguments[$at + 1];
        }
    }

    return $mounts;
}

it('shows a codemod under bubblewrap the system\'s directories and its own, and nothing that holds sockets', function () {
    $arguments = Codemods::bubblewrap('/tmp/tinvar-codemods-x', '/usr/bin/php8.5');

    // Of where sockets live, the run's own directory alone.
    expect(array_values(array_filter(bound($arguments), static fn (string $path): bool => preg_match('#^/(run|tmp|var|home|root|srv|opt|mnt|media)?(/|$)#', $path) === 1)))
        ->toBe(['/tmp/tinvar-codemods-x'])
        ->and(\array_slice($arguments, -11))->toBe([
            '--dev', '/dev', '--proc', '/proc', '--bind', '/tmp/tinvar-codemods-x', '/tmp/tinvar-codemods-x',
            '--unshare-all', '--die-with-parent', '--new-session', '--',
        ]);
});

// A static PHP kept beside the app, or in a home directory: its directory
// is the app's, or the home, sockets and .env with it.
it('binds a PHP outside the system\'s directories as its one file, and the libraries it loads as theirs, never a directory', function () {
    $arguments = Codemods::bubblewrap('/tmp/r', '/srv/shop/php', ['/srv/shop/lib/libphp-extra.so.1', '/usr/lib/libc.so.6']);
    $mounts = bound($arguments);

    expect($mounts)->toContain('/srv/shop/php')
        ->and($mounts)->toContain('/srv/shop/lib/libphp-extra.so.1')
        ->and($mounts)->not->toContain('/srv/shop')
        ->and($mounts)->not->toContain('/srv/shop/lib')
        ->and($mounts)->not->toContain('/srv')
        // One already under a system directory is not bound again.
        ->and(array_count_values($mounts)['/usr/lib/libc.so.6'] ?? 0)->toBe(0)
        ->and(bound(Codemods::bubblewrap('/tmp/r', '/home/dev/php')))->not->toContain('/home/dev')
        ->and(bound(Codemods::bubblewrap('/tmp/r', '/php')))->not->toContain('/');
});

// The app under /usr -- /usr/src/app and /usr/local/app are common working
// directories in an image -- the home, or the temporary directory under
// what is bound read-only would show the codemod all of it.
it('refuses to run where the app, the home or the temporary directory is under what the sandbox shows', function (string $outside) {
    expect(fn () => Codemods::bubblewrap('/tmp/r', '/usr/bin/php', [], [$outside]))
        ->toThrow(\RuntimeException::class, "{$outside} is under ");
})->with(['/usr/src/app', '/usr/local/app', '/usr/share/app', '/usr']);

it('runs where none of them is under what the sandbox shows', function () {
    expect(Codemods::bubblewrap('/tmp/r', '/usr/bin/php', [], ['/srv/shop', '/home/dev', '/tmp']))->toContain('--unshare-all');
});

// The console's descriptors -- a file it read, a socket it listens on -- are
// not opened close-on-exec, and a child holds each one under the same
// number, where php://fd reaches it past open_basedir.
it('hands a codemod none of the descriptors the process running it holds', function () {
    $this->workspace->file('outside/secret.txt', "held-open-secret\n");
    $held = fopen($this->workspace->root . '/outside/secret.txt', 'r');
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    // Only a count: a descriptor of PHP's own may read as anything.
    $leaked = 0;
    for ($fd = 3; $fd < 64; $fd++) {
        $read = @file_get_contents("php://fd/{$fd}", false, null, 0, 64);
        $leaked += is_string($read) && str_contains($read, 'held-open-secret') ? 1 : 0;
    }
    file_put_contents($argv[1] . '/seen.txt', "leaked {$leaked}\n");
    PHP);

    try {
        $result = $codemods->run($this->app, allowed: true);
    } finally {
        fclose($held);
        fclose($listener);
    }

    expect($result->failed)->toBe([])
        ->and($result->patch)->toContain('+leaked 0');
});

it('says why when bubblewrap could not make the sandbox', function () {
    $codemods = withCodemod($this, "<?php fwrite(STDERR, 'bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted'); exit(1);\n");

    expect($codemods->run($this->app, allowed: true)->failed)->toBe([
        'acme/billing codemods/upgrade.php: exited 1: bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted'
        . ' (the sandbox could not be made: where AppArmor restricts unprivileged user namespaces, bwrap needs a profile that allows them)',
    ]);
});

// The flags and failures are printed to the terminal of whoever runs the
// codemods, and a codemod chooses part of them: a line it changed, what it
// printed. An escape sequence in either moved the cursor and erased the
// lines above it -- the REVIEW block among them -- so each control
// character is written out instead.
it('writes out the control characters a codemod puts in its flags and failures', function () {
    $codemods = withCodemod($this, <<<'PHP'
    <?php
    $file = $argv[1] . '/src/OrderPage.php';
    file_put_contents($file, str_replace("#[Authorize('view', 'order')]", "#[\\Tinvar\\Attributes\\PublicAccess] // \e[1A\e[2K\e[1A\e[2K", file_get_contents($file)));
    PHP);

    expect($codemods->run($this->app, allowed: true)->flagged)
        ->toContain('src/OrderPage.php:11: +    #[\Tinvar\Attributes\PublicAccess] // \x1b[1A\x1b[2K\x1b[1A\x1b[2K');

    $this->workspace->file('vendor/acme/billing/codemods/upgrade.php', "<?php fwrite(STDERR, \"\\e[2J\\e[H\\u{9b}2J\\nsecond line\"); exit(1);\n");
    PackageLock::of($this->policy, [...(new Lockfiles($this->workspace->root))->composer()])->write($this->workspace->root);

    expect($codemods->run($this->app, allowed: true)->failed)
        ->toBe(["acme/billing codemods/upgrade.php: exited 1: \\x1b[2J\\x1b[H\\u{9b}2J\nsecond line"]);
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

// A file's name is the codemod's to choose, and diff writes it into the
// patch's header as it is. A line break in one wrote lines of the
// codemod's choosing into the patch -- a hunk for another file among them,
// which no flag read and `patch` applied -- and whitespace, a quote or a
// backslash can have patch or git apply read another file's name in it.
it('keeps a file whose name a patch tool could misread out of the patch, and flags it', function (string $name) {
    $this->workspace->file('app/src/Report.php', "<?php\n\nnamespace App;\n\nfinal class Report extends AdminPage\n{\n}\n");
    $codemods = withCodemod($this, '<?php $path = $argv[1] . "/" . ' . var_export($name, true) . ";\n"
        . "@mkdir(dirname(\$path), 0o777, true);\nfile_put_contents(\$path, \"hello\\n\");\n");

    $result = $codemods->run($this->app, allowed: true);

    expect($result->failed)->toBe([])
        ->and($result->needsReview())->toBeTrue()
        ->and($result->flagged)->toBe([json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ":1: left out of the patch: patch or git apply could read another file's name in it"])
        ->and($result->patch)->toBe('');
})->with([
    'a line break' => ["notes\n+++ b/src/Report.php\n@@ -5 +5 @@\n-final class Report extends AdminPage\n+final class Report extends Page\n--- a/readme.txt"],
    'a space' => ['src/Report.php extends Page'],
    'a tab' => ["src/Report.php\textends"],
    'a leading quote' => ['"src/Report.php"'],
    'a backslash' => ['src\\Report.php'],
]);

it('names in a patch only plain relative paths', function (string $path, bool $patchable) {
    expect(Codemods::patchable($path))->toBe($patchable);
})->with([
    ['src/OrderPage.php', true],
    ['.github/workflows/ci.yml', true],
    ['resources/js/order-page_v2.tsx', true],
    ['a..b/c', true],
    ['src/../OrderPage.php', false],
    ['../OrderPage.php', false],
    ['src/..', false],
    ['./src/OrderPage.php', false],
    ['/etc/passwd', false],
    ['src//OrderPage.php', false],
    ['src/', false],
    ['-p1', false],
    ['src/Order Page.php', false],
    ['"src/OrderPage.php', false],
    ['src\\OrderPage.php', false],
    ["src/OrderPage.php\n", false],
    ['src/Ordér.php', false],
    ['', false],
]);
