<?php

declare(strict_types=1);

/**
 * The renderer's socket and pool are checked for who owns them, and that
 * check is never skipped (ADR 0212): where PHP has no ext-posix to say who
 * this process is, a socket -- even one in /tmp, where any local user could
 * have put it -- is refused, not trusted.
 */

namespace Tinvar\Tests\Render\SocketTrustWithoutPosix;

/**
 * Runs $code in a PHP of its own with posix_geteuid() disabled, as a PHP
 * built without ext-posix has none.
 *
 * @return array{int, string}
 */
function withoutPosix(string $code): array
{
    $autoload = realpath(\Composer\InstalledVersions::getRootPackage()['install_path']) . '/vendor/autoload.php';
    $process = proc_open(
        [PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', 'require ' . var_export($autoload, true) . '; ' . $code],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
    );
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

it('refuses a socket and a pool it cannot check the owner of, rather than trusting them', function (string $call) {
    $directory = sys_get_temp_dir() . '/tinvar-trust-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o700);
    touch("{$directory}/renderer.sock");

    try {
        [$status, $output] = withoutPosix(
            'try { new Tinvar\Render\SocketTrust()->' . $call . '(' . var_export($directory . ($call === 'assertSocket' ? '/renderer.sock' : ''), true) . '); echo "TRUSTED"; }'
            . ' catch (Tinvar\Render\RendererException $e) { echo $e->getMessage(); }',
        );

        expect($status)->toBe(0)
            ->and($output)->not->toContain('TRUSTED')
            ->and($output)->toContain('ext-posix is not loaded');
    } finally {
        unlink("{$directory}/renderer.sock");
        rmdir($directory);
    }
})->with(['socket' => ['assertSocket'], 'pool' => ['assertPool']]);
