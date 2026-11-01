<?php

declare(strict_types=1);

/**
 * The prefixes uploads keep their own bytes under -- a direct upload's
 * (tinvar-incoming) and a resumable one's (tinvar-partial) -- are swept by
 * pruning on their own clocks, so no #[Uploaded] parameter may keep files
 * there: a kept file would be removed from under its record.
 */

namespace Tinvar\Tests\Uploads\Reserved;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Uploaded;
use Tinvar\Tests\Uploads\Fixtures\Profile;
use Tinvar\Uploads\Upload;

require_once __DIR__ . '/Fixtures/app.php';

final class IntoIncoming
{
    #[Action(name: 'reserved.incoming'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'tinvar-incoming', types: ['image/png'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class IntoPartial
{
    #[Action(name: 'reserved.partial'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'tinvar-partial/photos', types: ['image/png'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

it('refuses an upload parameter that would keep its files under a prefix pruning sweeps', function (string $class) {
    expect(fn () => (new ActionRegistry())->register($class))->toThrow(ActionException::class);
})->with([
    'tinvar-incoming' => [IntoIncoming::class],
    'under tinvar-partial' => [IntoPartial::class],
]);
