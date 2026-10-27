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
 *     workflow a GitHub workflow uses -- and every one a local action it
 *     uses names in turn -- is pinned the same way, by commit: a tag can be
 *     moved. Every container image a job runs in or beside is pinned by
 *     its digest, and so is every image a Dockerfile builds from, copies
 *     from or mounts, and the frontend its syntax line names; a file a
 *     Dockerfile ADDs from the network is held to its checksum.
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

        return [...$violations, ...$this->frameworkPackages($installed), ...$this->workflows(), ...$this->dockerfiles()];
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

            if ($package->downloadOptions) {
                $violations[] = "{$package->where} sets transport-options, which Composer applies to its download alone "
                    . '-- TLS verification, a CA file, headers -- where secure-http and disable-tls do not reach.';
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
            } else {
                // A commit pins an archive only where Composer fetches the
                // archive by it (Lockfiles::unpinnedArchives()).
                foreach ($package->unpinned as $url) {
                    $violations[] = "{$package->where} downloads {$url} as written, which its commit does not pin: "
                        . 'give the dist its shasum, or a URL Composer fetches by the commit.';
                }
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
        $seen = [];
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
                    // The job's own container and its service containers
                    // are pulled by name too, and a tag moves as readily as
                    // an action's does.
                    foreach (self::field($job, 'container') as $container) {
                        $violations = [...$violations, ...self::unpinnedContainer("{$name} jobs.{$id}.container", $container)];
                    }
                    foreach (self::field($job, 'services') as $services) {
                        if (!\is_array($services)) {
                            $violations[] = "{$name} jobs.{$id}.services is not a mapping the check can read.";

                            continue;
                        }
                        foreach ($services as $service => $container) {
                            $violations = [...$violations, ...self::unpinnedContainer("{$name} jobs.{$id}.services.{$service}", $container)];
                        }
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
                                $at = "{$name} jobs.{$id}.steps[{$index}]";
                                $violations = [...$violations, ...self::unpinned($at, $uses), ...$this->localAction($at, $uses, $seen)];
                            }
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The root's directories no Dockerfile of the project's own is in: what
     * is installed, built, or kept by tools. Only at the root: a directory
     * deeper down that shares one of these names is the project's.
     */
    private const array NOT_SOURCE = ['.git', '.claude', 'node_modules', 'vendor', 'dist', 'tools', '.phpstan', '.vitest', '.coverage', '.vite'];

    /**
     * Every Dockerfile in the project -- `Dockerfile`, `Containerfile`,
     * either with a `.suffix`, or `*.Dockerfile` and `*.Containerfile`, in
     * any case, as Docker and Podman take them -- with each image it
     * reaches pinned to a digest: the
     * base of each stage (FROM), what COPY --from and RUN --mount from=
     * take files out of, and the `# syntax=` frontend that parses it. A
     * name its own earlier stages declared, `scratch`, and a stage's number
     * are not images. A name with a variable in it is refused, since what
     * it names is decided by whoever runs the build. A file ADDed from a
     * URL must carry --checksum.
     *
     * @return list<string>
     */
    private function dockerfiles(): array
    {
        $violations = [];
        foreach ($this->dockerfilePaths($this->root, '') as $relative) {
            $violations = [...$violations, ...(is_link("{$this->root}/{$relative}")
                // What a link leads to can be anywhere, and change under the
                // check: refused rather than followed or passed over.
                ? ["{$relative} is a link, which the check does not follow: make it the file itself."]
                : self::dockerfile($relative, (string) file_get_contents("{$this->root}/{$relative}")))];
        }

        return $violations;
    }

    /** @return list<string> paths relative to the root, in a stable order, links among them */
    private function dockerfilePaths(string $directory, string $prefix): array
    {
        $entries = scandir($directory) ?: [];
        sort($entries);
        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ($prefix === '' && \in_array($entry, self::NOT_SOURCE, true))) {
                continue;
            }
            $path = "{$directory}/{$entry}";
            if (is_dir($path) && !is_link($path)) {
                $found = [...$found, ...$this->dockerfilePaths($path, "{$prefix}{$entry}/")];
            } elseif (preg_match('/^((Dockerfile|Containerfile)(\..+)?|.+\.(Dockerfile|Containerfile))$/i', $entry) === 1
                && preg_match('/\.(dockerignore|containerignore)$/i', $entry) !== 1) {
                $found[] = $prefix . $entry;
            }
        }

        return $found;
    }

    /**
     * One Dockerfile's violations. Instructions are read as BuildKit reads
     * them: keywords in any case, a trailing backslash continuing the line,
     * comments dropped -- but for the parser directives above the first
     * instruction.
     *
     * @return list<string>
     */
    public static function dockerfile(string $name, string $contents): array
    {
        $violations = [];
        $lines = preg_split('/\r?\n/', $contents) ?: [];

        // Parser directives: comments of the form `# key=value` before
        // anything else.
        foreach ($lines as $line) {
            if (preg_match('/^\s*#\s*([a-z]+)\s*=\s*(\S+)\s*$/i', $line, $m) !== 1) {
                break;
            }
            if (strtolower($m[1]) === 'syntax' && !self::pinnedImage($m[2])) {
                $violations[] = "{$name} names the frontend {$m[2]}, which is not pinned to a sha256 digest.";
            }
            // Another escape character moves where an instruction ends, and
            // the file would be read here as other instructions than Docker
            // runs: refused, and read no further.
            if (strtolower($m[1]) === 'escape' && $m[2] !== '\\') {
                return [...$violations, "{$name} sets the escape character to {$m[2]}, which the check does not read; only \\ is allowed."];
            }
        }

        $instructions = [];
        $current = '';
        foreach ($lines as $line) {
            // BuildKit takes the leading whitespace off every line, a
            // continued one's too -- Unicode's, so a no-break space before
            // FROM hides nothing.
            $line = self::trimLeadingSpace($line);
            // Blank lines and comments, between instructions or inside a
            // continued one, as BuildKit drops them.
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // Only spaces and tabs may follow the backslash: after a form
            // feed the instruction has ended. The pieces are joined with
            // nothing between, so `FR\` and `OM x` are `FROM x`, and a
            // stage name cut in two is read whole.
            if (preg_match('/^(.*)\\\\[ \t]*$/s', $line, $m) === 1) {
                $current .= $m[1];

                continue;
            }
            $instructions[] = trim($current . $line);
            $current = '';
        }
        if (trim($current) !== '') {
            $instructions[] = trim($current);
        }

        $stages = [];
        foreach ($instructions as $instruction) {
            $words = preg_split('/\s+/', $instruction) ?: [];
            $keyword = strtoupper((string) array_shift($words));
            // What an ONBUILD wraps runs in a build FROM this image, as if
            // it were written there: it is held to the same rules.
            while ($keyword === 'ONBUILD' && $words !== []) {
                $keyword = strtoupper((string) array_shift($words));
            }
            $flags = [];
            while ($words !== [] && str_starts_with($words[0], '--')) {
                $flags[] = (string) array_shift($words);
            }

            if ($keyword === 'FROM') {
                $image = $words[0] ?? '';
                if (!self::stageOrPinned($image, $stages)) {
                    $violations[] = "{$name} builds FROM {$image}, which is not pinned to a sha256 digest.";
                }
                if (isset($words[1], $words[2]) && strtoupper($words[1]) === 'AS') {
                    $stages[] = strtolower($words[2]);
                }

                continue;
            }

            foreach ($flags as $flag) {
                $from = null;
                if (\in_array($keyword, ['COPY', 'ADD'], true) && preg_match('/^--from=(.+)$/i', $flag, $m) === 1) {
                    $from = $m[1];
                } elseif ($keyword === 'RUN' && preg_match('/^--mount=(.+)$/i', $flag, $m) === 1) {
                    foreach (explode(',', $m[1]) as $option) {
                        if (preg_match('/^from=(.+)$/i', $option, $o) === 1) {
                            $from = $o[1];
                        }
                    }
                }
                if ($from !== null && !self::stageOrPinned($from, $stages)) {
                    $violations[] = "{$name} {$keyword} takes files from {$from}, which is not pinned to a sha256 digest.";
                }
            }

            if ($keyword === 'ADD') {
                $checksum = null;
                foreach ($flags as $flag) {
                    if (preg_match('/^--checksum=(.+)$/i', $flag, $m) === 1) {
                        $checksum = $m[1];
                    }
                }
                foreach ($words as $source) {
                    $violation = self::unpinnedAdd($name, $source, $checksum);
                    if ($violation !== null) {
                        $violations[] = $violation;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * An ADD source fetched from anywhere but the build context: any
     * `scheme://` URL, or a `user@host:path` git address. A git source --
     * one of those, git://, ssh://, a path ending in .git, or a URL with a
     * #ref -- is pinned by a full commit, as its #ref or as --checksum, which
     * BuildKit reads as the commit for git; anything else by
     * --checksum=sha256.
     */
    private static function unpinnedAdd(string $name, string $source, ?string $checksum): ?string
    {
        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1;
        $scp = preg_match('#^[^\s/@]+@[^\s/:]+:#', $source) === 1;
        if (!$url && !$scp) {
            return null;
        }

        $parts = explode('#', $source, 2);
        $location = $parts[0];
        $ref = $parts[1] ?? null;
        $git = $scp
            || preg_match('#^(git|ssh|git\+[a-z]+)://#i', $source) === 1
            || str_ends_with(strtolower($location), '.git')
            || $ref !== null;
        if (!$git) {
            return $checksum !== null && preg_match('/^sha256:[0-9a-f]{64}$/i', $checksum) === 1
                ? null
                : "{$name} ADDs {$source} with no --checksum=sha256: to hold it to.";
        }

        $commit = '/^[0-9a-f]{40}$/i';
        $pinned = ($ref !== null && preg_match($commit, explode(':', $ref, 2)[0]) === 1)
            || ($checksum !== null && preg_match($commit, $checksum) === 1);

        return $pinned ? null : "{$name} ADDs the git source {$source}, which is not pinned to a full commit.";
    }

    /**
     * $line without its leading whitespace, as Go's unicode.IsSpace reads
     * it: matched as UTF-8 bytes, so a line that is not valid UTF-8 further
     * on is trimmed as Go trims it, up to the first byte that is no space.
     */
    private static function trimLeadingSpace(string $line): string
    {
        return (string) preg_replace(
            '/^(?:[\t\n\v\f\r ]|\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/',
            '',
            $line,
        );
    }

    /** @param list<string> $stages the names this Dockerfile's earlier stages declared */
    private static function stageOrPinned(string $image, array $stages): bool
    {
        if (str_contains($image, '$')) {
            return false;
        }

        return \in_array(strtolower($image), $stages, true)
            || strtolower($image) === 'scratch'
            || preg_match('/^[0-9]+$/', $image) === 1
            || self::pinnedImage($image);
    }

    private static function pinnedImage(string $image): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/:-]*@sha256:[0-9a-f]{64}$/', $image) === 1;
    }

    /**
     * A local action's own `uses`, held to the same rule: a composite action
     * runs the steps in its action.yml as though the workflow had named
     * them, and a Docker action pulls its image. Each local action is read
     * once, through every local action it names in turn -- all of them
     * paths from the repository's root, as GitHub resolves them -- and one
     * whose action.yml is not there, or not inside the project, fails.
     *
     * @param array<string, true> $seen the local actions read already
     * @return list<string>
     */
    private function localAction(string $at, mixed $uses, array &$seen): array
    {
        if (!\is_string($uses) || !str_starts_with($uses, './')) {
            return [];
        }
        $path = trim(substr($uses, 2), '/');
        if ($path !== '' && !self::insideProject($path)) {
            return ["{$at} uses {$uses}, a local action outside the project."];
        }
        if (isset($seen[$path])) {
            return [];
        }
        $seen[$path] = true;

        $directory = $path === '' ? '' : $path . '/';
        $file = array_find(['action.yml', 'action.yaml'], fn (string $name): bool => is_file("{$this->root}/{$directory}{$name}") && !is_link("{$this->root}/{$directory}{$name}"));
        if ($file === null) {
            return ["{$at} uses {$uses}, a local action with no action.yml the check can read."];
        }
        $name = $directory . $file;
        try {
            $action = Yaml::parse((string) file_get_contents("{$this->root}/{$name}"), Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            return ["{$name} is not YAML the check can read: {$e->getMessage()}"];
        }
        if (!\is_array($action)) {
            return ["{$name} is not a mapping the check can read."];
        }

        $violations = [];
        foreach (self::field($action, 'runs') as $runs) {
            if (!\is_array($runs)) {
                $violations[] = "{$name} runs is not a mapping the check can read.";

                continue;
            }
            // An image pulled is pinned by its digest; a Dockerfile of the
            // action's own is built from the repository.
            foreach (self::field($runs, 'image') as $image) {
                if (!\is_string($image) || str_starts_with(strtolower($image), 'docker://')) {
                    $violations = [...$violations, ...self::unpinned("{$name} runs.image", $image)];
                }
            }
            foreach (self::field($runs, 'steps') as $steps) {
                if (!\is_array($steps)) {
                    $violations[] = "{$name} runs.steps is not a list the check can read.";

                    continue;
                }
                foreach ($steps as $index => $step) {
                    if (!\is_array($step)) {
                        $violations[] = "{$name} runs.steps[{$index}] is not a mapping the check can read.";

                        continue;
                    }
                    foreach (self::field($step, 'uses') as $inner) {
                        $innerAt = "{$name} runs.steps[{$index}]";
                        $violations = [...$violations, ...self::unpinned($innerAt, $inner), ...$this->localAction($innerAt, $inner, $seen)];
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
     * A job's container or a service: an image name, or a mapping whose
     * `image` is one. Each is pinned by its digest -- the tag beside it, if
     * any, is a label and no more. An expression is refused, since what it
     * names is not known until the workflow runs.
     *
     * @return list<string> the violations, when $container is not pinned to a digest
     */
    private static function unpinnedContainer(string $at, mixed $container): array
    {
        $images = \is_array($container) ? self::field($container, 'image') : [$container];
        if ($images === []) {
            return ["{$at} names no image the check can read."];
        }

        $violations = [];
        foreach ($images as $image) {
            if (!\is_string($image)) {
                $violations[] = "{$at} image is not a string.";

                continue;
            }
            $name = str_starts_with(strtolower($image), 'docker://') ? substr($image, 9) : $image;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/:-]*@sha256:[0-9a-f]{64}$/', $name) !== 1) {
                $violations[] = "{$at} image {$image} is not pinned to a sha256 digest.";
            }
        }

        return $violations;
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
