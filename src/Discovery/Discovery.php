<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

use Tinvar\Attributes\NotRegistered;
use Tinvar\Auth\ApplicationClasses;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Support\DevMode;
use Tinvar\Support\KnownAttributes;

/**
 * Finds every class an application registers (ADR 0146): its pages,
 * queries, actions, endpoints, models, commands, tasks, policies and the
 * rest, by what each declares, under the application's own namespace as
 * its composer.json's PSR-4 autoload maps it. No Kernel keeps a list a new
 * class is missing from, and no generator edits one.
 *
 *     $classes = Discovery::of(Kernel::class, Kernel::buildDirectory())->classes();
 *
 * What it reads, and nothing else:
 *
 *   - the directories the application's own composer.json -- the nearest
 *     at or above its Kernel's file -- maps in `autoload` to the Kernel's
 *     namespace or a namespace under it, each under the directory that
 *     file is in -- never `autoload-dev`, never a
 *     dependency's, never what Composer or npm installed, tests or
 *     fixtures (ApplicationClasses::files()), walked by namespace, so
 *     which folder a class sits in says nothing;
 *   - in each, a file whose path names a class, whose tokens declare that
 *     class -- parsed first, so a file that does not parse is said and
 *     never run -- loaded through the autoloader, and held to having been
 *     loaded from that very file. A class the application did not write
 *     is never registered, whatever its name. Loading includes the file
 *     whole: code it holds outside its class runs then, at the scan, as it
 *     would whenever the application autoloads the class. A file declaring
 *     no class its path names is never run.
 *
 * Fail closed: a file that does not parse or load, one declaring a class
 * its path does not name, a framework attribute misspelt (KnownAttributes)
 * or on a class nothing registers (Classifier::unclaimed()), two policies
 * for one class -- every one is a problem, and the scan with any is refused
 * whole (DiscoveryFailed). #[NotRegistered] leaves a class alone.
 *
 * Where it comes from:
 *
 *   - in production -- TINVAR_ENV, APP_ENV or NODE_ENV saying so, or
 *     TINVAR_ENV or APP_ENV saying staging -- from the build's manifest
 *     alone (Manifest), never a scan; a missing manifest refuses to boot;
 *   - anywhere else, from a scan, kept for the process and made again when
 *     a file under the namespace changes, or the framework that sorts them
 *     does: a look at each file's time and size, at most once every
 *     $recheck seconds -- as `tinvar serve --dev` watches the sources it runs
 *     codegen for (SourceWatcher). `tinvar check` scans afresh (freshly()).
 */
final class Discovery
{
    /** @var array<string, array{float, string, DiscoveredClasses}> by namespace and map: [last look, the files' fingerprint, what the scan found] */
    private static array $live = [];

    /** Whether classes() scans each time, believing no kept scan: inside freshly(). */
    private static bool $fresh = false;

    /**
     * @param string $namespace the application's root namespace, its Kernel's
     * @param array<string, list<string>> $psr4 the prefixes at or under it, and the directories each maps
     * @param string $manifest the build's manifest file
     * @param string|null $root the application's root, which holds its composer.json: a mapped directory outside it, or under its vendor/ or node_modules/, is refused
     * @param float $recheck seconds a scan is kept before the files are looked at again
     * @param string|null $refused why there is no application to scan, where of() found none: every scan is refused with it
     */
    public function __construct(
        public readonly string $namespace,
        private readonly array $psr4,
        public readonly string $manifest,
        private readonly ?string $root = null,
        private readonly float $recheck = 1.0,
        private readonly int $maxFiles = ApplicationClasses::MAX_FILES,
        private readonly ?string $refused = null,
    ) {}

    /**
     * The discovery of $kernel's application: its namespace, the PSR-4 map
     * under it that the application's own composer.json holds, and the
     * manifest in $buildDirectory.
     *
     * The application's composer.json is the nearest one at or above the
     * directory the Kernel's file is in, as Composer finds the project a
     * file belongs to: read from the application itself, never from
     * whichever package is the root one in this process -- a package's
     * suite run from a project of its own (bin/package-alone) drives the
     * shop, whose classes that project only autoloads. The directory it is
     * in is the application's root, which every mapped directory must be
     * under. A Kernel Composer installed -- at or under a vendor directory,
     * or a dependency's own -- is no application's, and maps nothing.
     *
     * @param class-string $kernel
     */
    public static function of(string $kernel, string $buildDirectory): self
    {
        $slash = strrpos($kernel, '\\');
        $namespace = $slash === false ? '' : substr($kernel, 0, $slash);
        [$root, $psr4, $refused] = self::application($kernel, $namespace);

        return new self(
            $namespace,
            $psr4,
            rtrim($buildDirectory, '/') . '/' . Manifest::FILE,
            $root,
            refused: $refused,
        );
    }

    /**
     * Where $kernel's application is: its root, the map under $namespace
     * its composer.json holds, and why there is none, where there is not.
     *
     * @param class-string $kernel
     * @return array{?string, array<string, list<string>>, ?string}
     */
    private static function application(string $kernel, string $namespace): array
    {
        $file = class_exists($kernel) ? new \ReflectionClass($kernel)->getFileName() : false;
        $directory = $file === false ? false : realpath(\dirname($file));
        if ($directory === false) {
            return [null, [], "{$kernel} is loaded from no file, so there is no composer.json of its application to read"];
        }
        $root = $directory;
        while (!is_file("{$root}/composer.json")) {
            $parent = \dirname($root);
            if ($parent === $root) {
                return [null, [], "there is no composer.json at or above {$directory}, where {$kernel} is"];
            }
            $root = $parent;
        }
        $installed = self::installedBy($root);
        if ($installed !== null) {
            return [$root, [], "{$kernel} is {$installed}, and discovery registers the application's own classes alone"];
        }

        return [$root, ApplicationClasses::fromComposerJson($root)->under($namespace)->prefixes(), null];
    }

    /** What installed $root, where Composer did: a vendor directory it is at or under, or the dependency it is. */
    private static function installedBy(string $root): ?string
    {
        foreach (array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders()) as $vendor) {
            $vendor = realpath($vendor);
            if ($vendor !== false && ($root === $vendor || str_starts_with($root, $vendor . '/'))) {
                return "under what Composer installed in {$vendor}";
            }
        }
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }
        foreach (\Composer\InstalledVersions::getAllRawData() as $data) {
            $own = $data['root']['name'];
            foreach ($data['versions'] as $name => $package) {
                $path = \is_string($package['install_path'] ?? null) ? realpath($package['install_path']) : false;
                if ($name !== $own && $path === $root) {
                    return "in {$name}, which Composer installed";
                }
            }
        }

        return null;
    }

    /**
     * Whether the classes come from the manifest alone: wherever $env does
     * not say a developer's or a test's machine (DevMode::notDevelopment(),
     * ADR 0213) -- production, staging, and an environment that says
     * nothing, which is held to a server's rules. The build's own commands
     * scan inside freshly() instead, whatever $env says.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     */
    public static function production(?array $env = null): bool
    {
        return DevMode::notDevelopment($env) !== null;
    }

    /**
     * The application's classes: the manifest's in production, which must
     * be there; a scan's, kept while the files stay as they were, anywhere
     * else.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws DiscoveryFailed
     */
    public function classes(?array $env = null): DiscoveredClasses
    {
        if (!self::$fresh && self::production($env)) {
            if (!is_file($this->manifest)) {
                throw new DiscoveryFailed([
                    "There is no discovery manifest at {$this->manifest}, and production boots from it alone, never "
                    . 'walking its sources: run `tinvar build` (or `tinvar discover`) where the build is made.',
                ], 'Discovery refused to boot:');
            }

            return Manifest::read($this->manifest, $this->namespace);
        }

        $key = $this->namespace . "\0" . serialize($this->psr4);
        $now = microtime(true);
        if (self::$fresh) {
            $fingerprint = $this->fingerprint();
            $classes = $this->scan();
            $this->cache($fingerprint, $classes);
            self::$live[$key] = [microtime(true), $fingerprint, $classes];

            return $classes;
        }
        $kept = self::$live[$key] ?? null;
        if ($kept !== null && $now - $kept[0] < $this->recheck) {
            return $kept[2];
        }
        $fingerprint = $this->fingerprint();
        if ($kept !== null && $kept[1] === $fingerprint) {
            self::$live[$key] = [$now, $fingerprint, $kept[2]];

            return $kept[2];
        }
        $classes = $this->cached($fingerprint);
        if ($classes === null) {
            $classes = $this->scan();
            $this->cache($fingerprint, $classes);
        }
        self::$live[$key] = [microtime(true), $fingerprint, $classes];

        return $classes;
    }

    /**
     * $callback's answer, with every classes() it asks scanning afresh,
     * whatever the environment says -- neither the manifest, nor the scan
     * this process keeps, nor the one beside the build believed, each
     * replaced by what the scan finds. `tinvar check` and
     * `build:fragments` ask their classes this way: a check vouches for the
     * sources as they are, and a build -- in an image's build stage, which
     * says no TINVAR_ENV -- is made from them, before there is a manifest.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    public static function freshly(\Closure $callback): mixed
    {
        $was = self::$fresh;
        self::$fresh = true;
        try {
            return $callback();
        } finally {
            self::$fresh = $was;
        }
    }

    /** The scan cache beside the manifest, which dev's classic-mode processes share. */
    private function cacheFile(): string
    {
        return \dirname($this->manifest) . '/discovery.dev.json';
    }

    /**
     * What the last scan found, where the files are as they were then:
     * dev serves each request in a process of its own, which would
     * otherwise scan afresh every time. Never read in production.
     */
    private function cached(string $fingerprint): ?DiscoveredClasses
    {
        $file = $this->cacheFile();
        $json = is_file($file) ? file_get_contents($file) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (!\is_array($data) || ($data['fingerprint'] ?? null) !== $fingerprint || !\is_array($data['classes'] ?? null)) {
            return null;
        }
        try {
            /** @var array<string, mixed> $kinds */
            $kinds = $data['classes'];
            $classes = DiscoveredClasses::fromArray($kinds);
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($classes->all() as $class) {
            if (!str_starts_with(strtolower($class), strtolower($this->namespace) . '\\')) {
                return null;
            }
        }

        return $classes;
    }

    /** Keeps $classes for the next process, where the build directory is there to keep them in. */
    private function cache(string $fingerprint, DiscoveredClasses $classes): void
    {
        $file = $this->cacheFile();
        if (!is_dir(\dirname($file)) || is_link($file)) {
            return;
        }
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode(['fingerprint' => $fingerprint, 'classes' => $classes->toArray()], JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($temporary, $json) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
        }
    }

    /**
     * What a look at the files finds now: each file's path, time and size,
     * hashed, with what decides how a class is sorted (rules()). It moves
     * when a file is added, removed or written, and when the framework is.
     */
    public function fingerprint(): string
    {
        // And what decides: a class sorted by other rules is sorted afresh.
        $stamps = [$this->namespace, ...self::rules()];
        foreach ($this->walk()->files() as [, $file]) {
            $stat = @stat($file);
            $stamps[] = $file . ':' . ($stat === false ? '-' : "{$stat['mtime']}:{$stat['size']}");
        }

        return hash('xxh128', implode("\n", $stamps));
    }

    /**
     * What decides how a class is sorted, stamped: the framework's version
     * as Composer installed it, and the time and size of each of its files
     * that sort -- discovery's own, KnownAttributes, EndpointRegistry, every
     * attribute class -- so a framework upgraded or edited in place never
     * leaves a kept scan's old answer standing.
     *
     * @return list<string>
     */
    private static function rules(): array
    {
        $stamps = [];
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('tinvar/framework')) {
            $stamps[] = 'tinvar/framework@' . (\Composer\InstalledVersions::getReference('tinvar/framework') ?? \Composer\InstalledVersions::getPrettyVersion('tinvar/framework') ?? '');
        }
        $files = [
            ...(glob(__DIR__ . '/*.php') ?: []),
            ...(glob(\dirname((string) new \ReflectionClass(NotRegistered::class)->getFileName()) . '/*.php') ?: []),
            (string) new \ReflectionClass(KnownAttributes::class)->getFileName(),
            (string) new \ReflectionClass(EndpointRegistry::class)->getFileName(),
        ];
        foreach ($files as $file) {
            $stat = @stat($file);
            $stamps[] = $file . ':' . ($stat === false ? '-' : "{$stat['mtime']}:{$stat['size']}");
        }

        return $stamps;
    }

    /**
     * A scan, now: every class under the namespace, by what it declares.
     *
     * @throws DiscoveryFailed naming every problem found
     */
    public function scan(): DiscoveredClasses
    {
        if ($this->refused !== null) {
            throw new DiscoveryFailed(["There is no application to discover the classes of: {$this->refused}."]);
        }
        $problems = [];
        if ($this->psr4 === []) {
            $where = $this->root === null ? "The application's composer.json" : "The composer.json at {$this->root}";
            $problems[] = "{$where} autoloads nothing under {$this->namespace}\\: there is nowhere to discover its classes. "
                . 'Map the namespace in its `autoload` psr-4.';
        }
        foreach ($this->psr4 as $prefix => $directories) {
            foreach ($directories as $directory) {
                $problem = $this->outsideTheApplication($directory);
                if ($problem !== null) {
                    $problems[] = "{$prefix} maps {$directory}, {$problem}: discovery registers the application's own classes alone.";
                }
            }
        }
        if ($problems !== []) {
            throw new DiscoveryFailed($problems);
        }

        $walk = $this->walk();
        $files = $walk->files();
        if (!$walk->walkedWhole()) {
            $problems[] = "The autoload map under {$this->namespace}\\ holds more than {$this->maxFiles} files, and discovery stopped there: "
                . 'a class past them would be registered by nothing. Narrow the map.';
        }

        $lists = array_fill_keys(array_keys(DiscoveredClasses::KINDS), []);
        $policies = [];
        $seen = [];
        foreach ($files as [$named, $file]) {
            try {
                $declared = self::declarations($file);
            } catch (\ParseError $e) {
                $problems[] = "{$file} does not parse: {$e->getMessage()} on line {$e->getLine()}.";

                continue;
            }
            if ($declared === []) {
                continue; // A script declaring no class: never included, so never run.
            }
            $class = null;
            foreach ($declared as $candidate) {
                if (strcasecmp($candidate, $named) === 0) {
                    $class = $candidate;
                }
            }
            if ($class === null) {
                $problems[] = "{$file} declares " . implode(', ', $declared) . ", and its path names {$named}: the autoloader cannot load it. "
                    . 'Name the file after its class, or move it.';

                continue;
            }
            $lower = strtolower($class);
            if (isset($seen[$lower])) {
                if ($seen[$lower] !== realpath($file)) {
                    $problems[] = "{$class} is declared in {$seen[$lower]} and in {$file}.";
                }

                continue;
            }
            $seen[$lower] = (string) realpath($file);

            $reflection = $this->load($class, $file, $problems);
            if ($reflection === null) {
                continue;
            }
            foreach ($declared as $other) {
                if ($other !== $class) {
                    $this->beside($other, $class, $file, $problems);
                }
            }
            $this->classify($reflection, $lists, $policies, $problems);
        }

        if ($problems !== []) {
            throw new DiscoveryFailed($problems);
        }
        $lists['policies'] = $policies;

        return DiscoveredClasses::fromArray($lists);
    }

    /**
     * A scan, written to the manifest -- or to $file.
     *
     * @throws DiscoveryFailed
     */
    public function write(?string $file = null): DiscoveredClasses
    {
        $classes = $this->scan();
        Manifest::write($file ?? $this->manifest, $this->namespace, $classes);

        return $classes;
    }

    /**
     * How the manifest -- or $file -- differs from a scan now: nothing
     * when it is current, each difference, or that there is none.
     *
     * @return list<string>
     * @throws DiscoveryFailed where the scan itself is refused
     */
    public function stale(?string $file = null): array
    {
        $file ??= $this->manifest;
        $scan = $this->scan();
        if (!is_file($file)) {
            return ["There is no discovery manifest at {$file}."];
        }
        try {
            $manifest = Manifest::read($file, $this->namespace);
        } catch (DiscoveryFailed $e) {
            return $e->problems;
        }

        return $scan->differences($manifest);
    }

    private function walk(): ApplicationClasses
    {
        return new ApplicationClasses($this->psr4, $this->maxFiles);
    }

    /** Why $directory is no directory of the application's own, or null where it is. */
    private function outsideTheApplication(string $directory): ?string
    {
        $real = realpath($directory);
        if ($real === false) {
            return null; // Nothing there to walk.
        }
        $root = $this->root === null ? false : realpath($this->root);
        $relative = $root !== false && str_starts_with($real, $root . '/') ? substr($real, \strlen($root) + 1) : ($root === $real ? '' : null);
        if ($root !== false && $relative === null) {
            return "outside the application at {$root}";
        }
        foreach (explode('/', strtolower($relative ?? '')) as $segment) {
            if (\in_array($segment, ['vendor', 'node_modules'], true)) {
                return 'under what Composer or npm installed';
            }
        }

        return null;
    }

    /**
     * Loads $class, which $file declares, through the autoloader, and holds
     * it to having come from that file.
     *
     * @param list<string> $problems
     * @return \ReflectionClass<object>|null
     */
    private function load(string $class, string $file, array &$problems): ?\ReflectionClass
    {
        try {
            $exists = class_exists($class) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false);
        } catch (\Throwable $e) {
            $problems[] = "{$class} ({$file}) does not load: " . $e::class . ": {$e->getMessage()}";

            return null;
        }
        if (!$exists) {
            $problems[] = "{$class} ({$file}) does not load: the autoloader does not find it. Run `composer dump-autoload`.";

            return null;
        }
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);
        $from = $reflection->getFileName();
        if ($from === false || realpath($from) !== realpath($file)) {
            $problems[] = "{$class} is loaded from " . ($from === false ? 'no file' : $from) . ", not {$file}: discovery registers the application's own classes alone.";

            return null;
        }

        return $reflection;
    }

    /**
     * Holds $other, declared in $class's file beside it: a class there is
     * loaded only when $class is, so one the framework would register is
     * refused rather than left unregistered.
     *
     * @param list<string> $problems
     */
    private function beside(string $other, string $class, string $file, array &$problems): void
    {
        if (!class_exists($other, false) && !enum_exists($other, false)) {
            return;
        }
        $reflection = new \ReflectionClass($other);
        if ($reflection->getAttributes(NotRegistered::class) !== []) {
            return;
        }
        [$kinds] = Classifier::classify($reflection);
        if ($kinds !== [] || Classifier::unclaimed($reflection, $kinds) !== [] || Classifier::notInherited($reflection) !== null) {
            $problems[] = "{$other} is declared in {$file} beside {$class}, so the autoloader never loads it by its name, and discovery cannot register it: "
                . 'give it a file of its own.';
        }
    }

    /**
     * Puts $class in each kind it is.
     *
     * @param \ReflectionClass<object> $class
     * @param array<string, list<class-string>> $lists
     * @param array<class-string, class-string> $policies
     * @param list<string> $problems
     */
    private function classify(\ReflectionClass $class, array &$lists, array &$policies, array &$problems): void
    {
        $name = $class->getName();
        try {
            $optOut = $class->getAttributes(NotRegistered::class);
            if ($optOut !== []) {
                $optOut[0]->newInstance();

                return;
            }
            KnownAttributes::check($class);
            $dropped = Classifier::notInherited($class);
            if ($dropped !== null) {
                $problems[] = $dropped;

                return;
            }
            [$kinds, $subjects] = Classifier::classify($class);
        } catch (\Throwable $e) {
            $problems[] = "{$name}: {$e->getMessage()}";

            return;
        }

        $unclaimed = Classifier::unclaimed($class, $kinds);
        if ($unclaimed !== []) {
            $problems[] = "{$name} carries " . implode(', ', $unclaimed) . ', and nothing registers it: it is no page, query, action, endpoint or '
                . 'any other kind the framework registers, so what those declare would never be asked. Declare what it is, take them off, '
                . 'or mark it #[NotRegistered].';

            return;
        }
        $hostless = Classifier::hostless($class, $kinds) ?? Classifier::unguarded($class, $kinds);
        if ($hostless !== null) {
            $problems[] = $hostless;

            return;
        }
        foreach ($kinds as $kind) {
            if ($kind !== 'policies') {
                $lists[$kind][] = $name;
            }
        }
        foreach ($subjects as $subject) {
            $subject = ltrim($subject, '\\');
            if (isset($policies[$subject]) && $policies[$subject] !== $name) {
                $problems[] = "{$subject} has two policies, {$policies[$subject]} and {$name}: say which decides by taking #[Policy] off the other.";

                continue;
            }
            $policies[$subject] = $name;
        }
    }

    /**
     * The classes, interfaces, traits and enums $file declares, by full
     * name, read from its tokens without running it.
     *
     * @return list<string>
     * @throws \ParseError where the file does not parse
     */
    public static function declarations(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new \ParseError('it could not be read');
        }
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source, TOKEN_PARSE),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $namespace = '';
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $next = $tokens[$i + 1] ?? null;
                $namespace = $next !== null && $next->is([T_NAME_QUALIFIED, T_STRING]) ? $next->text : '';

                continue;
            }
            if (!$token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW])) {
                continue; // Foo::class, or new class
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $next->is(T_STRING)) {
                $found[] = ltrim("{$namespace}\\{$next->text}", '\\');
            }
        }

        return $found;
    }
}
