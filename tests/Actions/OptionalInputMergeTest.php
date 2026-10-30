<?php

declare(strict_types=1);

/**
 * An action parameter and a form-request field with the same name are one
 * input. Their optionality was OR-ed, so a form request shared with a create
 * form made a required parameter optional: its check was skipped when the
 * caller left it out, and the call ended in a TypeError from the body.
 */

namespace Tinvar\Tests\Actions\OptionalInputMerge;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Gate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final class AdminArea {}

final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return true;
    }
}

/** Shared with a create form, which has no rack yet, so its rack is optional. */
final readonly class RackForm extends FormRequest
{
    public function __construct(public string $name, public ?Rack $rack = null) {}
}

/** Requires its rack. */
final readonly class RequiredRackForm extends FormRequest
{
    public function __construct(public string $name, public Rack $rack) {}
}

final class RackActions
{
    /** The rack is required here, whatever the shared form request says. */
    #[Action, Authorize('admin', AdminArea::class), Authorize('rename', 'rack')]
    public function rename(Rack $rack, RackForm $form): ActionResult
    {
        return ActionResult::ok($rack->name);
    }

    /** Optional as a parameter, required by the form request. */
    #[Action, Authorize('admin', AdminArea::class), Authorize('rename', 'rack')]
    public function relabel(RequiredRackForm $form, ?Rack $rack = null): ActionResult
    {
        return ActionResult::ok($form->rack->name);
    }

    /** Optional everywhere it is declared, so optional. */
    #[Action, Authorize('admin', AdminArea::class), Authorize('rename', 'rack')]
    public function draft(RackForm $form, ?Rack $rack = null): ActionResult
    {
        return ActionResult::ok($rack?->name);
    }
}

it('keeps an input required when any declaration of it is', function () {
    $registry = new ActionRegistry();
    $compiled = [];
    foreach ($registry->register(RackActions::class) as $action) {
        $compiled[$action->method] = $action->optionalModels;
    }

    expect($compiled)->toBe([
        'rename' => [],
        'relabel' => [],
        'draft' => ['rack' => true],
    ]);
});

it('refuses a call that leaves out a required parameter a form request calls optional, before the body', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(RackActions::class);
    $gate = new RecordingGate();
    $runner = new ActionRunner($registry, $orm, $gate, new Validator($orm));

    // Refused as an HTTP error rather than a TypeError from inside the body,
    // and the rack's check was not skipped on the way.
    expect(fn () => $runner->run('RackActions.rename', ['name' => 'x']))
        ->toThrow(\Tinvar\Http\HttpException::class);

    expect($runner->run('RackActions.rename', ['name' => 'x', 'rack' => $fixture->ids['racks'][0]])->value)
        ->toBeString()
        ->and($gate->asked)->toBe(['admin', 'rename']);
});
