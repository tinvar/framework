<?php

declare(strict_types=1);

/**
 * PhpCommand: the command a child PHP script is run with, which under
 * FrankenPHP's static binary is `frankenphp php-cli`, not PHP_BINARY alone.
 */

namespace Tinvar\Tests\Server\PhpCommand;

use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PhpCommand;

it('runs a PHP CLI as itself', function () {
    expect(PhpCommand::prefixFor('/usr/local/bin/php', ['/usr/local/bin/php', '/srv/app/bin/tinvar', 'serve']))
        ->toBe(['/usr/local/bin/php'])
        ->and(PhpCommand::prefixFor('/usr/bin/php8.5', null))->toBe(['/usr/bin/php8.5']);
});

// The container image's serve, started by the entrypoint as `php` on the
// PATH: its children are PHP's CLI too, never FrankenPHP's, which beside it
// in /usr/local/bin crashes on the SIGTERM a stop sends (ADR 0063).
it('runs the image\'s children on the CLI serve runs on', function () {
    expect(PhpCommand::prefixFor('/usr/local/bin/php', ['php', '/srv/shop/bin/tinvar', 'serve', '--app=/srv/shop/examples/shop', '--worker']))
        ->toBe(['/usr/local/bin/php'])
        ->and(PhpCommand::prefixFor('/usr/local/bin/php', ['php', '-d', 'memory_limit=256M', '/srv/shop/bin/tinvar', 'serve']))
        ->toBe(['/usr/local/bin/php']);
});

it('runs FrankenPHP\'s static binary through its php-cli', function () {
    expect(PhpCommand::prefixFor('/usr/local/bin/frankenphp', ['/usr/local/bin/frankenphp', 'php-cli', '/srv/app/bin/tinvar']))
        ->toBe(['/usr/local/bin/frankenphp', 'php-cli']);
});

// Where Linux says how the process was started, that decides: a binary
// renamed, or a PHP CLI that happens to be called frankenphp-something.
it('believes the command line over the name', function () {
    expect(PhpCommand::prefixFor('/opt/bin/server', ['/opt/bin/server', 'php-cli', 'x.php']))
        ->toBe(['/opt/bin/server', 'php-cli'])
        ->and(PhpCommand::prefixFor('/opt/bin/frankenphp-cli-wrapper', ['/opt/bin/frankenphp-cli-wrapper', 'x.php']))
        ->toBe(['/opt/bin/frankenphp-cli-wrapper']);
});

it('falls back on the binary\'s name where there is no command line to read', function () {
    expect(PhpCommand::prefixFor('/Users/me/tools/frankenphp', null))->toBe(['/Users/me/tools/frankenphp', 'php-cli'])
        ->and(PhpCommand::prefixFor('/Users/me/tools/FrankenPHP-mac-arm64', null))->toBe(['/Users/me/tools/FrankenPHP-mac-arm64', 'php-cli']);
});

it('is PHP_BINARY in this test run', function () {
    expect(PhpCommand::prefix()[0])->toBe(PHP_BINARY);
});

// The case it exists for, end to end: a PHP script run by the static
// binary's php-cli starts a PHP child the way serve does, and the child runs.
it('starts a child PHP under the static binary', function () {
    $frankenphp = FrankenPhp::locate(\dirname(__DIR__, 4));
    $script = (string) tempnam(sys_get_temp_dir(), 'tinvar-php-command-');
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1];
        $command = [...Tinvar\Server\PhpCommand::prefix(), '-r', 'echo "child ", PHP_SAPI;'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        echo stream_get_contents($pipes[1]), stream_get_contents($pipes[2]);
        exit(proc_close($process));
        PHP);

    try {
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $output = shell_exec(escapeshellarg($frankenphp) . ' php-cli ' . escapeshellarg($script) . ' ' . escapeshellarg($autoload) . ' 2>&1');

        expect($output)->toBe('child cli');
    } finally {
        unlink($script);
    }
})->skip(function () {
    try {
        FrankenPhp::locate(\dirname(__DIR__, 4));

        return false;
    } catch (\Throwable) {
        return true;
    }
}, 'needs a FrankenPHP binary');
