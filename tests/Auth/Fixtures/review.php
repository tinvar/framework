<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Fixtures\Review;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Table;
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
