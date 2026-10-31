<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A declared access check. Authorization runs before anything else: before a
 * cache lookup, validation, batching or Precognition.
 *
 * The Gate runs it -- PolicyGate, through the policy registered for the
 * model it names -- and the build rules hold every entry point and model
 * input to having one (BuildRules).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Authorize
{
    /** @param string|null $argument name of the model-typed input this check covers */
    public function __construct(
        public string $ability,
        public ?string $argument = null,
    ) {}

    /**
     * Every check an entry point declares: the class's first -- its
     * ancestors' and traits' included, see ClassAttributes -- then its
     * method's.
     *
     * One reader for the build rules and every registry. The attribute
     * targets methods, and BuildRules counted a check on `__invoke` while the
     * route and query registries read only the class -- so a check written
     * on the method satisfied the build and never ran.
     *
     * @param class-string $class
     * @return list<self>
     */
    public static function declaredOn(string $class, ?string $method = null): array
    {
        $reflection = new \ReflectionClass($class);
        $attributes = ClassAttributes::of($reflection, self::class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(self::class)];
        }

        return array_map(static fn (\ReflectionAttribute $a): self => $a->newInstance(), $attributes);
    }

    /**
     * How many checks the class declares, ancestors and traits included: the
     * leading part of declaredOn() that runs ahead of the method's own and
     * covers no input of it.
     *
     * @param class-string $class
     */
    public static function classLevelCount(string $class): int
    {
        return \count(ClassAttributes::of($class, self::class));
    }
}
