<?php

declare(strict_types=1);

/**
 * Entry points of every kind that take a bare id naming Script, which is
 * #[TenantScoped], for tenant-scoped-ids-must-be-typed at registration.
 */

namespace Tinvar\Tests\Auth\Fixtures\Registration;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Data\Data;
use Tinvar\Http\Response;
use Tinvar\Tests\Auth\Fixtures\BadIdRequest;
use Tinvar\Tests\Orm\Fixtures\Rack;

final readonly class CountData extends Data
{
    public function __construct(public int $count) {}
}

#[Query(name: 'registration.bare'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final class BareIdQuery
{
    public function __invoke(string $scriptId): CountData
    {
        return new CountData(0);
    }
}

/** Takes Rack, so Rack's namespace is searched for what scriptId names. */
#[Query(name: 'registration.beside'), Authorize('view', 'rack')]
final class BareIdBesideAModel
{
    public function __invoke(Rack $rack, string $scriptId): CountData
    {
        return new CountData(0);
    }
}

#[Query(name: 'registration.typed'), Authorize('view', 'rack')]
final class TypedQuery
{
    public function __invoke(Rack $rack, int $position): CountData
    {
        return new CountData($position);
    }
}

#[Page('/registration/{scriptId}', view: 'registration/show', name: 'registration.show'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final class BareIdPage
{
    public function __invoke(string $scriptId): CountData
    {
        return new CountData(0);
    }
}

final class BareIdActions
{
    #[Action, NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
    public function move(BadIdRequest $request): ActionResult
    {
        return ActionResult::ok();
    }
}

final class BareIdEndpoints
{
    #[Get('/api/registration/{scriptId}', name: 'registration.api'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
    public function show(string $scriptId): Response
    {
        return Response::make(200, $scriptId);
    }
}

#[Channel('registration.{scriptId}', message: CountData::class), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final readonly class BareIdChannel
{
    public function __construct(public string $scriptId) {}
}

#[Export(CountData::class, name: 'registration.export'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final readonly class BareIdExport
{
    /** @return list<CountData> */
    public function __invoke(string $scriptId): array
    {
        return [];
    }
}
