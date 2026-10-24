<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * tinvar.lock: what was installed for each enabled package when someone
 * last reviewed it -- its version, the lockfile's pin, and a hash of its
 * installed files.
 *
 * The allowlist says which packages may be there; this says which build of
 * each. An update that moves an enabled package to another version, another
 * commit or other files fails the check until `tinvar packages:lock` is run
 * again, which is the point at which a person looks at what changed.
 */
final class PackageLock
{
    public const string FILE = 'tinvar.lock';

    /**
     * @param array<string, array{version: string, pin: string, hash: string}> $entries by AllowedPackage::key()
     */
    public function __construct(public readonly array $entries = []) {}

    public static function read(string $root): ?self
    {
        $path = $root . '/' . self::FILE;
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $decoded = \is_string($contents) ? json_decode($contents, true) : null;
        if (!\is_array($decoded) || !\is_array($decoded['packages'] ?? null)) {
            throw new \RuntimeException(self::FILE . ' is not a package lock.');
        }

        $entries = [];
        foreach ($decoded['packages'] as $key => $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['version'] ?? null)
                || !\is_string($entry['pin'] ?? null)
                || !\is_string($entry['hash'] ?? null)) {
                throw new \RuntimeException(self::FILE . " has an unreadable entry for {$key}.");
            }
            $entries[(string) $key] = ['version' => $entry['version'], 'pin' => $entry['pin'], 'hash' => $entry['hash']];
        }

        return new self($entries);
    }

    /**
     * The lock for what is installed now, for the packages the policy enables.
     *
     * @param list<LockedPackage> $installed
     */
    public static function of(PackagePolicy $policy, array $installed): self
    {
        $entries = [];
        foreach ($policy->packages as $allowed) {
            $found = array_values(array_filter($installed, static fn (LockedPackage $p): bool => $p->key() === $allowed->key()));
            if (\count($found) !== 1 || $found[0]->directory === null || $found[0]->pin === null) {
                throw new \RuntimeException(
                    "{$allowed->name} is not installed exactly once with a pinned lock entry; install it before locking.",
                );
            }
            $entries[$allowed->key()] = [
                'version' => $found[0]->version,
                'pin' => $found[0]->pin,
                'hash' => TreeHash::of($found[0]->directory),
            ];
        }
        ksort($entries);

        return new self($entries);
    }

    public function write(string $root): void
    {
        $json = json_encode(
            [
                '_readme' => 'Written by `tinvar packages:lock`. Pins each enabled package (PackagePolicy) to the files that were reviewed.',
                'packages' => $this->entries === [] ? new \stdClass() : $this->entries,
            ],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        if (file_put_contents($root . '/' . self::FILE, $json . "\n") === false) {
            throw new \RuntimeException('Cannot write ' . self::FILE . '.');
        }
    }
}
