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
use Tinvar\Attributes\Post;
use Tinvar\Auth\Gate;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Endpoints\EndpointRunner;
use Tinvar\Http\Request;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Exists;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationContext;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

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

/**
 * A field judged against the database, beside a model field whose check
 * cannot be asked until it is sent.
 */
final readonly class ClaimRequest extends FormRequest
{
    public function __construct(
        #[Unique(Site::class, 'code')]
        public string $code,
        #[Exists(Rack::class, 'name')]
        public string $rack,
        public Site $site,
    ) {}
}

final class ClaimActions
{
    #[Action, Authorize('claim', 'site')]
    public function claim(ClaimRequest $request): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('claimed');
    }
}

final class ClaimEndpoints
{
    #[Post('/api/claims', name: 'claims.create'), Authorize('claim', 'site')]
    public function create(ClaimRequest $request): void
    {
        Ran::$bodies++;
    }
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
    $registry->register(ClaimActions::class);
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

    // Nothing but "is required" until the site is sent: its check has not
    // been asked, so no other field is judged yet.
    expect($runner->validateField('InviteActions.invite', ['email' => 'nope'], 'email'))->toBe([])
        ->and($runner->validateField('InviteActions.invite', ['email' => 'a@b.co'], 'email'))->toBe([])
        ->and($runner->validateField('InviteActions.invite', ['email' => 'a@b.co'], 'site'))
        ->toBe(['site is required.'])
        // No site, nothing to decide about it.
        ->and($gate->asked)->toBe([]);
});

it('answers a submit without the site with a 422 that says only that, and runs no body', function () {
    [$runner] = runner();

    expect(errorsOf(fn () => $runner->run('InviteActions.invite', ['email' => 'nope'])))
        ->toBe(['site' => ['site is required.']])
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

/**
 * The check on the site was set aside because the site was not sent, so
 * nothing the database says about the other fields may answer yet: Unique
 * and Exists ran, and their answers came back, for a caller no check had
 * been asked about -- an existence oracle, through Precognition-Validate-Only
 * too.
 */
describe('a database rule beside a model field left out', function () {
    it('answers an action alike for a taken value and a free one, and says only "is required"', function () {
        [$runner, $fixture, $gate] = runner(admin: false);

        $taken = ['code' => 'LAX0', 'rack' => 'R1'];
        $free = ['code' => 'NOPE', 'rack' => 'no-such-rack'];

        expect(errorsOf(fn () => $runner->run('ClaimActions.claim', $taken)))->toBe(['site' => ['site is required.']])
            ->and(errorsOf(fn () => $runner->run('ClaimActions.claim', $free)))->toBe(['site' => ['site is required.']])
            ->and(errorsOf(fn () => $runner->run('ClaimActions.claim', $taken, true)))->toBe(['site' => ['site is required.']])
            ->and(errorsOf(fn () => $runner->run('ClaimActions.claim', $free, true)))->toBe(['site' => ['site is required.']])
            ->and($runner->validateField('ClaimActions.claim', $taken, 'code'))->toBe([])
            ->and($runner->validateField('ClaimActions.claim', $free, 'code'))->toBe([])
            ->and($runner->validateField('ClaimActions.claim', $free, 'rack'))->toBe([])
            ->and($runner->validateField('ClaimActions.claim', $taken, 'site'))->toBe(['site is required.'])
            ->and($gate->asked)->toBe([])
            ->and(Ran::$bodies)->toBe(0);
    });

    it('judges against the database once the site is sent and its check asked', function () {
        [$runner, $fixture, $gate] = runner();
        $site = $fixture->ids['sites'][0];

        expect($runner->validateField('ClaimActions.claim', ['code' => 'LAX0', 'site' => $site], 'code'))
            ->not->toBe([])
            ->and($runner->validateField('ClaimActions.claim', ['rack' => 'no-such-rack', 'site' => $site], 'rack'))
            ->not->toBe([])
            ->and($gate->asked)->toBe(['claim', 'claim']);
    });

    it('answers an endpoint alike for a taken value and a free one, and says only "is required"', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new EndpointRegistry();
        $registry->register(ClaimEndpoints::class);
        $endpoint = $registry->get('claims.create');
        $gate = new RecordingGate();
        $runner = new EndpointRunner(static fn (string $c): object => new $c(), $orm, $gate, new Validator($orm));

        $taken = ['code' => 'LAX0', 'rack' => 'R1'];
        $free = ['code' => 'NOPE', 'rack' => 'no-such-rack'];
        $required = ['site' => ['site is required.']];

        expect($runner->precognition($endpoint, [], $taken, null))->toBe($required)
            ->and($runner->precognition($endpoint, [], $free, null))->toBe($required)
            ->and($runner->precognition($endpoint, [], $taken, ['code', 'rack']))->toBe([])
            ->and($runner->precognition($endpoint, [], $free, ['code', 'rack']))->toBe([])
            ->and($runner->precognition($endpoint, [], $free, ['site']))->toBe($required)
            ->and(errorsOf(fn () => $runner->run($endpoint, [], $taken, Request::post('/api/claims'))))->toBe($required)
            ->and(errorsOf(fn () => $runner->run($endpoint, [], $free, Request::post('/api/claims'))))->toBe($required)
            ->and($gate->asked)->toBe([])
            ->and(Ran::$bodies)->toBe(0);

        $site = $fixture->ids['sites'][0];
        expect($runner->precognition($endpoint, [], ['code' => 'LAX0', 'site' => $site], ['code']))->not->toBe([]);
    });

    it('stands Exists and Unique down in a context that may not look', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $looking = new ValidationContext([], $orm);
        $blind = new ValidationContext([], $orm, lookups: false);

        expect((new Unique(Site::class, 'code'))->check('LAX0', 'code', $looking))->not->toBeNull()
            ->and((new Unique(Site::class, 'code'))->check('LAX0', 'code', $blind))->toBeNull()
            ->and((new Exists(Rack::class, 'name'))->check('no-such-rack', 'rack', $looking))->not->toBeNull()
            ->and((new Exists(Rack::class, 'name'))->check('no-such-rack', 'rack', $blind))->toBeNull();
    });
});
