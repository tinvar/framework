<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Surface;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Checks;
use Tinvar\Data\Data;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Validation\FormRequest;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/tenant.php';

final readonly class SurfaceUser implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final readonly class NameData extends Data
{
    public function __construct(public string $name) {}
}

#[Page('/surface/clean/{rack}', view: 'clean'), Authorize('view', 'rack')]
final class CleanPage
{
    public function __invoke(Rack $rack): NameData
    {
        return new NameData($rack->name);
    }
}

#[Page('/surface/scripts', view: 'scripts'), Authorize('viewAny', Script::class)]
final class BareIdPage
{
    public function __invoke(string $scriptId): NameData
    {
        return new NameData($scriptId);
    }
}

/** An #[Action] on a page's own __invoke: one entry point, not two. */
#[Page('/surface/both/{rack}', view: 'both')]
final class PageAndActionPage
{
    #[Action]
    public function __invoke(Rack $rack): NameData
    {
        return new NameData($rack->name);
    }
}

#[Page('/surface/untyped', view: 'untyped'), Authorize('view', 'thing')]
final class UntypedCheckPage
{
    /** @param mixed $thing */
    public function __invoke($thing): NameData
    {
        return new NameData('x');
    }
}

final readonly class LinkedScriptData extends Data
{
    public function __construct(public string $scriptId, public ?LinkedScriptData $next = null) {}
}

final readonly class NotAnIdRequest extends FormRequest
{
    public function __construct(public bool $script, public float $scriptId) {}
}

final readonly class BodyAssignedRequest extends FormRequest
{
    public string $scriptId;

    public function __construct(string $value)
    {
        $this->scriptId = $value;
    }
}

final class Plain {}

final class UserReader
{
    public function __construct(public readonly AuthContext $auth) {}
}

abstract class AbstractClock {}

#[Query(name: 'surface.union', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedUnionQuery
{
    public function __construct(private readonly Plain|UserReader $dependency) {}

    public function __invoke(): NameData
    {
        return new NameData($this->dependency::class);
    }
}

#[Query(name: 'surface.untyped', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedUntypedQuery
{
    public function __construct(private $untyped = null) {}

    public function __invoke(): NameData
    {
        return new NameData(get_debug_type($this->untyped));
    }
}

#[Query(name: 'surface.abstract', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class SharedAbstractQuery
{
    public function __construct(private readonly AbstractClock $clock) {}

    public function __invoke(): NameData
    {
        return new NameData($this->clock::class);
    }
}

/** @return list<string> */
function surfaceRules(BuildRules $rules, array $entryPoints, array $payloads = []): array
{
    return array_map(static fn ($v): string => "{$v->rule} {$v->where}", $rules->check($entryPoints, $payloads));
}

describe('the current user inside a fiber', function () {
    it('restores the outer impersonation when a nested one ends', function () {
        $auth = new AuthContext();
        $auth->login(new SurfaceUser('request-user'));

        $fiber = new \Fiber(fn (): array => $auth->actingAs(
            new SurfaceUser('outer'),
            fn (): array => [
                $auth->actingAs(new SurfaceUser('inner'), fn (): ?string => $auth->id()),
                $auth->id(),
            ],
        ));
        $fiber->start();

        expect($fiber->getReturn())->toBe(['inner', 'outer'])
            ->and($auth->id())->toBe('request-user');
    });
});

describe('Checks::applies', function () {
    // A subjectless check has no optional subject to be absent, so nothing
    // can make it inapplicable.
    it('always applies a check that names no subject', function () {
        expect(Checks::applies(new Authorize('admin'), [], ['admin']))->toBeTrue();
    });
});

describe('PublicAccess', function () {
    it('does not exempt an entry point whose class does not exist', function () {
        expect(PublicAccess::declaredOn('Tinvar\Tests\Auth\Surface\Missing', 'status'))->toBeFalse();
    });
});

describe('build rules', function () {
    it('assert() returns quietly when nothing is violated', function () {
        expect(fn () => (new BuildRules())->assert([CleanPage::class]))->not->toThrow(\RuntimeException::class);
    });

    it('flags a bare id parameter naming a tenant-scoped model', function () {
        expect(surfaceRules(new BuildRules([Script::class]), [BareIdPage::class]))
            ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . BareIdPage::class . '::__invoke($scriptId)']);
    });

    it('checks a page whose __invoke is also an #[Action] once, not twice', function () {
        expect(surfaceRules(new BuildRules(), [PageAndActionPage::class]))
            ->toBe([BuildRules::AUTHORIZE_EVERY_MODEL_INPUT . ' ' . PageAndActionPage::class . '::__invoke($rack)']);
    });

    it('flags a check naming an untyped parameter, which a policy would get raw', function () {
        $violations = (new BuildRules())->check([UntypedCheckPage::class]);

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT)
            ->and($violations[0]->message)->toContain('not model-typed');
    });

    describe('tenant-scoped ids in payloads', function () {
        it('walks a self-referencing payload once, without looping', function () {
            expect(surfaceRules(new BuildRules([Script::class]), [], [LinkedScriptData::class]))
                ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . LinkedScriptData::class . '::$scriptId']);
        });

        it('ignores a field whose type cannot hold an id', function () {
            expect((new BuildRules([Script::class]))->check([], [NotAnIdRequest::class]))->toBe([]);
        });

        // A form request may assign a field in its body rather than promote it.
        it('flags a public property assigned in the constructor body', function () {
            expect(surfaceRules(new BuildRules([Script::class]), [], [BodyAssignedRequest::class]))
                ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . BodyAssignedRequest::class . '::$scriptId']);
        });
    });

    describe('shared queries', function () {
        // One union member reaching the user is enough, and the query's own
        // source never names it, so only the type walk can see it.
        it('flags a union dependency when any member reaches the user', function () {
            $violations = (new BuildRules())->check([SharedUnionQuery::class]);

            expect($violations)->toHaveCount(1)
                ->and($violations[0]->rule)->toBe(BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER)
                ->and($violations[0]->message)->toContain(AuthContext::class);
        });

        it('refuses an untyped dependency rather than assuming it safe', function () {
            $messages = array_map(
                static fn ($v): string => $v->message,
                (new BuildRules())->check([SharedUntypedQuery::class]),
            );

            expect($messages)->toHaveCount(1)
                ->and($messages[0])->toContain('takes an untyped parameter');
        });

        it('refuses an abstract dependency rather than assuming it safe', function () {
            $messages = array_map(
                static fn ($v): string => $v->message,
                (new BuildRules())->check([SharedAbstractQuery::class]),
            );

            expect($messages)->toHaveCount(1)
                ->and($messages[0])->toContain('the abstract class ' . AbstractClock::class);
        });
    });
});
