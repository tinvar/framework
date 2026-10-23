<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Container\Lifetime;

/**
 * On a class: a service the container builds, shared for its lifetime.
 *
 *     #[Dependency(Lifetime::Request)]
 *     final class PowerMeter { public function __construct(private Orm $orm) {} }
 *
 * On a property: filled by the container after construction, for a class
 * that cannot take the dependency in its constructor. Constructor
 * parameters are autowired by type without any attribute; this is the
 * exception, not the rule.
 *
 *     #[Dependency] private PowerMeter $meter;
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
final readonly class Dependency
{
    public function __construct(public Lifetime $lifetime = Lifetime::Transient) {}
}
