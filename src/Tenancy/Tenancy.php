<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Console\CommandTenants;
use Tinvar\Http\Request;
use Tinvar\Http\TenantResolver;
use Tinvar\Orm\Orm;
use Tinvar\Orm\TenantSubtrees;

/**
 * Tenancy, registered: what an application that requires tinvar/tenancy
 * hands the framework (ADR 0056).
 *
 * Two halves, registered where each is read:
 *
 *     $orm = new Orm($connection, $map, Tenancy::tree($tree));
 *     $tenancy = new Tenancy($container->resolver(), $directory);
 *     new Kernel(..., tenants: $tenancy);
 *     new WebhookEndpoint(..., tenants: $tenancy);
 *     new Console(..., tenants: $tenancy);
 *
 * - tree() is the Orm's: registering it is what lets a #[TenantScoped]
 *   model be read at all, and what reads across a subtree are made with.
 *   The application writes the tree (TenantTree), never the walk.
 * - The object itself is the entry points': it reads the tenant a
 *   #[TenantFrom] source places a request in, held to the session's by the
 *   Orm's checked tree (ResolveTenant), and the tenant a command's
 *   `--tenant=` names, held to the application's TenantDirectory.
 */
final readonly class Tenancy implements TenantResolver, CommandTenants
{
    /**
     * @param \Closure(class-string): object $resolver builds tenant sources, as it builds an entry point's own
     * @param TenantDirectory|null $directory the application's tenants, which a command's
     *        `--tenant=` is checked against; without it, a command declaring
     *        #[TenantFrom(TenantArgument::class)] is refused when the console is built
     */
    public function __construct(private \Closure $resolver, private ?TenantDirectory $directory = null) {}

    /**
     * The tenancy to register with the Orm: the application's tree, walked
     * within its limits, or, with none, tenants side by side.
     */
    public static function tree(?TenantTree $tree = null): TenantSubtrees
    {
        return $tree === null ? new NoHierarchy() : new TenantHierarchy($tree);
    }

    public function check(string $source): void
    {
        self::source($source);
    }

    public function resolve(Orm $orm, string $source, Request $request): string
    {
        $built = ($this->resolver)(self::source($source));
        if (!$built instanceof TenantSource) {
            throw new \LogicException("The resolver built {$source} as " . get_debug_type($built) . ', not a tenant source.');
        }

        return ResolveTenant::resolve($orm, $built, $request);
    }

    /**
     * Only a command argument places a command: Host and Signature read a
     * request, and a command has none, so one declaring either would run
     * with no tenant while its author believed otherwise.
     */
    public function checkCommand(string $command, string $source): void
    {
        $this->placesCommand($command, $source);
    }

    public function commandTenant(string $name, string $source, ?string $named): string
    {
        $this->placesCommand($name, $source);
        if ($named === null || $named === '') {
            throw new \InvalidArgumentException("{$name} runs as a tenant: name it with --" . TenantArgument::OPTION . '=ID.');
        }
        if ($this->directory?->has($named) !== true) {
            throw new \InvalidArgumentException("There is no tenant '{$named}'.");
        }

        return $named;
    }

    /** Refuses a command whose source is not its argument, or whose argument there is nothing to check against. */
    private function placesCommand(string $command, string $source): void
    {
        if (!is_a($source, TenantArgument::class, true)) {
            throw new \InvalidArgumentException(
                "{$command} declares its tenant from {$source}, which reads a request; a command's comes from its argument: "
                . '#[TenantFrom(TenantArgument::class)].',
            );
        }
        if ($this->directory === null) {
            throw new \InvalidArgumentException(
                "{$command} runs as the tenant its argument names, and the tenancy was given no TenantDirectory to check it against.",
            );
        }
    }

    /** @return class-string<TenantSource> */
    private static function source(string $source): string
    {
        if (!is_a($source, TenantSource::class, true)) {
            throw new \InvalidArgumentException(
                "#[TenantFrom({$source})] names no tenant source: the class must implement " . TenantSource::class . '.',
            );
        }

        return $source;
    }
}
