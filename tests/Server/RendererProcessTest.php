<?php

declare(strict_types=1);

/**
 * The renderer a build step starts (RendererProcess): started as serve
 * starts one -- the same script lookup, environment and tether -- and gone,
 * directory and all, when it is stopped.
 */

namespace Tinvar\Tests\Server\RendererProcess;

use Tinvar\Server\RendererProcess;
use Tinvar\Support\PrivateDirectory;

it('starts the renderer tethered to its input, with the environment serve gives one, and leaves nothing when stopped', function () {
    $root = PrivateDirectory::create('tinvar-rp-test');
    mkdir("{$root}/packages/renderer/bin", 0700, true);
    // A renderer that says what it was started with, listens, and stops
    // when its input ends -- as --exit-with-stdin has the real one.
    file_put_contents("{$root}/packages/renderer/bin/renderer.js", <<<'JS'
        const fs = require('node:fs');
        const [socket] = process.argv.slice(2);
        fs.writeFileSync(require('node:path').dirname(socket) + '/started.json', JSON.stringify({ argv: process.argv.slice(2), env: Object.keys(process.env).sort() }));
        fs.writeFileSync(socket, '');
        process.stdin.on('end', () => process.exit(0));
        process.stdin.resume();
        JS);
    file_put_contents("{$root}/entry.js", '');
    putenv('TINVAR_TEST_SECRET=hunter2');

    try {
        $renderer = RendererProcess::start($root, "{$root}/entry.js", 10.0);
        $directory = \dirname($renderer->socketPath);
        $started = json_decode((string) file_get_contents("{$directory}/started.json"), true);
        $renderer->stop();

        expect($started['argv'])->toBe([$renderer->socketPath, "{$root}/entry.js", '--exit-with-stdin'])
            ->and($started['env'])->not->toContain('TINVAR_TEST_SECRET')
            ->and($started['env'])->toContain('NODE_ENV')
            ->and(is_dir($directory))->toBeFalse();
    } finally {
        putenv('TINVAR_TEST_SECRET');
        exec('rm -rf ' . escapeshellarg($root));
    }
})->skip(fn () => trim((string) shell_exec('command -v node')) === '', 'needs node');
