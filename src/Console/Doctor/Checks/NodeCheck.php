<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;

/**
 * Node, at the version package.json's `engines.node` asks for: the
 * renderers, Vite's build and dev server, and codegen's tests all run on
 * it. Found as `tinvar serve` finds it, on the PATH the application runs
 * with.
 */
final readonly class NodeCheck implements DoctorCheck
{
    /** What the framework's own packages ask for, where the application's package.json says nothing. */
    public const string MINIMUM = '22.13.0';

    /** @param (\Closure(): ?string)|null $version a test's `node --version`; the PATH's node by default, null for none */
    public function __construct(private ?\Closure $version = null) {}

    public function title(): string
    {
        return 'Node';
    }

    public function run(DoctorContext $context): array
    {
        $wanted = self::wanted($context->root);
        $version = $this->version !== null ? ($this->version)() : self::installed($context->env['PATH'] ?? (string) getenv('PATH'));
        if ($version === null) {
            return [Finding::problem(
                'No node on the PATH: the renderers, the build and the dev server run on it.',
                "Install Node {$wanted} or later (nodejs.org, or your version manager), so `node --version` answers.",
            )];
        }
        $version = ltrim(trim($version), 'v');
        if (version_compare($version, $wanted, '<')) {
            return [Finding::problem(
                "Node is {$version}; package.json asks for {$wanted} or later.",
                "Install Node {$wanted} or later and put it first on your PATH.",
            )];
        }

        return [Finding::passed("Node {$version}, {$wanted} or later as package.json asks.")];
    }

    /** The least version package.json's `engines.node` asks for, as `>=22.13`; MINIMUM where it says nothing readable. */
    public static function wanted(string $root): string
    {
        $package = json_decode((is_file("{$root}/package.json") ? (string) file_get_contents("{$root}/package.json") : ''), true);
        $engines = \is_array($package) && \is_array($package['engines'] ?? null) ? (string) ($package['engines']['node'] ?? '') : '';
        if (preg_match('/>=?\s*v?(\d+(?:\.\d+){0,2})/', $engines, $m) === 1) {
            return $m[1];
        }

        return self::MINIMUM;
    }

    /** `node --version` from the first node on $path, or null for none. */
    private static function installed(string $path): ?string
    {
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $node = "{$directory}/node";
            if ($directory !== '' && is_file($node) && is_executable($node)) {
                $output = [];
                exec(escapeshellarg($node) . ' --version 2>/dev/null', $output, $status);

                return $status === 0 && isset($output[0]) ? $output[0] : null;
            }
        }

        return null;
    }
}
