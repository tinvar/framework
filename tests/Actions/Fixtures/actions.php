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
