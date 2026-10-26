<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Fixtures\Review;

use Tinvar\Attributes\Authorize;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Query\BatchQuery;
use Tinvar\Tests\Orm\Fixtures\Rack;

// --- a model hierarchy, for policy specificity ----------------------------

#[Table('animals')]
class Animal extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

class Dog extends Animal {}

final class Puppy extends Dog {}

final class AnimalPolicy
{
    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return true;
    }
}

final class DogPolicy
{
    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return false;
    }
}

interface Ownable {}

final class OwnablePolicy
{
    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return true;
    }
}

#[Table('ambiguous')]
final class AmbiguousSubject extends Animal implements Ownable {}

/** before() is not public, so the gate cannot call it. */
final class ProtectedBeforePolicy
{
    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return true;
    }

    protected function before(?Authenticatable $user, string $ability, mixed $subject): ?bool
    {
        return true;
    }
}

/** Answers with a truthy non-bool, which is not a decision. */
final class StringyPolicy
{
    public function view(?Authenticatable $user, object|string $subject): string
    {
        return 'denied';
    }
}

/** before() denies outright. */
final class BlanketDenyPolicy
{
    public function before(?Authenticatable $user, string $ability, mixed $subject): ?bool
    {
        return false;
    }

    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return true;
    }
}

/** Memoises, which a shared instance makes a cross-request leak. */
final class MemoisingPolicy
{
    private array $seen = [];

    public function view(?Authenticatable $user, object|string $subject): bool
    {
        return $this->seen[0] ??= true;
    }
}

// --- build-rule shapes ----------------------------------------------------

final readonly class RackRefData extends Data
{
    public function __construct(public Rack $rack) {}
}

/** A model smuggled in through a payload: still a model-typed input. */
#[Page('/ref', view: 'ref')]
final class PayloadModelPage
{
    public function __invoke(RackRefData $ref): RackRefData
    {
        return $ref;
    }
}

#[Page('/ref-ok', view: 'refok'), Authorize('view', 'rack')]
final class GuardedPayloadModelPage
{
    public function __invoke(RackRefData $ref): RackRefData
    {
        return $ref;
    }
}

final readonly class WattsData extends Data
{
    public function __construct(public int $watts) {}
}

/** A batch query declares its per-call models on the attribute. */
#[Query(name: 'review.unguardedBatch', args: ['rack' => Rack::class])]
final class UnguardedBatchQuery implements BatchQuery
{
    /** @return list<WattsData> */
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): WattsData => new WattsData(1), $calls);
    }
}

#[Query(name: 'review.guardedBatch', args: ['rack' => Rack::class]), Authorize('view', 'rack')]
final class GuardedBatchQuery implements BatchQuery
{
    /** @return list<WattsData> */
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): WattsData => new WattsData(1), $calls);
    }
}

// --- shared-query build rule ---------------------------------------------

#[Query(name: 'review.sharedClean', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedCleanQuery
{
    public function __invoke(): WattsData
    {
        return new WattsData(42);
    }
}

/** Shared, but the body reads the current user. */
#[Query(name: 'review.sharedLeaky', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedLeakyQuery
{
    public function __construct(private readonly \Tinvar\Auth\AuthContext $auth) {}

    public function __invoke(): WattsData
    {
        return new WattsData($this->auth->check() ? 1 : 0);
    }
}

/** Shared, and escapes tenant scoping. */
#[Query(name: 'review.sharedUnscoped', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedUnscopedQuery
{
    public function __construct(private readonly \Tinvar\Orm\Orm $orm) {}

    public function __invoke(): WattsData
    {
        return $this->orm->acrossTenants(static fn (): WattsData => new WattsData(1));
    }
}

/** Reads the user, but is not shared, so the key separates callers. */
#[Query(name: 'review.perUser', cache: '1m'), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class PerUserQuery
{
    public function __construct(private readonly \Tinvar\Auth\AuthContext $auth) {}

    public function __invoke(): WattsData
    {
        return new WattsData($this->auth->check() ? 1 : 0);
    }
}

/** Reads the user, but only through an injected helper. */
final class Greeter
{
    public function __construct(private readonly \Tinvar\Auth\AuthContext $auth) {}

    public function greeting(): string
    {
        return 'secret of ' . ($this->auth->id() ?? 'nobody');
    }
}

/** Shared, and reaches the user indirectly. A text grep misses this. */
#[Query(name: 'review.sharedIndirect', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedIndirectQuery
{
    public function __construct(private readonly Greeter $greeter) {}

    public function __invoke(): WattsData
    {
        return new WattsData(\strlen($this->greeter->greeting()));
    }
}

interface Clockish
{
    public function now(): int;
}

/** Shared, taking an interface: the type walk cannot see what it will be. */
#[Query(name: 'review.sharedInterface', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedInterfaceQuery
{
    public function __construct(private readonly Clockish $clock) {}

    public function __invoke(): WattsData
    {
        return new WattsData($this->clock->now());
    }
}

/** Shared, reaching the user through a container rather than injection. */
#[Query(name: 'review.sharedContainer', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedContainerQuery
{
    public function __invoke(): WattsData
    {
        $auth = new \Tinvar\Auth\AuthContext();

        return new WattsData($auth->check() ? 1 : 0);
    }
}

/**
 * A shared query that reads the current panel. The spec names
 * Panel::current() in the shared-query build rule; this is the same read
 * through the injected context.
 */
#[Query(name: 'panels.shared', cache: '5m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class PanelReadingSharedQuery
{
    public function __construct(private readonly \Tinvar\Auth\PanelContext $panels) {}

    public function __invoke(): WattsData
    {
        return new WattsData(0);
    }
}
