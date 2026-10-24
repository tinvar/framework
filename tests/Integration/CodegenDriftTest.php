<?php

declare(strict_types=1);

/**
 * "Codegen drift. Fails if generated TypeScript is out of date with the PHP
 * signatures." The shop's committed types are what its Data classes,
 * queries, pages and actions generate now -- and `codegen --check` says
 * which file is not, when one is.
 */

namespace Tinvar\Tests\Integration\CodegenDrift;

use Tinvar\Support\PrivateDirectory;

/** @return array{int, string} the exit status and everything printed */
function codegen(string ...$arguments): array
{
    $root = \dirname(__DIR__, 4);
    $command = array_merge([PHP_BINARY, "{$root}/bin/tinvar", 'codegen'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

it('finds the shop\'s generated TypeScript up to date with its PHP', function () {
    [$status, $output] = codegen('--check');

    expect($status)->toBe(0, $output);
});

it('names the file that drifted, the one missing and the one left behind, and writes nothing', function () {
    $types = \dirname(__DIR__, 4) . '/examples/shop/resources/types';
    $copy = PrivateDirectory::create('tinvar-codegen');
    mkdir("{$copy}/actions");
    foreach (['generated.ts', 'modules.d.ts'] as $file) {
        copy("{$types}/{$file}", "{$copy}/{$file}");
    }
    $actions = glob("{$types}/actions/*.ts") ?: [];
    file_put_contents("{$copy}/generated.ts", "\n// edited by hand\n", FILE_APPEND);
    file_put_contents("{$copy}/actions/Removed.ts", 'export {};');

    try {
        [$status, $output] = codegen("--out={$copy}", '--check');
        $untouched = file_get_contents("{$copy}/generated.ts");
    } finally {
        foreach (glob("{$copy}/actions/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir("{$copy}/actions");
        PrivateDirectory::remove($copy);
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('generated.ts is out of date')
        ->and($output)->toContain('actions/Removed.ts is generated from nothing now')
        ->and($output)->toContain('actions/' . basename($actions[0]) . ' is missing')
        ->and($untouched)->toEndWith("// edited by hand\n");
});
