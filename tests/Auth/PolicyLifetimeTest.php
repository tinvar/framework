<?php

declare(strict_types=1);

/**
 * PolicyRegistry kept every policy it built for as long as the registry
 * lived -- the worker's life, in the Kernel -- including policies the
 * application's resolver built with request-lived services. The next
 * request on the worker was then decided by the last one's state.
 */

namespace Tinvar\Tests\Auth\PolicyLifetime;

use Tinvar\Attributes\Dependency;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Container\Lifetime;

/** A request-lived service: the current member's role, fixed when built. */
#[Dependency(Lifetime::Request)]
final class Membership
{
    public static string $current = '';

    public readonly string $role;

    public function __construct()
    {
        $this->role = self::$current;
    }
}

final class Document {}

final class DocumentPolicy
{
    public function __construct(private readonly Membership $membership) {}

    public function delete(?object $user, Document $document): bool
    {
        return $this->membership->role === 'owner';
    }
}

it('asks the resolver for a policy on every use rather than keeping it', function () {
    $container = new Container();
    $policies = (new PolicyRegistry($container->resolver()))->register(Document::class, DocumentPolicy::class);

    Membership::$current = 'owner';
    $first = $policies->for(Document::class);
    $container->endRequest();

    Membership::$current = 'viewer';
    $second = $policies->for(Document::class);

    expect($first->delete(null, new Document()))->toBeTrue()
        ->and($second)->not->toBe($first)
        ->and($second->delete(null, new Document()))->toBeFalse();
});

it('keeps one instance of a policy it builds itself, which has no dependencies', function () {
    $policies = (new PolicyRegistry())->register(Document::class, NoDependencyPolicy::class);

    expect($policies->for(Document::class))->toBe($policies->for(Document::class));
});

final class NoDependencyPolicy
{
    public function delete(?object $user, Document $document): bool
    {
        return false;
    }
}
