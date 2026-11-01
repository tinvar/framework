<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

/**
 * An application's classes, by what the framework registers each as
 * (ADR 0146): what discovery found under the application's namespace,
 * with whatever its Kernel adds by hand.
 *
 *     $classes = Discovery::of(Kernel::class, $buildDirectory)->classes()
 *         ->with(tasks: [PruneQueue::class], configs: [FrameworkConfig::class]);
 *     $routes->registerAll($classes->routes);
 *
 * One class may be several kinds: a query carrying #[Tool] is in queries
 * and in tools; a class with #[Action] methods and #[Get] ones is in
 * actions and endpoints. Each list is sorted by class name, discovered
 * classes first and those added by hand after them, each class once --
 * deduplicated by name, case-insensitively, as PHP names classes.
 */
final readonly class DiscoveredClasses
{
    /**
     * Every kind, and what puts a class in it. `policies` is a map of the
     * class decided about to its policy, every other kind a list.
     */
    public const array KINDS = [
        'routes' => 'pages, layouts, error pages and admin resources: #[Page], #[Layout], #[ErrorPage], #[AdminResource]',
        'queries' => '#[Query]',
        'actions' => 'a public #[Action] method',
        'endpoints' => 'a public #[Get], #[Post] or #[Endpoint] method',
        'channels' => '#[Channel]',
        'transfers' => '#[Export] or #[Import]',
        'shared' => 'SharedData, or a public #[Shared] method',
        'policies' => '#[Policy(Subject::class)]',
        'tasks' => '#[Schedule]',
        'commands' => '#[Command]',
        'configs' => '#[Config]',
        'models' => 'a subclass of the ORM\'s Model',
        'payloads' => 'a subclass of Data',
        'fieldEnums' => 'an enum carrying #[FieldsOf]',
        'scenarios' => '#[Scenario]',
        'jobs' => 'Job',
        'workflows' => 'Workflow',
        'listeners' => 'a public #[Listener] method',
        'mailables' => 'Mailable',
        'tools' => 'a query or action carrying #[Tool]',
        'services' => 'a query or action carrying #[ServiceCall]',
        'webhooks' => '#[Webhook]',
    ];

    /**
     * @param list<class-string> $routes
     * @param list<class-string> $queries
     * @param list<class-string> $actions
     * @param list<class-string> $endpoints
     * @param list<class-string> $channels
     * @param list<class-string> $transfers
     * @param list<class-string<\Tinvar\Data\SharedData>> $shared
     * @param array<class-string, class-string> $policies the class decided about => its policy
     * @param list<class-string> $tasks
     * @param list<class-string<\Tinvar\Console\Command>> $commands
     * @param list<class-string> $configs
     * @param list<class-string<\Tinvar\Orm\Model>> $models
     * @param list<class-string> $payloads
     * @param list<class-string> $fieldEnums
     * @param list<class-string> $scenarios
     * @param list<class-string> $jobs
     * @param list<class-string> $workflows
     * @param list<class-string> $listeners
     * @param list<class-string> $mailables
     * @param list<class-string> $tools
     * @param list<class-string> $services
     * @param list<class-string> $webhooks
     */
    public function __construct(
        public array $routes = [],
        public array $queries = [],
        public array $actions = [],
        public array $endpoints = [],
        public array $channels = [],
        public array $transfers = [],
        public array $shared = [],
        public array $policies = [],
        public array $tasks = [],
        public array $commands = [],
        public array $configs = [],
        public array $models = [],
        public array $payloads = [],
        public array $fieldEnums = [],
        public array $scenarios = [],
        public array $jobs = [],
        public array $workflows = [],
        public array $listeners = [],
        public array $mailables = [],
        public array $tools = [],
        public array $services = [],
        public array $webhooks = [],
    ) {}

    /**
     * Built from kinds, each list sorted and deduplicated.
     *
     * @param array<string, mixed> $kinds by kind, as toArray() writes them
     * @throws \InvalidArgumentException naming a kind there is none of, or an entry that is no class name
     */
    public static function fromArray(array $kinds): self
    {
        $lists = [];
        foreach ($kinds as $kind => $classes) {
            if (!\array_key_exists($kind, self::KINDS)) {
                throw new \InvalidArgumentException("'{$kind}' is no kind of class discovery finds: " . implode(', ', array_keys(self::KINDS)) . '.');
            }
            if (!\is_array($classes)) {
                throw new \InvalidArgumentException("The {$kind} are not a list.");
            }
            $lists[$kind] = $kind === 'policies' ? self::policyMap($classes) : self::sorted(self::names($kind, $classes));
        }

        return self::make($lists);
    }

    /**
     * Every kind, each a list of class names -- policies, a map.
     *
     * @return array<string, list<class-string>|array<class-string, class-string>>
     */
    public function toArray(): array
    {
        $kinds = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $kinds[$kind] = $this->{$kind};
        }

        return $kinds;
    }

    /**
     * These classes, with $additions -- registered by hand -- after them:
     * each class once, a policy given here taking the discovered one's
     * place for its class.
     *
     *     $classes->with(tasks: [PruneQueue::class], configs: [FrameworkConfig::class])
     *
     * @param array<class-string>|array<class-string, class-string> ...$additions by kind
     * @throws \InvalidArgumentException naming a kind there is none of
     */
    public function with(array ...$additions): self
    {
        $lists = $this->toArray();
        foreach ($additions as $kind => $classes) {
            if (!\is_string($kind) || !\array_key_exists($kind, self::KINDS)) {
                throw new \InvalidArgumentException("'{$kind}' is no kind of class discovery finds: " . implode(', ', array_keys(self::KINDS)) . '.');
            }
            if ($kind === 'policies') {
                /** @var array<class-string, class-string> $current */
                $current = $lists['policies'];
                $merged = [...$current, ...self::policyMap($classes)];
                ksort($merged);
                $lists['policies'] = $merged;

                continue;
            }
            /** @var list<class-string> $current */
            $current = $lists[$kind];
            $lists[$kind] = self::unique([...$current, ...self::names($kind, $classes)]);
        }

        return self::make($lists);
    }

    /**
     * These classes without $classes, from every kind: for a test that
     * wants the application without one of its own.
     */
    public function without(string ...$classes): self
    {
        $gone = array_flip(array_map(strtolower(...), $classes));
        $lists = [];
        foreach ($this->toArray() as $kind => $names) {
            $kept = [];
            foreach ($names as $key => $class) {
                if (!isset($gone[strtolower($class)]) && !($kind === 'policies' && isset($gone[strtolower((string) $key)]))) {
                    $kept[$key] = $class;
                }
            }
            $lists[$kind] = $kind === 'policies' ? $kept : array_values($kept);
        }

        return self::make($lists);
    }

    /**
     * Every class in any kind, each once, sorted.
     *
     * @return list<class-string>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->toArray() as $kind => $names) {
            foreach ($kind === 'policies' ? array_values($names) : $names as $class) {
                $all[] = $class;
            }
        }

        return self::sorted($all);
    }

    /**
     * How $other differs from these classes, kind by kind: what it lacks
     * and what it has besides, each said once. None when they are the same.
     *
     * @return list<string>
     */
    public function differences(self $other, string $these = 'the scan', string $those = 'the manifest'): array
    {
        $said = [];
        foreach (array_keys(self::KINDS) as $kind) {
            if ($kind === 'policies') {
                $mine = array_map(static fn (string $s, string $p): string => "{$s} => {$p}", array_keys($this->policies), $this->policies);
                $theirs = array_map(static fn (string $s, string $p): string => "{$s} => {$p}", array_keys($other->policies), $other->policies);
            } else {
                $mine = $this->{$kind};
                $theirs = $other->{$kind};
            }
            foreach (array_diff($mine, $theirs) as $class) {
                $said[] = "{$kind}: {$class} is in {$these} and not in {$those}";
            }
            foreach (array_diff($theirs, $mine) as $class) {
                $said[] = "{$kind}: {$class} is in {$those} and not in {$these}";
            }
        }

        return $said;
    }

    /** @param array<string, array<mixed>> $lists */
    private static function make(array $lists): self
    {
        /** @phpstan-ignore argument.type (each list is held to its kind above) */
        return new self(...$lists);
    }

    /**
     * @param array<mixed> $classes
     * @return list<class-string>
     */
    private static function names(string $kind, array $classes): array
    {
        $names = [];
        foreach ($classes as $class) {
            if (!\is_string($class) || !self::isClassName($class)) {
                throw new \InvalidArgumentException("The {$kind} hold " . var_export($class, true) . ', which is no class name.');
            }
            /** @var class-string $class */
            $names[] = ltrim($class, '\\');
        }

        return $names;
    }

    /**
     * @param array<mixed> $map
     * @return array<class-string, class-string>
     */
    private static function policyMap(array $map): array
    {
        $policies = [];
        foreach ($map as $subject => $policy) {
            if (!\is_string($subject) || !self::isClassName($subject) || !\is_string($policy) || !self::isClassName($policy)) {
                throw new \InvalidArgumentException('The policies hold ' . var_export([$subject => $policy], true) . ', which is no class mapped to its policy.');
            }
            /** @var class-string $subject */
            /** @var class-string $policy */
            $policies[ltrim($subject, '\\')] = ltrim($policy, '\\');
        }
        ksort($policies);

        return $policies;
    }

    private static function isClassName(string $name): bool
    {
        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $name) === 1;
    }

    /**
     * @param list<class-string> $classes
     * @return list<class-string>
     */
    private static function unique(array $classes): array
    {
        $seen = [];
        $unique = [];
        foreach ($classes as $class) {
            if (!isset($seen[strtolower($class)])) {
                $seen[strtolower($class)] = true;
                $unique[] = $class;
            }
        }

        return $unique;
    }

    /**
     * @param list<class-string> $classes
     * @return list<class-string>
     */
    private static function sorted(array $classes): array
    {
        $classes = self::unique($classes);
        usort($classes, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $classes;
    }
}
