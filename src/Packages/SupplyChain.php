<?php

declare(strict_types=1);

namespace Tinvar\Packages;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The supply-chain half of `tinvar check`: what the project installs, held
 * to its PackagePolicy and its lockfiles, with nothing fetched.
 *
 * tinvar-spec.md asks for three things, and each is a rule here:
 *
 *   undeclared-framework-package
 *     "Packages are enabled only from an explicit allowlist in typed config,
 *      with a lockfile. An undeclared package with framework metadata fails
 *      the build." A package whose composer.json has `extra.tinvar`, or
 *      whose package.json has `tinvar`, is refused unless the policy names
 *      it; one the policy names is refused unless tinvar.lock pins the
 *      version, lock pin and files that are installed (PackageLock).
 *
 *   pinned-by-integrity-hash
 *     "Dependencies are pinned by integrity hash." Every npm package by its
 *     sha512 integrity, which `npm ci` verifies on install; every Composer
 *     package by a full commit, from an allowed https host; each lockfile in
 *     step with the manifest beside it, so what was reviewed in the
 *     manifest is what the lock installs. Every action and reusable
 *     workflow a GitHub workflow uses is pinned the same way, by commit --
 *     a tag can be moved.
 *
 *   trusted-ci-only
 *     "Downstream app test suites run in framework CI only on trusted
 *      branches, never on pull requests from forks." No workflow is
 *     triggered by `pull_request_target` or `workflow_run`, the two events
 *     that run with the repository's secrets on behalf of a fork's code.
 *     Workflows are parsed as YAML (symfony/yaml, a dev dependency) for
 *     both rules, and one that cannot be read fails.
 *
 * And one more the spec's intent covers: an npm package may run a script
 * when it is installed only if the policy says why (Composer runs no
 * dependency's scripts, and its plugins already need `allow-plugins`, which
 * is held to the same rule: named one by one, never wholesale).
 */
final class SupplyChain
{
    private readonly Lockfiles $lockfiles;

    public function __construct(
        private readonly string $root,
        private readonly PackagePolicy $policy,
    ) {
        $this->lockfiles = new Lockfiles($root);
    }

    /** @return list<string> one line per violation; empty when the tree passes */
    public function check(): array
    {
        $violations = [];
        $installed = [];

        if ($this->lockfiles->hasComposer()) {
            try {
                $packages = $this->lockfiles->composer();
                $violations = [...$violations, ...$this->composer($packages)];
                $installed = [...$installed, ...$packages];
            } catch (\RuntimeException $e) {
                $violations[] = $e->getMessage();
            }
        }

        if ($this->lockfiles->hasNpm()) {
            try {
                $packages = $this->lockfiles->npm();
                $violations = [...$violations, ...$this->npm($packages)];
                $installed = [...$installed, ...$packages];
            } catch (\RuntimeException $e) {
                $violations[] = $e->getMessage();
            }
        }

        return [...$violations, ...$this->frameworkPackages($installed), ...$this->workflows()];
    }

    /**
     * The packages the policy enables, as installed -- for whatever runs
     * their code, such as a codemod. Refused while the tree fails the check:
     * a package is only what tinvar.lock says it is once everything passes.
     *
     * @return list<LockedPackage>
     */
    public function enabled(): array
    {
        $violations = $this->check();
        if ($violations !== []) {
            throw new \RuntimeException("The supply-chain check fails:\n  " . implode("\n  ", $violations));
        }

        $installed = [
            ...($this->lockfiles->hasComposer() ? $this->lockfiles->composer() : []),
            ...($this->lockfiles->hasNpm() ? $this->lockfiles->npm() : []),
        ];

        return array_values(array_filter(
            $installed,
            fn (LockedPackage $package): bool => $this->policy->allows($package->ecosystem, $package->name),
        ));
    }

    /** @param list<LockedPackage> $packages
     * @return list<string> */
    private function composer(array $packages): array
    {
        $violations = [];
        $manifest = $this->lockfiles->json('composer.json');
        $lock = $this->lockfiles->json(Lockfiles::COMPOSER);

        if (($lock['content-hash'] ?? null) !== self::composerContentHash($manifest)) {
            $violations[] = 'composer.lock is out of date with composer.json: run composer update --lock, and review what it changes.';
        }

        $config = \is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        if (($config['secure-http'] ?? true) !== true || ($config['disable-tls'] ?? false) !== false) {
            $violations[] = 'composer.json turns off secure-http or TLS: packages must download over https.';
        }

        $allowPlugins = $config['allow-plugins'] ?? [];
        if (!\is_array($allowPlugins)) {
            $violations[] = 'composer.json allows every Composer plugin: name each one under config.allow-plugins.';
            $allowPlugins = [];
        }
        foreach ($allowPlugins as $pattern => $allowed) {
            if ($allowed === true && str_contains((string) $pattern, '*')) {
                $violations[] = "composer.json allows plugins by the pattern '{$pattern}': name each one.";
            }
        }

        foreach ($packages as $package) {
            if ($package->plugin && !\array_key_exists($package->name, $allowPlugins)) {
                $violations[] = "{$package->name} is a Composer plugin, and config.allow-plugins does not name it.";
            }

            // A path package included: Composer installs from the source
            // under --prefer-source, or when a dist fails.
            foreach ($package->alternates as $url) {
                if (!$this->policy->composerSourceAllows($url)) {
                    $violations[] = "{$package->where} may also download from {$url}, which PackagePolicy::\$composerSources does not allow.";
                }
            }

            if ($package->local) {
                if ($package->source === null || !self::insideProject($package->source)) {
                    $violations[] = "{$package->where} is a path package outside the project.";
                }

                continue;
            }

            if ($package->pin === null) {
                $violations[] = "{$package->where} is not pinned to a commit or checksum.";
            }
            if ($package->source === null || !$this->policy->composerSourceAllows($package->source)) {
                $violations[] = "{$package->where} downloads from " . ($package->source ?? 'nowhere it names')
                    . ', which PackagePolicy::$composerSources does not allow.';
            }
        }

        return $violations;
    }

    /** @param list<LockedPackage> $packages
     * @return list<string> */
    private function npm(array $packages): array
    {
        $violations = [];
        $lock = $this->lockfiles->json(Lockfiles::NPM);
        /** @var array<mixed> $entries */
        $entries = $lock['packages'];

        // The root and every workspace: each manifest's ranges as the lock
        // recorded them, which is what `npm ci` refuses to install without.
        $manifests = ['' => 'package.json'];
        $workspaces = \is_array($entries['']['workspaces'] ?? null) ? $entries['']['workspaces'] : [];
        foreach ($workspaces as $workspace) {
            if (\is_string($workspace) && \is_array($entries[$workspace] ?? null)) {
                $manifests[$workspace] = $workspace . '/package.json';
            }
        }
        foreach ($manifests as $key => $file) {
            if (!self::insideProject($file)) {
                $violations[] = "package-lock.json names a workspace outside the project: {$key}.";

                continue;
            }
            try {
                $manifest = $this->lockfiles->json($file);
            } catch (\RuntimeException $e) {
                $violations[] = $e->getMessage();

                continue;
            }
            $locked = \is_array($entries[$key] ?? null) ? $entries[$key] : [];
            foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $field) {
                $want = \is_array($manifest[$field] ?? null) ? $manifest[$field] : [];
                $have = \is_array($locked[$field] ?? null) ? $locked[$field] : [];
                ksort($want);
                ksort($have);
                if ($want !== $have) {
                    $violations[] = "package-lock.json is out of date with {$file} ({$field}): run npm install, and review what it changes.";
                }
            }
        }

        foreach ($packages as $package) {
            if (preg_match('#(^|/)\.\.?(/|$)#', $package->where) === 1) {
                $violations[] = "{$package->where} is installed outside node_modules.";

                continue;
            }

            if ($package->local) {
                if ($package->source === null || !self::insideProject($package->source)) {
                    $violations[] = "{$package->where} links to a directory outside the project.";
                }

                continue;
            }

            // sha1 and md5 integrities are still accepted by npm for old
            // packages; neither is an integrity hash any more.
            if ($package->pin === null || !str_starts_with($package->pin, 'sha512-')) {
                $violations[] = "{$package->where} has no sha512 integrity.";
            }
            if ($package->source === null || !$this->policy->registryAllows($package->source)) {
                $violations[] = "{$package->where} resolves from " . ($package->source ?? 'nowhere it names')
                    . ', which PackagePolicy::$registries does not allow.';
            }
            if ($package->installScript && !\array_key_exists($package->name, $this->policy->installScripts)) {
                $violations[] = "{$package->where} runs a script when installed, and PackagePolicy::\$installScripts does not allow it.";
            }
        }

        return $violations;
    }

    /** @param list<LockedPackage> $installed
     * @return list<string> */
    private function frameworkPackages(array $installed): array
    {
        $violations = [];
        $counts = [];

        foreach ($installed as $package) {
            if ($this->policy->allows($package->ecosystem, $package->name)) {
                $counts[$package->key()] = ($counts[$package->key()] ?? 0) + 1;
            } elseif ($package->metadata !== null) {
                $violations[] = "{$package->where} carries framework metadata and is not in PackagePolicy::\$packages.";
            }
        }

        try {
            $lock = PackageLock::read($this->root);
        } catch (\RuntimeException $e) {
            return [...$violations, $e->getMessage()];
        }

        foreach ($this->policy->packages as $allowed) {
            $count = $counts[$allowed->key()] ?? 0;
            if ($count === 0) {
                $violations[] = "{$allowed->name} is allowed but not in the {$allowed->ecosystem->value} lockfile.";

                continue;
            }
            if ($count > 1) {
                $violations[] = "{$allowed->name} is installed {$count} times: an enabled package is installed once.";

                continue;
            }

            $package = array_values(array_filter($installed, static fn (LockedPackage $p): bool => $p->key() === $allowed->key()))[0];
            $entry = $lock?->entries[$allowed->key()] ?? null;
            if ($entry === null) {
                $violations[] = "{$allowed->name} is not in " . PackageLock::FILE . ': review it, then run tinvar packages:lock.';

                continue;
            }
            if ($package->directory === null) {
                $violations[] = "{$allowed->name} is not installed.";

                continue;
            }

            $changed = [];
            if ($entry['version'] !== $package->version) {
                $changed[] = "version {$entry['version']} is now {$package->version}";
            }
            if ($entry['pin'] !== $package->pin) {
                $changed[] = 'its lockfile pin changed';
            }
            if ($entry['hash'] !== TreeHash::of($package->directory)) {
                $changed[] = 'its installed files changed';
            }
            if ($changed !== []) {
                $violations[] = "{$allowed->name} is not what " . PackageLock::FILE . ' pins (' . implode(', ', $changed)
                    . '): review the change, then run tinvar packages:lock.';
            }
        }

        foreach (array_keys($lock === null ? [] : $lock->entries) as $key) {
            $known = array_filter($this->policy->packages, static fn (AllowedPackage $p): bool => $p->key() === $key);
            if ($known === []) {
                $violations[] = PackageLock::FILE . " pins {$key}, which PackagePolicy::\$packages does not allow: run tinvar packages:lock.";
            }
        }

        return $violations;
    }

    /**
     * Each workflow parsed as YAML, the way GitHub reads it, rather than
     * line by line: a quoted key, a flow mapping, a block scalar or an
     * escaped character in a double-quoted one each hid a `uses` or a
     * trigger from a line's regex. What the parser refuses -- a duplicate
     * key, a tag, a second document -- fails, as does a shape GitHub would
     * not run, since the check cannot say what it would do.
     *
     * @return list<string>
     */
    private function workflows(): array
    {
        $directory = $this->root . '/.github/workflows';
        $files = array_values(array_filter(
            is_dir($directory) ? scandir($directory) ?: [] : [],
            static fn (string $file): bool => preg_match('/\.ya?ml$/i', $file) === 1,
        ));
        if ($files === []) {
            return [];
        }
        // A dev dependency, which an application may not have installed.
        if (!class_exists(Yaml::class)) {
            return ['.github/workflows cannot be checked without a YAML parser: composer require --dev symfony/yaml.'];
        }

        $violations = [];
        foreach ($files as $file) {
            $name = '.github/workflows/' . $file;
            try {
                $workflow = Yaml::parse((string) file_get_contents($directory . '/' . $file), Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (ParseException $e) {
                $violations[] = "{$name} is not YAML the check can read: {$e->getMessage()}";

                continue;
            }
            if (!\is_array($workflow)) {
                $violations[] = "{$name} is not a mapping the check can read.";

                continue;
            }

            foreach (self::field($workflow, 'on') as $on) {
                $events = match (true) {
                    \is_string($on) => [$on],
                    \is_array($on) && array_is_list($on) => $on,
                    \is_array($on) => array_map(strval(...), array_keys($on)),
                    default => [null],
                };
                foreach ($events as $event) {
                    if (!\is_string($event)) {
                        $violations[] = "{$name} has triggers the check cannot read: an event, a list of events or a map of them.";

                        break;
                    }
                    if (\in_array(strtolower($event), ['pull_request_target', 'workflow_run'], true)) {
                        $violations[] = "{$name} triggers on {$event}, which runs on a fork's behalf with the repository's secrets.";
                    }
                }
            }

            // A step's `uses` runs an action; a job's calls a reusable
            // workflow.
            foreach (self::field($workflow, 'jobs') as $jobs) {
                if (!\is_array($jobs)) {
                    $violations[] = "{$name} jobs is not a mapping the check can read.";

                    continue;
                }
                foreach ($jobs as $id => $job) {
                    if (!\is_array($job)) {
                        $violations[] = "{$name} jobs.{$id} is not a mapping the check can read.";

                        continue;
                    }
                    foreach (self::field($job, 'uses') as $uses) {
                        $violations = [...$violations, ...self::unpinned("{$name} jobs.{$id}", $uses)];
                    }
                    foreach (self::field($job, 'steps') as $steps) {
                        if (!\is_array($steps)) {
                            $violations[] = "{$name} jobs.{$id}.steps is not a list the check can read.";

                            continue;
                        }
                        foreach ($steps as $index => $step) {
                            if (!\is_array($step)) {
                                $violations[] = "{$name} jobs.{$id}.steps[{$index}] is not a mapping the check can read.";

                                continue;
                            }
                            foreach (self::field($step, 'uses') as $uses) {
                                $violations = [...$violations, ...self::unpinned("{$name} jobs.{$id}.steps[{$index}]", $uses)];
                            }
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Every value under $key in a decoded mapping, matching its name in any
     * case: two spellings of one key can both be there.
     *
     * @param array<mixed> $mapping
     * @return list<mixed>
     */
    private static function field(array $mapping, string $key): array
    {
        $values = [];
        foreach ($mapping as $name => $value) {
            if (strtolower((string) $name) === $key) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<string> the violation, when $uses is not pinned to a commit or a digest */
    private static function unpinned(string $at, mixed $uses): array
    {
        if (!\is_string($uses)) {
            return ["{$at} uses something that is not a string."];
        }
        $pinned = str_starts_with($uses, './')
            || preg_match('/^docker:\/\/[^@]+@sha256:[0-9a-f]{64}$/', $uses) === 1
            || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}$/', $uses) === 1;

        return $pinned ? [] : ["{$at} uses {$uses}, which is not pinned to a full commit."];
    }

    /**
     * Composer's own content-hash (Locker::getContentHash): the md5 of the
     * manifest's keys that decide what is locked.
     *
     * @param array<mixed> $manifest
     */
    public static function composerContentHash(array $manifest): string
    {
        $relevant = [];
        foreach (['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'] as $key) {
            if (\array_key_exists($key, $manifest)) {
                $relevant[$key] = $manifest[$key];
            }
        }
        if (isset($manifest['config']['platform'])) {
            $relevant['config']['platform'] = $manifest['config']['platform'];
        }
        ksort($relevant);

        return md5((string) json_encode($relevant, 0));
    }

    private static function insideProject(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_contains($path, '://')
            && !str_contains($path, '\\')
            && preg_match('#(^|/)\.\.(/|$)#', $path) !== 1;
    }
}
