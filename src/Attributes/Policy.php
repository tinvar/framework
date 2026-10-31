<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * The policy that decides about a model -- or any class an ability is
 * asked of -- found by discovery and registered for it (ADR 0146).
 *
 *     #[Policy(Order::class)]
 *     final class OrderPolicy
 *     {
 *         public function view(?Authenticatable $user, Order $order): bool { ... }
 *     }
 *
 * Repeatable, for one policy deciding about several classes. Two policies
 * claiming one class is refused when the application's classes are
 * discovered: which one decided would otherwise be the order files were
 * read in.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Policy
{
    /** @param class-string $subject */
    public function __construct(public string $subject) {}
}
