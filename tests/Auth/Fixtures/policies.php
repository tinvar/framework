<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Fixtures;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Unit;
use Tinvar\Validation\FormRequest;

final readonly class User implements Authenticatable
{
    /** @param list<string> $ownedRackNames */
    public function __construct(
        private string $id,
        public bool $isAdmin = false,
        public array $ownedRackNames = [],
    ) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final class RackPolicy
{
    public function view(?Authenticatable $user, Rack|string $rack): bool
    {
        if (!$user instanceof User) {
            return false;
        }
        if (\is_string($rack)) {
            return true;
        }

        return \in_array($rack->name, $user->ownedRackNames, true);
    }

    public function viewAny(?Authenticatable $user, Rack|string $rack): bool
    {
        return $user instanceof User;
    }
}

/** Has a before() hook: the usual place for "an admin may do anything". */
final class SitePolicy
{
    public function before(?Authenticatable $user, string $ability, mixed $subject): ?bool
    {
        return $user instanceof User && $user->isAdmin ? true : null;
    }

    public function view(?Authenticatable $user, Site|string $site): bool
    {
        return false;
    }
}

/** Registered for Unit but missing the ability an entry point declares. */
final class EmptyPolicy {}

// --- build-rule fixtures ---------------------------------------------------

final readonly class OkData extends Data
{
    public function __construct(public string $name) {}
}

#[Page('/good/{rack}', view: 'good'), Authorize('view', 'rack')]
final class GuardedPage
{
    public function __invoke(Rack $rack): OkData
    {
        return new OkData($rack->name);
    }
}

#[Page('/bad/{rack}', view: 'bad')]
final class UnguardedPage
{
    public function __invoke(Rack $rack): OkData
    {
        return new OkData($rack->name);
    }
}

/** Second and third model arguments count too. */
#[Page('/partial/{rack}/{site}', view: 'partial'), Authorize('view', 'rack')]
final class PartiallyGuardedPage
{
    public function __invoke(Rack $rack, Site $site): OkData
    {
        return new OkData($rack->name);
    }
}

#[Page('/exempt/{rack}', view: 'exempt'), NoAuthorization(reason: 'public status board')]
final class ExemptPage
{
    public function __invoke(Rack $rack): OkData
    {
        return new OkData($rack->name);
    }
}

#[Query(name: 'racks.unguarded')]
final class UnguardedQuery
{
    public function __invoke(Unit $unit): OkData
    {
        return new OkData($unit->name);
    }
}

final class Actions
{
    #[Authorize('update', 'rack')]
    public function rename(Rack $rack, string $name): OkData
    {
        return new OkData($name);
    }

    /** Guards $rack but not $unit. */
    #[Authorize('update', 'rack')]
    public function move(Rack $rack, Unit $unit): OkData
    {
        return new OkData($unit->name);
    }
}

/** A bare id naming a tenant-scoped model. */
final readonly class BadIdRequest extends FormRequest
{
    public function __construct(
        public string $scriptId,
        public int $position,
    ) {}
}

final readonly class GoodIdRequest extends FormRequest
{
    public function __construct(
        public Script $script,
        public int $position,
    ) {}
}
