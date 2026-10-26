<?php

declare(strict_types=1);

/**
 * The runner resolved every model-typed form field before validating, and
 * answered one the caller had not sent yet with a 404. Precognition's
 * validate('email') on a form whose site select was still empty failed with
 * an HTTP error, contradicting Validator::errorsFor, and a full submit
 * without a site was a 404 rather than "site is required.".
 *
 * An id that WAS sent and names no row in scope is still a 404, as the spec
 * says: "A missing or foreign ID is a 404."
 */

namespace Tinvar\Tests\Actions\UnsentNestedModel;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final class AdminArea {}

final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $admin = true) {}

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return $check->ability !== 'admin' || $this->admin;
    }
}

final class Ran
{
    public static int $bodies = 0;
}

final readonly class InviteRequest extends FormRequest
{
    public function __construct(
        #[Email]
        public string $email,
        public Site $site,
    ) {}
}

final class InviteActions
{
    #[Action, Authorize('invite', 'site')]
    public function invite(InviteRequest $request): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('sent');
    }
}

#[Authorize('admin', AdminArea::class)]
final class AdminInviteActions
{
    #[Action, Authorize('invite', 'site')]
    public function invite(InviteRequest $request): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('sent');
    }
}

/** @return array{0: ActionRunner, 1: Fixture, 2: RecordingGate} */
function runner(bool $admin = true): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(InviteActions::class);
    $registry->register(AdminInviteActions::class);
    $gate = new RecordingGate($admin);

    return [new ActionRunner($registry, $orm, $gate, new Validator($orm)), $fixture, $gate];
}

function errorsOf(\Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors;
    }

    throw new \LogicException('expected a 422');
}

beforeEach(function () {
    Ran::$bodies = 0;
});

it("validates one field while the form's site select is still empty", function () {
    [$runner, , $gate] = runner();

    expect($runner->validateField('InviteActions.invite', ['email' => 'nope'], 'email'))
        ->toBe(['email must be a valid email address.'])
        ->and($runner->validateField('InviteActions.invite', ['email' => 'a@b.co'], 'email'))->toBe([])
        ->and($runner->validateField('InviteActions.invite', ['email' => 'a@b.co'], 'site'))
        ->toBe(['site is required.'])
        // No site, nothing to decide about it.
        ->and($gate->asked)->toBe([]);
});

it('answers a submit without the site with a 422, every error at once, and runs no body', function () {
    [$runner] = runner();

    expect(errorsOf(fn () => $runner->run('InviteActions.invite', ['email' => 'nope'])))
        ->toBe([
            'email' => ['email must be a valid email address.'],
            'site' => ['site is required.'],
        ])
        ->and(errorsOf(fn () => $runner->run('InviteActions.invite', ['email' => 'a@b.co', 'site' => ''], true)))
        ->toBe(['site' => ['site is required.']])
        ->and(Ran::$bodies)->toBe(0);
});

it('still 404s on a site id that was sent and names no row', function () {
    [$runner] = runner();
    $bogus = '0192f3a1-0000-7000-8000-000000000000';

    expect(fn () => $runner->run('InviteActions.invite', ['email' => 'a@b.co', 'site' => $bogus]))
        ->toThrow(NotFoundException::class)
        ->and(fn () => $runner->validateField('InviteActions.invite', ['email' => 'a@b.co', 'site' => $bogus], 'email'))
        ->toThrow(NotFoundException::class);
});

it('still runs every check that has a subject', function () {
    [$runner, , $gate] = runner(admin: false);

    expect(fn () => $runner->validateField('AdminInviteActions.invite', ['email' => 'a@b.co'], 'email'))
        ->toThrow(ForbiddenException::class)
        ->and(fn () => $runner->run('AdminInviteActions.invite', ['email' => 'a@b.co']))
        ->toThrow(ForbiddenException::class)
        ->and($gate->asked)->toBe(['admin', 'admin']);
});

it('checks the site once it is sent', function () {
    [$runner, $fixture, $gate] = runner();

    expect($runner->run('InviteActions.invite', ['email' => 'a@b.co', 'site' => $fixture->ids['sites'][0]])->value)
        ->toBe('sent')
        ->and($gate->asked)->toBe(['invite']);
});
