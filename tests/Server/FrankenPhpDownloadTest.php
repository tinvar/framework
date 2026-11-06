<?php

declare(strict_types=1);

/**
 * FrankenPHP's official binary for the machine, downloaded into the
 * project's tools/frankenphp and held to its pinned sha256 before it is
 * made executable (ADR 0175).
 */

namespace Tinvar\Tests\Server\FrankenPhpDownload;

use Tinvar\Server\FrankenPhp;
use Tinvar\Server\FrankenPhpDownload;
use Tinvar\Server\ServeException;

const ROOT = __DIR__ . '/../../../..';

/** @return resource a stream that reads $bytes */
function stream(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    \assert(\is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** A downloader whose one asset, for Linux x86_64, is $bytes, served by $served. */
function downloader(string $bytes, ?string $served = null, int $limit = FrankenPhpDownload::LIMIT, ?array &$fetched = null): FrankenPhpDownload
{
    $fetched = [];

    return new FrankenPhpDownload(
        version: '9.9.9',
        assets: ['Linux x86_64' => ['frankenphp-linux-x86_64-gnu', hash('sha256', $bytes)]],
        fetch: static function (string $url) use ($bytes, $served, &$fetched) {
            $fetched[] = $url;

            return stream($served ?? $bytes);
        },
        limit: $limit,
    );
}

beforeEach(function () {
    $this->project = sys_get_temp_dir() . '/tinvar-frankenphp-' . bin2hex(random_bytes(4));
    mkdir($this->project);
});

afterEach(function () {
    foreach (glob("{$this->project}/tools/{,.}*", \GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir("{$this->project}/tools")) {
        rmdir("{$this->project}/tools");
    }
    rmdir($this->project);
});

it('picks the release asset for the machine it runs on', function () {
    $download = new FrankenPhpDownload();

    expect($download->asset('Darwin', 'arm64'))->toBe(['frankenphp-mac-arm64', FrankenPhpDownload::ASSETS['Darwin arm64'][1]])
        ->and($download->asset('Linux', 'x86_64')[0])->toBe('frankenphp-linux-x86_64-gnu')
        ->and($download->asset('Linux', 'amd64')[0])->toBe('frankenphp-linux-x86_64-gnu')
        ->and($download->asset('Linux', 'aarch64')[0])->toBe('frankenphp-linux-aarch64-gnu')
        ->and($download->asset('Linux', 'arm64')[0])->toBe('frankenphp-linux-aarch64-gnu')
        // FrankenPHP publishes no Intel Mac binary, nor one for Windows.
        ->and($download->asset('Darwin', 'x86_64'))->toBeNull()
        ->and($download->asset('Windows', 'x86_64'))->toBeNull();
});

it('downloads from the release on GitHub, over https', function () {
    expect(new FrankenPhpDownload()->url('frankenphp-mac-arm64'))
        ->toBe('https://github.com/php/frankenphp/releases/download/v' . FrankenPhpDownload::VERSION . '/frankenphp-mac-arm64');
});

it('installs the binary at tools/frankenphp, executable, once it hashes to the pin', function () {
    $download = downloader("\x7fELF a binary", fetched: $fetched);

    $path = $download->install("{$this->project}/tools/frankenphp", 'Linux', 'x86_64');

    expect($path)->toBe("{$this->project}/tools/frankenphp")
        ->and(file_get_contents($path))->toBe("\x7fELF a binary")
        ->and(fileperms($path) & 0o777)->toBe(0o755)
        ->and($fetched)->toBe(['https://github.com/php/frankenphp/releases/download/v9.9.9/frankenphp-linux-x86_64-gnu'])
        // Nothing left half-written beside it.
        ->and(glob("{$this->project}/tools/.*.part") ?: [])->toBe([]);
});

it('installs nothing that does not hash to the pin, and leaves no part behind', function () {
    $download = downloader("\x7fELF a binary", served: "\x7fELF someone else's");

    expect(fn () => $download->install("{$this->project}/tools/frankenphp", 'Linux', 'x86_64'))
        ->toThrow(ServeException::class, 'frankenphp-linux-x86_64-gnu v9.9.9 hashes to ' . hash('sha256', "\x7fELF someone else's") . ', not the pinned ' . hash('sha256', "\x7fELF a binary"));

    expect("{$this->project}/tools/frankenphp")->not->toBeFile()
        ->and(glob("{$this->project}/tools/.*.part") ?: [])->toBe([]);
});

it('stops a download larger than any release, and keeps none of it', function () {
    $download = downloader(str_repeat('x', 64), limit: 32);

    expect(fn () => $download->install("{$this->project}/tools/frankenphp", 'Linux', 'x86_64'))
        ->toThrow(ServeException::class, 'is larger than 32 bytes');

    expect("{$this->project}/tools/frankenphp")->not->toBeFile()
        ->and(glob("{$this->project}/tools/.*.part") ?: [])->toBe([]);
});

it('never replaces a binary that is there', function () {
    mkdir("{$this->project}/tools");
    file_put_contents("{$this->project}/tools/frankenphp", 'mine');
    $download = downloader("\x7fELF a binary", fetched: $fetched);

    expect(fn () => $download->install("{$this->project}/tools/frankenphp", 'Linux', 'x86_64'))
        ->toThrow(ServeException::class, 'is there already');
    expect(file_get_contents("{$this->project}/tools/frankenphp"))->toBe('mine')
        ->and($fetched)->toBe([]);
});

it('says where to get FrankenPHP on a machine it has no binary for', function () {
    $download = downloader("\x7fELF a binary", fetched: $fetched);

    expect(fn () => $download->install("{$this->project}/tools/frankenphp", 'Darwin', 'x86_64'))
        ->toThrow(ServeException::class, 'FrankenPHP v9.9.9 has no official binary for Darwin x86_64');
    expect($fetched)->toBe([]);
});

it('finds a binary that is there, and nothing where none is', function () {
    $path = getenv('PATH');
    $env = getenv('TINVAR_FRANKENPHP');
    putenv('PATH=/nonexistent');
    putenv('TINVAR_FRANKENPHP');
    try {
        expect(FrankenPhp::find($this->project))->toBeNull();

        mkdir("{$this->project}/tools");
        file_put_contents("{$this->project}/tools/frankenphp", '#!/bin/sh');
        chmod("{$this->project}/tools/frankenphp", 0o755);
        expect(FrankenPhp::find($this->project))->toBe("{$this->project}/tools/frankenphp");
    } finally {
        putenv("PATH={$path}");
        putenv($env === false ? 'TINVAR_FRANKENPHP' : "TINVAR_FRANKENPHP={$env}");
    }
});

// One release everywhere: the Dockerfiles' Linux binaries are the ones
// the download pins, at the same version. deps:check moves the
// Dockerfiles' pin; this refuses a move that leaves the download behind.
it('pins the release and the Linux sums the Dockerfiles pin', function (string $dockerfile) {
    $text = (string) file_get_contents(ROOT . "/{$dockerfile}");

    expect($text)->toContain('releases/download/v' . FrankenPhpDownload::VERSION . '/$frankenphp"');
    foreach (['Linux x86_64', 'Linux aarch64'] as $machine) {
        [$asset, $sha256] = FrankenPhpDownload::ASSETS[$machine];
        expect(preg_match('~frankenphp=' . preg_quote($asset, '~') . '; \\\\\n\s+frankenphp_sha256=' . $sha256 . ';~', $text))->toBe(1, "{$dockerfile} pins another {$asset}");
    }
})->with(['packages/skeleton/Dockerfile', 'examples/shop/Dockerfile']);
