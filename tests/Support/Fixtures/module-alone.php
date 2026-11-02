<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\Fixtures;

use Tinvar\Packages\Layers;

/**
 * A module's own run (bin/module-alone, ADR 0114): its tests with nothing of
 * the framework loadable but the module, what it may use by the layer map,
 * and the core under it.
 *
 * The layer rule reads names; this holds what names cannot -- a class built
 * at runtime, one a fixture loads for a test -- as the queue's own process
 * does for the queue (ADR 0077). Every other Tinvar class is refused by an
 * autoloader put in front of Composer's, which throws naming it, so the
 * test that reached for it fails; and when the run ends, any such class
 * declared some other way -- a file required by hand -- fails the run.
 *
 * The tests' own fixtures (Tinvar\Tests) are not the framework's and are
 * not held; what they load of the framework is. Nor is an attribute's
 * class: the core loads every Tinvar attribute it finds on a class it
 * compiles, to refuse one that names no class (KnownAttributes), so an
 * action that is a tool and a service call loads both attributes in
 * either module's run. Loading one runs nothing of its module; only the
 * module that reads it makes one.
 */
final class ModuleAlone
{
    /** The modules a run can hold: the map's layers between the core and the kernel, and the queue. */
    public const array MODULES = ['queue', 'jobs', 'events', 'mail', 'webhooks', 'transfers', 'workflows', 'push', 'search', 'notifications', 'calls', 'tools', 'services', 'gateway', 'accounts', 'audit', 'api'];

    /** @var list<string> what was refused, in order */
    public static array $refused = [];

    private static ?string $module = null;

    /** Holds the rest of this process to what $module may load. */
    public static function hold(string $module): void
    {
        if (!\in_array($module, self::MODULES, true)) {
            throw new \InvalidArgumentException("No module '{$module}' runs alone: it is one of " . implode(', ', self::MODULES) . '.');
        }
        if (self::$module !== null) {
            throw new \LogicException('This process already holds ' . self::$module . '.');
        }
        self::$module = $module;
        $layers = Layers::modules();

        $outside = self::outside($layers, $module);
        if ($outside !== []) {
            throw new \LogicException("{$module} alone: loaded before the run began: " . implode(', ', $outside) . '.');
        }

        spl_autoload_register(static function (string $class) use ($layers, $module): void {
            if (self::allowed($layers, $module, $class)) {
                return;
            }
            self::$refused[] = $class;
            fwrite(\STDERR, "{$module} alone: refused {$class}\n");

            throw new \LogicException("{$module} alone: {$class} is the " . $layers->layerOf($class) . " layer's, which {$module} may not use.");
        }, true, true);

        register_shutdown_function(static function () use ($layers, $module): void {
            $outside = self::outside($layers, $module);
            if ($outside !== []) {
                fwrite(\STDERR, "{$module} alone: declared though refused: " . implode(', ', $outside) . "\n");

                exit(1);
            }
        });
    }

    /** Whether $module may load $class. */
    public static function allowed(Layers $layers, string $module, string $class): bool
    {
        $class = ltrim($class, '\\');
        if (strncasecmp($class, 'Tinvar\\', 7) !== 0 || strncasecmp($class, 'Tinvar\\Tests\\', 13) === 0 || strncasecmp($class, 'Tinvar\\Attributes\\', 18) === 0) {
            return true;
        }
        $layer = $layers->layerOf($class);

        return $layer !== null && $layers->mayUse($module, $layer);
    }

    /**
     * Every class, interface, trait and enum declared now that $module may
     * not load.
     *
     * @return list<string>
     */
    private static function outside(Layers $layers, string $module): array
    {
        return array_values(array_filter(
            [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
            // The map's reader is the run's, not the module's.
            static fn (string $class): bool => $class !== Layers::class && !self::allowed($layers, $module, $class),
        ));
    }
}
