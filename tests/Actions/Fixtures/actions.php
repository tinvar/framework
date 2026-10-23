<?php

declare(strict_types=1);

namespace Tinvar\Tests\Actions\Fixtures;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

final class Trace
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    public static function note(string $event): void
    {
        self::$events[] = $event;
    }
}

final readonly class MoveUnitRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(42)] public int $position,
    ) {
    }
}

final class RackActions
{
    #[Action, Authorize('update', 'rack')]
    public function moveUnit(Rack $rack, MoveUnitRequest $request): ActionResult
    {
        Trace::note('body:moveUnit');

        return ActionResult::revalidateTags("rack:{$rack->primaryKey()}")
            ->withRoutes('racks.show')
            ->flash(['message' => "Moved to {$request->position}"]);
    }

    #[Action, Authorize('update', 'rack'), Authorize('view', 'site')]
    public function transfer(Rack $rack, Site $site): ActionResult
    {
        Trace::note('body:transfer');

        return ActionResult::ok();
    }

    #[Action, NoAuthorization(reason: 'public status ping')]
    public function ping(): ActionResult
    {
        Trace::note('body:ping');

        return ActionResult::ok('pong');
    }

    /**
     * No declared return type, so PHP will not catch this: the runner's own
     * check is what has to.
     */
    #[Action, Authorize('update', 'rack')]
    public function wrongReturn(Rack $rack)
    {
        return 'not a result';
    }
}

/** A model input with no check naming it: refused at registration. */
final class UnguardedActions
{
    #[Action]
    public function touch(Rack $rack): ActionResult
    {
        return ActionResult::ok();
    }
}

/** A parameter type an action cannot bind. */
final class UnbindableActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function at(\DateTimeImmutable $when): ActionResult
    {
        return ActionResult::ok();
    }
}

// --- model-typed fields inside a form request -----------------------------

final readonly class TransferRequest extends FormRequest
{
    public function __construct(
        public Site $destination,
        #[Min(1)] public int $priority = 1,
    ) {}
}

final class NestedModelActions
{
    /** Both the parameter and the field inside the request are named. */
    #[Action, Authorize('update', 'rack'), Authorize('view', 'destination')]
    public function transfer(Rack $rack, TransferRequest $request): ActionResult
    {
        Trace::note('body:nested-transfer');

        return ActionResult::ok($request->destination->code);
    }
}

/** The field inside the request is resolved but never checked. */
final class UnguardedFieldActions
{
    #[Action, Authorize('update', 'rack')]
    public function transfer(Rack $rack, TransferRequest $request): ActionResult
    {
        return ActionResult::ok();
    }
}

// --- review-finding fixtures ---------------------------------------------

final readonly class SiteFieldRequest extends FormRequest
{
    public function __construct(public Site $target) {}
}

/** A form request declared BEFORE a model parameter of the same name. */
final class CollisionFirstActions
{
    #[Action, Authorize('view', 'target')]
    public function go(SiteFieldRequest $request, Rack $target): ActionResult
    {
        return ActionResult::ok();
    }
}

/** The same shapes, reordered. Both must be refused. */
final class CollisionLastActions
{
    #[Action, Authorize('view', 'target')]
    public function go(Rack $target, SiteFieldRequest $request): ActionResult
    {
        return ActionResult::ok();
    }
}

final class VariadicActions
{
    #[Action, Authorize('update', 'racks')]
    public function many(Rack ...$racks): ActionResult
    {
        return ActionResult::ok();
    }
}

final readonly class OptionalDestinationRequest extends FormRequest
{
    public function __construct(
        public ?Site $destination = null,
        public string $note = '',
    ) {}
}

final class OptionalActions
{
    #[Action, Authorize('update', 'rack'), Authorize('view', 'destination')]
    public function move(Rack $rack, OptionalDestinationRequest $request): ActionResult
    {
        return ActionResult::ok($request->destination?->code ?? 'nowhere');
    }

    #[Action, Authorize('update', 'rack')]
    public function rename(Rack $rack, string $name): ActionResult
    {
        return ActionResult::ok($name);
    }
}

final readonly class AlphaRequest extends FormRequest
{
    public function __construct(#[Min(3)] public string $alpha) {}
}

final readonly class BetaRequest extends FormRequest
{
    public function __construct(#[Min(3)] public string $beta) {}
}

final class TwoRequestActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function both(AlphaRequest $a, BetaRequest $b): ActionResult
    {
        return ActionResult::ok($a->alpha . $b->beta);
    }
}

final readonly class RenameSiteRequest extends FormRequest
{
    public function __construct(
        #[\Tinvar\Validation\Rules\Unique(Site::class, 'code', ignoreSubject: true)] public string $code,
    ) {}
}

/** Two model inputs: the Unique rule must exempt the Site, not the Rack. */
final class TwoModelActions
{
    #[Action, Authorize('update', 'rack'), Authorize('update', 'site')]
    public function renameSite(Rack $rack, Site $site, RenameSiteRequest $request): ActionResult
    {
        return ActionResult::ok($request->code);
    }
}

/**
 * A class-level opt-out meant for the ping below. It must not exempt the
 * destructive action beside it.
 */
#[NoAuthorization(reason: 'the ping below needs none')]
final class BlanketExemptActions
{
    #[Action]
    public function ping(): ActionResult
    {
        return ActionResult::ok('pong');
    }

    #[Action]
    public function deleteRack(Rack $rack): ActionResult
    {
        Trace::note('body:deleteRack');

        return ActionResult::ok();
    }
}

/** A read-only class check must not satisfy a destructive method. */
#[Authorize('view', 'rack')]
final class BlanketCheckActions
{
    #[Action]
    public function destroy(Rack $rack): ActionResult
    {
        Trace::note('body:destroy');

        return ActionResult::ok();
    }
}

final class SubjectlessActions
{
    #[Action, Authorize('admin')]
    public function wipe(): ActionResult
    {
        return ActionResult::ok();
    }
}

final class StatefulActions
{
    public ?string $last = null;

    #[Action, Authorize('update', 'rack')]
    public function touch(Rack $rack): ActionResult
    {
        $seen = $this->last;
        $this->last = $rack->name;

        return ActionResult::ok($seen);
    }
}
