<?php

declare(strict_types=1);

/**
 * `tinvar check`'s Caddyfile rule (ADR 0081): wherever an app's Caddyfile
 * serves PHP, an `intercept` holds the response whole and hands stored files
 * to the file server from the directory PHP is told, and nothing lets the
 * X-Accel headers reach a client.
 */

namespace Tinvar\Tests\Server\CaddyfileRules;

use Tinvar\Server\CaddyfileRules;

const ROOT = __DIR__ . '/../../../..';

/** The skeleton's timeouts, one a line (ADR 0081, 0207). */
const TIMEOUTS = "\t\t\tread_header 10s\n\t\t\tread_body 60s\n\t\t\twrite_idle 8s\n";

/**
 * What the skeleton's `servers` block holds after its timeouts: the proxies
 * in front (ADR 0134), read from the file so the block can be taken out
 * whole.
 */
\define('SERVERS_AFTER_TIMEOUTS', (string) preg_replace(
    '/^.*?\n\tservers \{\n\t\ttimeouts \{\n' . TIMEOUTS . '\t\t\}\n(.*?)\t\}\n.*$/s',
    '$1',
    (string) file_get_contents(ROOT . '/packages/skeleton/Caddyfile'),
));

/**
 * $contents written to a Caddyfile of its own, and checked.
 *
 * @return list<string>
 */
function checked(string $contents, bool $holdsResponses = true): array
{
    $directory = sys_get_temp_dir() . '/tinvar-caddyfile-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $path = "{$directory}/Caddyfile";
    file_put_contents($path, $contents);
    try {
        return CaddyfileRules::check($path, $holdsResponses);
    } finally {
        unlink($path);
        rmdir($directory);
    }
}

/** The skeleton's Caddyfile, with $search replaced: one that passes, broken one way. */
function skeleton(string $search = '', string $replace = ''): string
{
    $contents = (string) file_get_contents(ROOT . '/packages/skeleton/Caddyfile');
    if ($search !== '') {
        expect($contents)->toContain($search);
    }

    return $search === '' ? $contents : str_replace($search, $replace, $contents);
}

it('passes the shop\'s Caddyfile, the skeleton\'s and the docs\'', function () {
    expect(CaddyfileRules::check(ROOT . '/examples/shop/Caddyfile'))->toBe([])
        ->and(CaddyfileRules::check(ROOT . '/packages/skeleton/Caddyfile'))->toBe([])
        ->and(CaddyfileRules::check(ROOT . '/examples/docs/Caddyfile'))->toBe([]);
});

// Development runs no workers and hands over no files, but a response PHP
// marks still never shows the client the header.
it('holds the development Caddyfile to the headers alone', function () {
    $dev = ROOT . '/packages/framework/resources/Caddyfile.dev';

    expect(CaddyfileRules::check($dev, holdsResponses: false))->toBe([])
        ->and(implode("\n", CaddyfileRules::check($dev)))->toContain('is not wrapped in an `intercept` block');
});

it('refuses PHP served with no intercept around it, and says why', function () {
    $without = preg_replace('/\n\t\tintercept \{.*?\n\t\t\}\n/s', "\n", skeleton());
    \assert(\is_string($without));
    $violations = checked($without);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toMatch('/^Caddyfile:\d+: `php` is not wrapped in an `intercept` block, so its workers write to clients themselves/')
        ->and($violations[0])->toContain("FrankenPHP's output mutex")
        ->and($violations[0])->toEndWith('(ADR 0081).');
});

it('refuses an X-Accel header left for the client', function (string $header) {
    $violations = checked(skeleton("\t\t\t-{$header}\n", ''));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain("nothing removing {$header} from its responses");
})->with(['X-Accel-Buffering', 'X-Accel-Redirect']);

it('takes a removal on one line, or around the handler, but not one under a matcher', function () {
    $block = "\t\theader {\n\t\t\t-X-Powered-By\n\t\t\t-X-Accel-Buffering\n\t\t\t-X-Accel-Redirect\n\t\t}\n";

    expect(checked(skeleton($block, "\t\theader -X-Accel-Buffering\n\t\theader -X-Accel-Redirect\n")))->toBe([])
        ->and(checked(str_replace("\tbind {", "\theader -X-Accel-Buffering\n\theader -X-Accel-Redirect\n\tbind {", skeleton($block, ''))))->toBe([])
        ->and(checked(skeleton($block, "\t\theader /api/* -X-Accel-Buffering\n\t\theader -X-Accel-Redirect\n")))->toHaveCount(1);
});

it('refuses an intercept that does not hold a response whole, or hand a file over', function (string $search, string $replace, string $message) {
    expect(implode("\n", checked(skeleton($search, $replace))))->toContain($message);
})->with([
    'nothing held whole' => ['@whole header !X-Accel-Buffering', '@whole header X-Other', 'no `handle_response` for `header !X-Accel-Buffering`'],
    'no file handed over' => ['@file header X-Accel-Redirect *', '@file header X-Other *', 'no `handle_response` for `header X-Accel-Redirect *`'],
    'a file rewritten elsewhere' => ['rewrite * {http.intercept.header.X-Accel-Redirect}', 'rewrite * /index.html', 'without `rewrite * {http.intercept.header.X-Accel-Redirect}`'],
    'no file server' => ["\t\t\t\tfile_server\n", '', 'and `file_server`'],
    'a matcher on the intercept' => ["\t\tintercept {", "\t\tintercept /api/* {", 'does not wrap every request it serves'],
]);

// The file server sends the path PHP names below its root: a root that is
// not the directory PHP checked the file was inside would send whatever is
// at that path there instead -- the app's own source, say.
it('refuses a file root that is not the directory PHP is told', function () {
    $other = checked(skeleton('root * {$APP_STORAGE:/nonexistent/tinvar-storage}', 'root * {$TINVAR_APP_ROOT}'));
    $missing = checked(skeleton("\t\t\tenv TINVAR_FILE_ROOT \"{\$APP_STORAGE}\"\n", ''));

    expect(implode("\n", $other))->toContain('from a `root` that is not the directory PHP is told in TINVAR_FILE_ROOT')
        ->and(implode("\n", $missing))->toContain('does not tell PHP where stored files are handed over from')
        ->and(checked(skeleton('root * {$APP_STORAGE:/nonexistent/tinvar-storage}', 'root * {$APP_STORAGE}')))->toBe([]);
});

// A route runs its directives in the order written, so an intercept after
// PHP wraps nothing PHP wrote.
it('refuses an intercept after PHP in a route', function () {
    $route = skeleton("\thandle {\n\t\troot", "\troute {\n\t\troot");
    $after = preg_replace('/(\n\t\tintercept \{.*?\n\t\t\}\n)(\n\t\tphp \{.*?\n\t\t\}\n)/s', '$2$1', $route);
    \assert(\is_string($after));

    expect(checked($route))->toBe([])
        ->and(implode("\n", checked($after)))->toContain('put it ahead of the PHP directive in the route');
});

it('holds php_server to the same rules, wherever it is', function () {
    $violations = checked("{\n\tadmin off\n}\n\nhttp://:8080 {\n\troot * public\n\tphp_server\n}\n");

    expect($violations)->not->toBe([])
        ->and($violations[0])->toStartWith('Caddyfile:7: `php_server`');
});

it('reads comments and quotes as Caddy does, and serves no PHP from a comment', function () {
    expect(checked("# php\nhttp://:8080 {\n\trespond \"php { }\" 200 # php\n}\n"))->toBe([])
        ->and(checked("http://:8080 {\n\theader X-A `a } b`\n\tphp\n}\n"))->not->toBe([]);
});

// What it cannot read is not a pass.
it('refuses a Caddyfile it cannot read', function (string $contents, string $message) {
    expect(checked($contents))->toBe(["Caddyfile: {$message}"]);
})->with([
    'a block never closed' => ["http://:8080 {\n\tphp\n", 'a block is never closed.'],
    'a stray close' => ["}\n", 'line 1 closes a block that was never opened.'],
    'a quote never closed' => ["http://:8080 {\n\trespond \"x\n}\n", 'line 2 opens a quote that is never closed.'],
    'a heredoc' => ["http://:8080 {\n\trespond <<HTML\n\tx\n\tHTML 200\n}\n", 'line 2 has a heredoc, which this check does not read.'],
]);

it('refuses a missing Caddyfile', function () {
    expect(CaddyfileRules::check('/nonexistent/Caddyfile'))->toBe(['/nonexistent/Caddyfile: no Caddyfile to read, so nothing says how PHP is served.']);
});

/**
 * $files written into a directory of their own, the first checked.
 *
 * @param array<string, string> $files name => contents
 * @return list<string>
 */
function checkedTree(array $files, bool $holdsResponses = true): array
{
    $directory = sys_get_temp_dir() . '/tinvar-caddyfile-' . bin2hex(random_bytes(4));
    mkdir($directory);
    try {
        foreach ($files as $name => $contents) {
            if (!is_dir(\dirname("{$directory}/{$name}"))) {
                mkdir(\dirname("{$directory}/{$name}"), 0o755, true);
            }
            file_put_contents("{$directory}/{$name}", $contents);
        }

        return CaddyfileRules::check($directory . '/' . array_key_first($files), $holdsResponses);
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
}

// A write to a client that stopped reading stalls for write_idle, Caddy's
// default a minute; where a worker still writes, every other worker's
// response waits behind it (ADR 0053, 0105).
it('refuses PHP served with no write_idle, or a longer one, in the global options', function (string $search, string $replace, string $message) {
    expect(implode("\n", checked(skeleton($search, $replace))))->toContain($message);
})->with([
    'no servers block' => ["\tservers {\n\t\ttimeouts {\n" . TIMEOUTS . "\t\t}\n" . SERVERS_AFTER_TIMEOUTS . "\t}\n", '', 'serves PHP with no `servers` block for every listener setting `write_idle`'],
    'no write_idle' => ["\t\t\twrite_idle 8s\n", '', '`servers` sets no `write_idle`'],
    'a minute' => ['write_idle 8s', 'write_idle 1m', '`write_idle 1m` is not a duration of at most 8s'],
    'a placeholder' => ['write_idle 8s', 'write_idle {$IDLE:8s}', '`write_idle {$IDLE:8s}` is not a duration of at most 8s'],
    'none at all' => ['write_idle 8s', 'write_idle 0s', '`write_idle 0s` is not a duration'],
    'only for one listener' => ["\tservers {", "\tservers :9000 {", 'serves PHP with no `servers` block for every listener'],
]);

it('takes a shorter write_idle, one on a line, and a listener of its own held to it too', function () {
    expect(checked(skeleton('write_idle 8s', 'write_idle 2500ms')))->toBe([])
        ->and(checked(skeleton("\t\t\twrite_idle 8s\n\t\t}\n", "\t\t}\n\t\ttimeouts { write_idle 5s }\n")))->toBe([])
        ->and(implode("\n", checked(skeleton("\tservers {", "\tservers :9000 {\n\t\tprotocols h1\n\t}\n\tservers {"))))
        ->toContain('`servers :9000` sets no `write_idle`');
});

it('asks no write_idle of a file that serves no PHP', function () {
    expect(checked("http://:8080 {\n\trespond \"ok\" 200\n}\n"))->toBe([]);
});

it('holds the development Caddyfile to write_idle too', function () {
    $dev = (string) file_get_contents(ROOT . '/packages/framework/resources/Caddyfile.dev');

    expect(implode("\n", checkedTree(['Caddyfile.dev' => str_replace('write_idle 8s', 'write_idle 30s', $dev)], false)))
        ->toContain('`write_idle 30s` is not a duration of at most 8s');
});

// Audit 2026-11-13 M5: a request that sends its body slowly held a worker
// for as long as it liked -- Caddy reads a body with no deadline -- so as
// many slow uploads as there are workers stopped the app (ADR 0207).
it('refuses PHP served with no read deadlines, or longer ones, in the global options', function (string $search, string $replace, string $message) {
    expect(implode("\n", checked(skeleton($search, $replace))))->toContain($message);
})->with([
    'no read_body' => ["\t\t\tread_body 60s\n", '', '`servers` sets no `read_body`'],
    'no read_header' => ["\t\t\tread_header 10s\n", '', '`servers` sets no `read_header`'],
    'a long read_body' => ['read_body 60s', 'read_body 5m', '`read_body 5m` is not a duration of at most 60s'],
    'a long read_header' => ['read_header 10s', 'read_header 1m', '`read_header 1m` is not a duration of at most 10s'],
]);

// And a body larger than any the app takes is cut off by Caddy, before PHP.
it('refuses PHP served with no request_body max_size around it', function (string $search, string $replace, string $message) {
    expect(implode("\n", checked(skeleton($search, $replace))))->toContain($message);
})->with([
    'none' => ["\trequest_body {\n\t\tmax_size 11MiB\n\t}\n", '', 'is served with no `request_body` setting `max_size`'],
    'no size' => ["\t\tmax_size 11MiB\n", "\t\timmediate_errors\n", 'is served with no `request_body` setting `max_size`'],
    'under a matcher' => ["\trequest_body {", "\trequest_body /api/* {", 'is served with no `request_body` setting `max_size`'],
    'a size it cannot read' => ['max_size 11MiB', 'max_size {$BODY}', 'is served with no `request_body` setting `max_size`'],
]);

it('takes request_body in the PHP directive\'s own block', function () {
    $moved = skeleton("\trequest_body {\n\t\tmax_size 11MiB\n\t}\n", '');

    expect(checked(str_replace("\t\trewrite * /worker.php\n", "\t\trewrite * /worker.php\n\t\trequest_body {\n\t\t\tmax_size 4MB\n\t\t}\n", $moved)))->toBe([]);
});

// What an import brings in is held to the rules where it lands.
it('follows a snippet, a file and a glob, putting in their arguments', function () {
    $site = skeleton();
    $handle = (string) preg_replace('/^.*?(\n\thandle \{\n.*?\n\t\}\n).*$/s', '$1', $site);
    $global = (string) preg_replace('/^.*?\n(\tservers \{\n.*?\n\t\}\n).*$/s', '$1', $site);
    $withoutHandle = str_replace($handle, "\n\timport app {\$APP_STORAGE}\n", $site);
    $bare = str_replace($global, "\timport limits/*.caddy\n", $withoutHandle);

    // The handler from a snippet, its storage an argument; the limits from a glob.
    $snippet = "(app) {\n" . str_replace(['{$APP_STORAGE:/nonexistent/tinvar-storage}', '"{$APP_STORAGE}"'], ['{args[0]}', '"{args[0]}"'], $handle) . "}\n";
    $first = strpos($bare, "\n}\n") + 3;
    $file = substr($bare, 0, $first) . "\n" . $snippet . substr($bare, $first);
    expect(checkedTree(['Caddyfile' => $file, 'limits/write.caddy' => "servers {\n\ttimeouts {\n\t\tread_header 5s\n\t\tread_body 30s\n\t\twrite_idle 8s\n\t}\n}\n"]))->toBe([]);

    // The same handler, missing its intercept, refused where it is written.
    $broken = (string) preg_replace('/\n\t\tintercept \{.*?\n\t\t\}\n/s', "\n", $handle);
    $violations = checkedTree([
        'Caddyfile' => str_replace($handle, "\n\timport sites/app.caddy\n", $site),
        'sites/app.caddy' => $broken,
    ]);
    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toMatch('/^sites\/app\.caddy:\d+: `php` is not wrapped in an `intercept` block/');

    // And the global options' write_idle, missing from what a glob brings in.
    expect(implode("\n", checkedTree(['Caddyfile' => str_replace($global, "\timport limits/*.caddy\n", $site), 'limits/none.caddy' => "servers {\n}\n"])))
        ->toContain('`servers` sets no `write_idle`');
});

// What it cannot follow is not a pass.
it('refuses an import it cannot read', function (array $files, string $message) {
    expect(implode("\n", checkedTree($files)))->toContain($message);
})->with([
    'a file that is not there' => [['Caddyfile' => "import missing.caddy\n"], "Caddyfile:1: imports `missing.caddy`, which cannot be read"],
    'a path named at runtime' => [['Caddyfile' => "import {\$SITES}/app.caddy\n"], 'which names its file at runtime'],
    'an import of itself' => [['Caddyfile' => "import other.caddy\n", 'other.caddy' => "import Caddyfile\n"], 'which imports the file it is in'],
    'a snippet inside itself' => [['Caddyfile' => "(loop) {\n\timport loop\n}\nimport loop\n"], 'imports the snippet (loop) inside itself'],
    'a file it cannot parse' => [['Caddyfile' => "import broken.caddy\n", 'broken.caddy' => "http://:8080 {\n\tphp\n"], 'broken.caddy: a block is never closed.'],
    'a snippet defined after it is imported, which Caddy reads as a file' => [['Caddyfile' => "import later\n(later) {\n}\n"], 'imports `later`, which cannot be read'],
    'an import with a block' => [['Caddyfile' => "import app {\n\tx\n}\n"], 'has an `import` this check does not read'],
]);
