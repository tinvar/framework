<?php

declare(strict_types=1);

namespace Tinvar\Tests\Packages\Fixtures;

use Tinvar\Packages\SupplyChain;

/**
 * A project on disk with both lockfiles and what they install, built to
 * pass the supply-chain check, for a test to break one thing in.
 */
final class Workspace
{
    public const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    public const string INTEGRITY = 'sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==';

    public readonly string $root;

    /** @var array<string, mixed> */
    public array $composerJson;

    /** @var list<array<string, mixed>> */
    public array $composerPackages = [];

    /** @var array<string, mixed> */
    public array $packageJson;

    /** @var array<string, array<string, mixed>> */
    public array $npmPackages = [];

    /** @var array<string, array<string, mixed>> package.json of each installed npm package, by lock path */
    public array $npmInstalled = [];

    /** @var array<string, string> workflow file => contents */
    public array $workflows = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/tinvar-supply-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700);
        $this->composerJson = [
            'name' => 'fixture/app',
            'require' => ['acme/money' => '1.0.0'],
            'config' => ['allow-plugins' => []],
        ];
        $this->composer('acme/money');
        $this->packageJson = ['name' => 'fixture', 'private' => true, 'dependencies' => ['left-pad' => '1.3.0']];
        $this->npm('left-pad');
    }

    /** @param array<string, mixed> $overrides */
    public function composer(string $name, array $overrides = []): void
    {
        $this->composerPackages[] = array_replace([
            'name' => $name,
            'version' => '1.0.0',
            'source' => ['type' => 'git', 'url' => "https://github.com/{$name}.git", 'reference' => self::COMMIT],
            'dist' => [
                'type' => 'zip',
                'url' => "https://api.github.com/repos/{$name}/zipball/" . self::COMMIT,
                'reference' => self::COMMIT,
                'shasum' => '',
            ],
            'type' => 'library',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $installed extra package.json fields
     */
    public function npm(string $name, array $overrides = [], array $installed = []): void
    {
        $path = 'node_modules/' . $name;
        $this->npmPackages[$path] = array_replace([
            'version' => '1.3.0',
            'resolved' => "https://registry.npmjs.org/{$name}/-/" . basename($name) . '-1.3.0.tgz',
            'integrity' => self::INTEGRITY,
        ], $overrides);
        $this->npmInstalled[$path] = array_replace(['name' => $name, 'version' => '1.3.0'], $installed);
    }

    public function write(): self
    {
        $this->json('composer.json', $this->composerJson);
        $this->json('composer.lock', [
            'content-hash' => SupplyChain::composerContentHash($this->composerJson),
            'packages' => $this->composerPackages,
            'packages-dev' => [],
        ]);
        foreach ($this->composerPackages as $package) {
            if (($package['dist']['type'] ?? null) === 'path') {
                continue;
            }
            $manifest = ['name' => $package['name']];
            if (isset($package['extra'])) {
                $manifest['extra'] = $package['extra'];
            }
            $this->json("vendor/{$package['name']}/composer.json", $manifest);
            $this->file("vendor/{$package['name']}/src/Thing.php", "<?php\n// {$package['name']}\n");
        }

        $root = ['name' => $this->packageJson['name']];
        foreach (['dependencies', 'devDependencies'] as $field) {
            if (isset($this->packageJson[$field])) {
                $root[$field] = $this->packageJson[$field];
            }
        }
        $this->json('package.json', $this->packageJson);
        $this->json('package-lock.json', [
            'name' => 'fixture',
            'lockfileVersion' => 3,
            'requires' => true,
            'packages' => ['' => $root, ...$this->npmPackages],
        ]);
        foreach ($this->npmInstalled as $path => $manifest) {
            if (($this->npmPackages[$path]['link'] ?? false) === true) {
                continue;
            }
            $this->json("{$path}/package.json", $manifest);
            $this->file("{$path}/index.js", "module.exports = 1;\n");
        }

        foreach ($this->workflows as $name => $contents) {
            $this->file(".github/workflows/{$name}", $contents);
        }

        return $this;
    }

    /** @param array<mixed> $data */
    public function json(string $relative, array $data): void
    {
        $this->file($relative, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function file(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $contents);
    }

    public function remove(): void
    {
        self::delete($this->root);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
