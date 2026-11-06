<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * FrankenPHP's official binary for this machine, downloaded from its
 * GitHub release into the project's tools/frankenphp, where `tinvar serve`
 * and `tinvar dev` look for it (ADR 0175).
 *
 * Each binary is pinned here by its sha256, and nothing is made
 * executable until the download hashes to it. The download is written
 * beside the target under a name of its own and renamed into place only
 * then, so a failure leaves nothing a server would run. The Linux sums
 * are the ones the skeleton's and the shop's Dockerfiles pin, at the same
 * release (a test holds them together). The Mac binary's sum is GitHub's
 * recorded digest for the asset, checked against its build provenance from
 * FrankenPHP's release workflow when it was pinned.
 *
 * Only a release that is no longer the latest is pinned: FrankenPHP
 * rebuilds its latest release's binaries nightly, under the same version.
 */
final readonly class FrankenPhpDownload
{
    public const string VERSION = '1.13.3';

    /**
     * Each machine, as `PHP_OS_FAMILY machine`, and its release asset and
     * sha256. FrankenPHP publishes no Intel Mac or Windows binary.
     */
    public const array ASSETS = [
        'Darwin arm64' => ['frankenphp-mac-arm64', '7cffdb2f25e7e6206dba907cdfdd2fabc44876aaec1a2a4783e588f7b953d7b8'],
        'Linux x86_64' => ['frankenphp-linux-x86_64-gnu', 'd210c06bfcd7ed974c05376fc7fa9c300b1db8cd2a7c793bed5ed8d087b3f0ef'],
        'Linux aarch64' => ['frankenphp-linux-aarch64-gnu', '53aca1dfd4496cd1cb1d1954d0e9a5896e60c65dc7a61ebd7a821c69bdcea539'],
    ];

    /** Larger than any release's binary (about 200 MB), and no larger. */
    public const int LIMIT = 512 << 20;

    /**
     * @param array<string, array{string, string}> $assets
     * @param (\Closure(string): resource)|null $fetch opens a URL for reading; https by default
     */
    public function __construct(
        private string $version = self::VERSION,
        private array $assets = self::ASSETS,
        private ?\Closure $fetch = null,
        private int $limit = self::LIMIT,
    ) {}

    /**
     * The release asset and its sha256 for a machine, or null where there
     * is none.
     *
     * @return array{string, string}|null
     */
    public function asset(string $os = \PHP_OS_FAMILY, ?string $machine = null): ?array
    {
        $machine = $machine === null ? self::machine($os, php_uname('m')) : self::normalized($os, $machine);

        return $this->assets["{$os} {$machine}"] ?? null;
    }

    /**
     * The machine a binary is for, as `uname -m` names it, normalized: an
     * Apple silicon Mac whose PHP runs under Rosetta says `x86_64`, and is
     * taken for the arm64 Mac it is, whose binary runs there natively.
     *
     * @param (\Closure(string): ?string)|null $sysctl reads a sysctl by name; `sysctl -n` by default
     */
    public static function machine(string $os, string $uname, ?\Closure $sysctl = null): string
    {
        $machine = self::normalized($os, $uname);
        if ($os === 'Darwin' && $machine === 'x86_64') {
            $sysctl ??= static function (string $name): ?string {
                $output = [];
                exec('/usr/sbin/sysctl -n ' . escapeshellarg($name) . ' 2>/dev/null', $output, $status);

                return $status === 0 ? trim(implode("\n", $output)) : null;
            };
            if ($sysctl('hw.optional.arm64') === '1') {
                return 'arm64';
            }
        }

        return $machine;
    }

    private static function normalized(string $os, string $machine): string
    {
        return match (strtolower($machine)) {
            'arm64', 'aarch64' => $os === 'Darwin' ? 'arm64' : 'aarch64',
            'x86_64', 'amd64' => 'x86_64',
            default => $machine,
        };
    }

    /** Whether this Linux's C library is musl's, with no glibc loader beside it. */
    public static function musl(): bool
    {
        return (glob('/lib/ld-musl-*.so.1') ?: []) !== []
            && (glob('/lib{,64,/*-linux-gnu}/ld-linux*.so.*', \GLOB_BRACE) ?: []) === [];
    }

    public function url(string $asset): string
    {
        return "https://github.com/php/frankenphp/releases/download/v{$this->version}/{$asset}";
    }

    /**
     * Download this machine's binary to $target, hold it to its pin and
     * make it executable.
     *
     * @param bool|null $musl whether this Linux's C library is musl's; read from the system by default
     * @return string $target
     * @throws ServeException where there is a file at $target already, or
     *         one appears there before the download is in place; no binary
     *         for the machine; or a download that fails or does not hash to
     *         its pin. Nothing is left at $target then
     */
    public function install(string $target, string $os = \PHP_OS_FAMILY, ?string $machine = null, ?bool $musl = null): string
    {
        $machine = $machine === null ? self::machine($os, php_uname('m')) : self::normalized($os, $machine);
        if (file_exists($target) || is_link($target)) {
            throw new ServeException("{$target} is there already: remove it first to download FrankenPHP v{$this->version} in its place.");
        }
        if ($os === 'Linux' && ($musl ?? self::musl())) {
            throw new ServeException(
                "FrankenPHP's official Linux binary needs glibc, and this Linux's C library is musl's. Install FrankenPHP's static build "
                . '(https://frankenphp.dev), then put it at tools/frankenphp, set TINVAR_FRANKENPHP, or have it on your PATH.',
            );
        }
        [$asset, $sha256] = $this->asset($os, $machine) ?? throw new ServeException(
            "FrankenPHP v{$this->version} has no official binary for {$os} {$machine}. Install FrankenPHP (https://frankenphp.dev), "
            . 'then put it at tools/frankenphp, set TINVAR_FRANKENPHP, or have it on your PATH.',
        );

        $directory = \dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new ServeException("Cannot make {$directory} for FrankenPHP.");
        }
        // What an interrupted download left -- a kill skips the cleanup below.
        foreach (glob("{$directory}/." . basename($target) . '-*.part') ?: [] as $stale) {
            if (preg_match('/-[0-9a-f]{16}\.part\z/', $stale) === 1 && is_file($stale) && !is_link($stale)) {
                @unlink($stale);
            }
        }
        $part = "{$directory}/." . basename($target) . '-' . bin2hex(random_bytes(8)) . '.part';
        $out = @fopen($part, 'xb') ?: throw new ServeException("Cannot write {$part}.");
        $in = null;
        try {
            $in = ($this->fetch ?? self::fetch(...))($this->url($asset));
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false) {
                    throw new ServeException("The download of {$asset} v{$this->version} broke off.");
                }
                $size += \strlen($chunk);
                if ($size > $this->limit) {
                    throw new ServeException("{$asset} v{$this->version} is larger than {$this->limit} bytes, more than any release's binary: nothing was installed.");
                }
                hash_update($hash, $chunk);
                for ($written = 0; $written < \strlen($chunk); $written += $wrote) {
                    $wrote = fwrite($out, substr($chunk, $written));
                    if ($wrote === false || $wrote === 0) {
                        throw new ServeException("Cannot write {$part}.");
                    }
                }
            }
            if (!fclose($out)) {
                throw new ServeException("Cannot write {$part}.");
            }
            $out = null;

            $got = hash_final($hash);
            if (!hash_equals($sha256, $got)) {
                throw new ServeException("{$asset} v{$this->version} hashes to {$got}, not the pinned {$sha256}: nothing was installed.");
            }
            if (!chmod($part, 0o755)) {
                throw new ServeException("Cannot put FrankenPHP at {$target}.");
            }
            // A link, not a rename: it fails where something has appeared
            // at the target meanwhile, rather than replacing it.
            set_error_handler(static fn (): bool => true);
            try {
                $linked = link($part, $target);
            } finally {
                restore_error_handler();
            }
            if (!$linked) {
                throw new ServeException(file_exists($target) || is_link($target)
                    ? "{$target} is there already: something put it there during the download, and it was left as it is."
                    : "Cannot put FrankenPHP at {$target}.");
            }
        } finally {
            if (\is_resource($in)) {
                fclose($in);
            }
            if (\is_resource($out)) {
                fclose($out);
            }
            if (is_file($part)) {
                @unlink($part);
            }
        }

        return $target;
    }

    /** @return resource */
    private static function fetch(string $url)
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ServeException("FrankenPHP is downloaded over https alone, not from {$url}.");
        }
        // GitHub answers a release asset with a redirect to its storage.
        $context = stream_context_create([
            'http' => ['follow_location' => 1, 'max_redirects' => 5, 'timeout' => 60, 'user_agent' => 'tinvar-frankenphp-install', 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new ServeException("Cannot download {$url}: " . (error_get_last()['message'] ?? 'the request failed') . '.');
        }

        return $stream;
    }
}
