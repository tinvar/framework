<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Shared;
use Tinvar\Auth\PanelBinding;
use Tinvar\Container\Dependency;
use Tinvar\Container\Lifetime;
use Tinvar\Http\Request;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;

/**
 * Every key a SharedData provider declares, with the method that produces
 * it and the types it may produce: what a render reads (SharedReads) and
 * what codegen types `shared()` by.
 *
 * Everything a read could trip over is refused here, when the provider is
 * registered, rather than on the render that first reads the key: a key
 * that is not a name, two providers claiming one key, a method a render
 * cannot call, a return type the encoder would refuse, and a provider that
 * could carry one visitor's values into the next request on a worker.
 */
final class SharedRegistry
{
    /**
     * @var array<string, array{class: class-string<SharedData>, method: string, panels: list<Panel>,
     *     classes: list<class-string>, nullable: bool, parameters: list<class-string>}>
     */
    private array $keys = [];

    /** @param list<class-string<SharedData>> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /** @param class-string $class */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, SharedData::class) || !($reflection = new \ReflectionClass($class))->isInstantiable()) {
            throw new \InvalidArgumentException(
                "{$class} is registered as shared data, and is not a class implementing " . SharedData::class . ' that can be built.',
            );
        }
        KnownAttributes::check($reflection);

        self::assertRequestLived($reflection);

        $declared = 0;
        foreach ($reflection->getMethods() as $method) {
            $attribute = $method->getAttributes(Shared::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }
            $declared++;

            $key = $attribute->newInstance()->key ?? $method->getName();
            $where = "{$class}::{$method->getName()}()";

            // A name, because it is a property of the generated map and
            // the tail of the call name the renderer sends; never the one
            // key a JavaScript object cannot hold as data.
            if (!Str::isIdentifier($key) || $key === Encoder::RESERVED_KEY) {
                throw new \InvalidArgumentException("{$where} shares the key '{$key}', which is not an identifier.");
            }
            $taken = $this->keys[$key] ?? null;
            if ($taken !== null) {
                throw new \InvalidArgumentException(
                    "Two shared values are named '{$key}': {$taken['class']}::{$taken['method']}() and {$where}.",
                );
            }
            if (!$method->isPublic() || $method->isStatic()) {
                throw new \InvalidArgumentException("{$where} is #[Shared], and only a public instance method can be read.");
            }

            [$classes, $nullable] = self::returns($method, $where);
            $panels = Panel::declaredOn($class, $method->getName());
            self::assertPlacedOnly($panels, $where);

            $this->keys[$key] = [
                'class' => $reflection->getName(),
                'method' => $method->getName(),
                'panels' => $panels,
                'classes' => $classes,
                'nullable' => $nullable,
                'parameters' => self::parameters($method, $where),
            ];
        }

        if ($declared === 0) {
            throw new \InvalidArgumentException("{$class} is registered as shared data, and has no #[Shared] method.");
        }
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    /** @return class-string<SharedData> */
    public function classOf(string $key): string
    {
        return $this->entry($key)['class'];
    }

    public function methodOf(string $key): string
    {
        return $this->entry($key)['method'];
    }

    /**
     * What the key's method takes, in order: Request or DataContext.
     *
     * @return list<class-string>
     */
    public function parametersOf(string $key): array
    {
        return $this->entry($key)['parameters'];
    }

    /**
     * Every #[Panel] the key's class and method declare; none places it
     * by its class's namespace, or in no panel.
     *
     * @return list<Panel>
     */
    public function panelsOf(string $key): array
    {
        return $this->entry($key)['panels'];
    }

    /**
     * The Data classes a key's value may be -- a ProvidesData read as what
     * its toData() returns -- and whether it may be null: what codegen
     * types the key as.
     *
     * @return array{list<class-string<Data>>, bool}
     */
    public function typesOf(string $key): array
    {
        $entry = $this->entry($key);
        $classes = [];
        $nullable = $entry['nullable'];
        foreach ($entry['classes'] as $class) {
            if (is_subclass_of($class, Data::class)) {
                $classes[] = $class;
                continue;
            }
            [$provided, $mayBeNull] = self::provided($class);
            $classes = [...$classes, ...$provided];
            $nullable = $nullable || $mayBeNull;
        }

        return [array_values(array_unique($classes)), $nullable];
    }

    /**
     * What a ProvidesData's toData() is declared to return: its Data
     * classes, and whether it may be null.
     *
     * @param class-string $class
     * @return array{list<class-string<Data>>, bool}
     */
    public static function provided(string $class): array
    {
        $type = new \ReflectionMethod($class, 'toData')->getReturnType();
        $members = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $classes = [];
        foreach ($members as $member) {
            if ($member instanceof \ReflectionNamedType && !$member->isBuiltin() && is_subclass_of($member->getName(), Data::class)) {
                $classes[] = $member->getName();
            }
        }

        // The interface's own `?Data` names no class to type it by.
        if ($classes === []) {
            throw new \InvalidArgumentException(
                "{$class}::toData() is declared to return no Data class of its own. Narrow its return type -- "
                . '`?CustomerData` -- so the value can be typed.',
            );
        }

        return [$classes, $type->allowsNull()];
    }

    /** @return array{class: class-string<SharedData>, method: string, panels: list<Panel>, classes: list<class-string>, nullable: bool, parameters: list<class-string>} */
    private function entry(string $key): array
    {
        return $this->keys[$key] ?? throw new \OutOfBoundsException("No shared value is named '{$key}'.");
    }

    /**
     * Refuses a provider that could outlive the render it was built for.
     *
     * The values it produces are one visitor's, read with that visitor's
     * request. A worker-lived instance would hold them -- or what it was
     * built from -- for the next request on the worker, another visitor's;
     * so would a static property, whatever the instance's life.
     *
     * @param \ReflectionClass<SharedData> $class
     */
    private static function assertRequestLived(\ReflectionClass $class): void
    {
        for ($level = $class; $level !== false; $level = $level->getParentClass()) {
            foreach ($level->getAttributes(Dependency::class) as $dependency) {
                if ($dependency->newInstance()->lifetime === Lifetime::Worker) {
                    throw new \InvalidArgumentException(
                        "{$class->getName()} is shared data, and #[Dependency(Lifetime::Worker)]: it would carry one "
                        . "visitor's values into the next request on the worker. Leave it transient.",
                    );
                }
            }
        }

        foreach ($class->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            throw new \InvalidArgumentException(
                "{$class->getName()}::\${$property->getName()} is static, and {$class->getName()} is shared data: a static "
                . "property outlives the request, and would carry one visitor's values into the next. Keep it on the instance.",
            );
        }
    }

    /**
     * The classes a #[Shared] method may return, and whether null.
     *
     * Each member a Data class or a ProvidesData, which the encoder can
     * take: an array, a scalar or `mixed` would cross untyped, and the
     * point of declaring the key is that the view reading it is typed.
     *
     * @return array{list<class-string>, bool}
     */
    private static function returns(\ReflectionMethod $method, string $where): array
    {
        $type = $method->getReturnType();
        $members = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => null,
        };
        if ($members === null) {
            throw new \InvalidArgumentException("{$where} is #[Shared] with no return type. Declare the Data class it returns.");
        }

        $classes = [];
        foreach ($members as $member) {
            if (!$member instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException("{$where} is #[Shared] with an intersection type, which cannot be typed.");
            }
            $name = $member->getName();
            if ($name === 'null') {
                continue;
            }
            if ($member->isBuiltin() || (!is_subclass_of($name, Data::class) && !is_subclass_of($name, ProvidesData::class))) {
                throw new \InvalidArgumentException(
                    "{$where} is #[Shared] and may return {$name}. A shared value is a Data object, a ProvidesData, or null.",
                );
            }
            if (is_subclass_of($name, ProvidesData::class)) {
                self::provided($name);
            }
            $classes[] = $name;
        }

        if ($classes === []) {
            throw new \InvalidArgumentException("{$where} is #[Shared] and can only return null.");
        }

        return [$classes, $type->allowsNull()];
    }

    /**
     * What the method takes. A render calls it with nothing but what it
     * knows of itself, so every parameter is the Request or the
     * DataContext; a service goes in the class's constructor, which the
     * application's resolver builds.
     *
     * @return list<class-string>
     */
    private static function parameters(\ReflectionMethod $method, string $where): array
    {
        $out = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($name !== Request::class && $name !== DataContext::class) {
                throw new \InvalidArgumentException(
                    "{$where} takes \${$parameter->getName()}, and a shared value's method may take only the "
                    . 'Request and a DataContext. Take a service in the constructor.',
                );
            }
            $out[] = $name;
        }

        return $out;
    }

    /**
     * A provider's #[Panel] places it, and nothing more.
     *
     * `requires:` and `permission:` narrow who may reach an entry point,
     * and a shared value is not one: it is read inside a page that was
     * reached, behind that page's stack. Carried here they would read as
     * a check nothing makes, so they are refused; a value only some of a
     * panel's members may see belongs in a query with its own #[Authorize].
     *
     * @param list<Panel> $panels
     */
    private static function assertPlacedOnly(array $panels, string $where): void
    {
        try {
            $binding = PanelBinding::of($panels);
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException("{$where}: {$e->getMessage()}", 0, $e);
        }

        if ($binding !== null && ($binding->kinds !== [] || $binding->permissions !== [])) {
            throw new \InvalidArgumentException(
                "{$where} is shared data in panel '{$binding->name}' with a `requires:` or `permission:`, which a shared "
                . 'value cannot be held to. Put what needs it in a query with its own #[Authorize].',
            );
        }
    }
}
