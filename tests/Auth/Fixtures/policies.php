<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Fixtures;

use Tinvar\Attributes\Action;
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
    #[Action, Authorize('update', 'rack')]
    public function rename(Rack $rack, string $name): OkData
    {
        return new OkData($name);
    }

    /** Guards $rack but not $unit. */
    #[Action, Authorize('update', 'rack')]
    public function move(Rack $rack, Unit $unit): OkData
    {
        return new OkData($unit->name);
    }

    /** No declaration at all: the mistake the rule exists to catch. */
    #[Action]
    public function destroy(Rack $rack): OkData
    {
        return new OkData('gone');
    }

    /** Static, so the runners could never call it. */
    #[Action, Authorize('update', 'rack')]
    public static function archive(Rack $rack): OkData
    {
        return new OkData('archived');
    }
}

/** A class-level check must not blanket a later, destructive method. */
#[Page('/board/{rack}', view: 'board'), Authorize('view', 'rack')]
final class BoardPage
{
    public function __invoke(Rack $rack): OkData
    {
        return new OkData($rack->name);
    }

    #[Action]
    public function nuke(Rack $rack): OkData
    {
        return new OkData('nuked');
    }
}

/** A class-level opt-out must not exempt a later action either. */
#[Page('/status/{rack}', view: 'status'), NoAuthorization(reason: 'public status board')]
final class StatusBoardPage
{
    public function __invoke(Rack $rack): OkData
    {
        return new OkData($rack->name);
    }

    #[Action]
    public function delete(Rack $rack): OkData
    {
        return new OkData('deleted');
    }
}

/** An #[Authorize] with no subject: a 500 on the first request. */
#[Page('/create', view: 'create'), Authorize('create')]
final class SubjectlessPage
{
    public function __invoke(): OkData
    {
        return new OkData('x');
    }
}

/** A check naming a parameter that is not model-typed. */
#[Page('/raw/{rack}', view: 'rawcheck'), Authorize('view', 'rack')]
final class RawCheckPage
{
    public function __invoke(string $rack): OkData
    {
        return new OkData($rack);
    }
}

/** A base-class-typed and a union-typed model input. */
#[Page('/wide/{thing}', view: 'wide')]
final class WideTypedPage
{
    public function __invoke(\Tinvar\Orm\Model $thing, Rack|Site $other): OkData
    {
        return new OkData('x');
    }
}

final readonly class InnerIdData extends Data
{
    public function __construct(public string $scriptId) {}
}

final readonly class OuterData extends Data
{
    public function __construct(public InnerIdData $inner) {}
}

final readonly class PluralIdsRequest extends FormRequest
{
    /** @param list<string> $scriptIds */
    public function __construct(public array $scriptIds) {}
}

/** A bare id naming a tenant-scoped model. */
final readonly class BadIdRequest extends FormRequest
{
    public function __construct(
        public string $scriptId,
        public int $position,
    ) {}
}

/** Names Rack, which carries no #[TenantScoped]. */
final readonly class RackIdRequest extends FormRequest
{
    public function __construct(
        public string $rackId,
    ) {}
}

final readonly class GoodIdRequest extends FormRequest
{
    public function __construct(
        public Script $script,
        public int $position,
    ) {}
}
