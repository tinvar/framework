<?php

declare(strict_types=1);

/**
 * The docs site (examples/docs), built for real: every page rendered as
 * nobody through Docs\Kernel and the renderer into files, the files served
 * by a plain static file server -- no PHP -- and read in Chromium the way a
 * reader would: the landing page, a guide, search, dark mode and a phone.
 *
 * Static output carries nothing of anyone's (StaticBuilder refuses it), and
 * a page drawn from Markdown carries no markup the Markdown wrote: every
 * script in a file is the build's, under its nonce.
 */

namespace Tinvar\Tests\Integration\DocsSite;

use Docs\Content\Library;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

/** The site, built once for the file, into a directory removed when the process ends. */
function site(string $root): string
{
    static $site = null;
    if ($site !== null) {
        return $site;
    }

    $directory = sys_get_temp_dir() . '/tinvar-docs-site-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/examples/docs/bin/docs") . ' build --out=' . escapeshellarg("{$directory}/site") . ' 2>&1',
        $output,
        $status,
    );
    if ($status !== 0) {
        throw new \RuntimeException("The docs did not build:\n" . implode("\n", \array_slice($output, -30)));
    }

    return $site = "{$directory}/site";
}

describe('the docs site', function () use ($root) {
    beforeEach(function () use ($root) {
        if (!is_dir("{$root}/node_modules/@meridian/ui")) {
            $this->markTestSkipped('The npm workspace is not installed.');
        }
    });

    it('generates the types its views are written against, and holds its pages to the build rules', function () use ($root) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/examples/docs/bin/docs") . ' codegen --check 2>&1', $codegen, $drifted);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/examples/docs/bin/docs") . ' check 2>&1', $check, $failed);

        expect($drifted)->toBe(0, implode("\n", $codegen))
            ->and($failed)->toBe(0, implode("\n", $check));
    });

    it('builds every page to a file, with every script the build\'s own', function () use ($root) {
        $site = site($root);

        $paths = ['/', ...array_map(static fn ($page): string => "/docs/{$page->slug}", new Library()->pages())];
        foreach ($paths as $path) {
            $file = rtrim("{$site}{$path}", '/') . '/index.html';
            expect(is_file($file))->toBeTrue("{$path} was not built");

            $html = (string) file_get_contents($file);
            preg_match('/<meta http-equiv="Content-Security-Policy" content="script-src \'nonce-([^\']+)\'/', $html, $nonce);
            expect($nonce[1] ?? null)->not->toBeNull("{$path} carries no policy");

            // Every script element carries the file's one nonce: no script a
            // page's content could have written runs. The page metadata is
            // data, never run.
            preg_match_all('/<script\b([^>]*)>/i', $html, $scripts);
            foreach ($scripts[1] as $attributes) {
                if (str_contains($attributes, 'type="application/json"')) {
                    continue;
                }
                expect(str_contains($attributes, "nonce=\"{$nonce[1]}\""))->toBeTrue("{$path} has a script without the nonce");
            }
            expect($html)->not->toMatch('/\son[a-z]+="/i', "{$path} has an inline event handler");
            expect($html)->not->toContain('javascript:');
        }

        expect(is_file("{$site}/404.html"))->toBeTrue()
            ->and((string) file_get_contents("{$site}/404.html"))->toContain('There is no page at this address')
            ->and(glob("{$site}/assets/*.js"))->not->toBe([]);
    });

    it('reads as a reader would in Chromium, served as plain files', function () use ($root) {
        if (!is_file("{$root}/node_modules/playwright/package.json")) {
            $this->markTestSkipped('Playwright is not installed.');
        }
        try {
            $frankenphp = FrankenPhp::locate($root);
        } catch (ServeException) {
            $this->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
        }
        $site = site($root);
        $port = new PortFinder()->find('127.0.0.1');

        // Caddy's file server: no PHP, no rewrites -- what any static host does.
        $server = proc_open(
            [$frankenphp, 'file-server', '--root', $site, '--listen', "127.0.0.1:{$port}"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        try {
            $deadline = microtime(true) + 20;
            while (!PortFinder::answers('127.0.0.1', $port) && microtime(true) < $deadline) {
                usleep(50_000);
            }

            $browser = proc_open(
                ['node', "{$root}/examples/docs/e2e/smoke.mjs", "http://127.0.0.1:{$port}"],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $out,
                $root,
                ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            );
            $output = (string) stream_get_contents($out[1]);
            proc_close($browser);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $result = json_decode($output, true);
        expect($result)->toBeArray("the browser script failed:\n{$output}")
            ->and($result['failure'] ?? null)->toBeNull()
            ->and($result['errors'])->toBe([])
            ->and($result['violations'])->toBe([]);

        $steps = $result['steps'];
        expect($steps['landing']['status'])->toBe(200)
            ->and($steps['landing']['title'])->toBe('Documentation · Tinvar')
            ->and($steps['landing']['policy'])->toContain("'strict-dynamic'")
            ->and($steps['guide']['heading'])->toBe('Pages and layouts')
            ->and($steps['guide']['current'])->toBe('Pages and layouts')
            ->and($steps['guide']['highlighted'])->toBeGreaterThan(0)
            ->and($steps['guide']['toc'])->toBeGreaterThan(0)
            ->and($steps['guide']['next'])->toBe(1)
            ->and($steps['search']['first'])->toStartWith('Static pages')
            ->and($steps['search']['landedOn'])->toMatch('#^/docs/static-pages/?$#')
            ->and($steps['search']['dialogClosed'])->toBeTrue()
            ->and($steps['dark']['theme'])->toBe('dark')
            ->and($steps['dark']['afterReload'])->toBe($steps['dark']['background'])
            ->and($steps['dark']['toggle'])->toBe('dark')
            ->and($steps['mobile']['sidebarVisible'])->toBeFalse()
            ->and($steps['mobile']['drawerLinks'])->toBeGreaterThan(20)
            ->and($steps['mobile']['overflow'])->toBe(0);
    });
});
