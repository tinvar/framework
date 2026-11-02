<?php

declare(strict_types=1);

/**
 * The API's calls (ADR 0151): an action or a query marked #[RequiresScope],
 * run for the user an API token signed in -- held to the token's scopes,
 * tenant, panel and host, then run through the action's or query's own
 * resolution, #[Authorize] and validation.
 */

namespace Tinvar\Tests\Api\ApiCalls;

use Tinvar\Api\ApiCalls;
use Tinvar\Api\ApiToken;
use Tinvar\Api\PresentedApiToken;
use Tinvar\Calls\CallResult;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\Ran;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

/** @param list<string> $scopes */
function presented(Harness $h, array $scopes, string $user = 'ada', ?string $host = null): PresentedApiToken
{
    $issue = fn (): string => $h->as($user, fn () => $h->apiTokens->issue('A script', $scopes))->plaintext;
    $token = $host === null ? $issue() : $h->on($host, $issue);

    return ($host === null ? $h->apiTokens->authenticate($token) : $h->on($host, fn () => $h->apiTokens->authenticate($token)))
        ?? throw new \LogicException('The token did not hold.');
}

it('exposes the actions and queries marked #[RequiresScope], with the scopes each requires', function () {
    $h = new Harness();

    expect(array_map(static fn (array $entry): array => $entry[1], $h->apiCalls->operations()))->toBe([
        'racks.rename' => ['racks:write'],
        'racks.audit' => ['racks:audit'],
        'racks.show' => ['racks:read'],
    ]);
});

it('runs a query and an action for the token\'s user, through their own policy and validation', function () {
    $h = new Harness();
    $token = presented($h, ['racks:read', 'racks:write']);

    $shown = $h->apiCalls->call($token, 'racks.show', ['rack' => $h->rack()]);
    $renamed = $h->apiCalls->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'API-1']);
    $invalid = $h->apiCalls->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => '']);

    expect($shown->status)->toBe(CallResult::OK)
        ->and($shown->value)->toMatchArray(['id' => $h->rack()])
        ->and($renamed->status)->toBe(CallResult::OK)
        ->and($h->rackName())->toBe('API-1')
        ->and($invalid->status)->toBe(CallResult::INVALID)
        ->and($invalid->errors)->toHaveKey('name');
});

it('refuses a call the token holds no scope for, and runs nothing', function () {
    $h = new Harness();
    $token = presented($h, ['racks:read']);

    expect($h->apiCalls->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'X'])->reason)->toBe('insufficient_scope')
        ->and(Ran::$bodies)->toBe([]);
});

it('refuses an operation nothing exposes as one that does not exist', function () {
    $h = new Harness();
    $token = presented($h, ['racks:read', 'racks:write']);

    expect($h->apiCalls->call($token, 'racks.scrap', ['rack' => $h->rack()])->reason)->toBe('unknown_operation')
        ->and($h->apiCalls->call($token, 'racks.nothing', [])->reason)->toBe('unknown_operation')
        ->and(Ran::$bodies)->toBe([]);
});

it('holds the user\'s own policy: a scope grants nothing the policy refuses them', function () {
    $h = new Harness();
    $token = presented($h, ['racks:write'], user: 'mallory');

    expect($h->apiCalls->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'Mine'])->status)->toBe(CallResult::FORBIDDEN)
        ->and(Ran::$bodies)->toBe([]);
});

it('runs in the token\'s tenant, and refuses it where another is in force', function () {
    $h = new Harness();
    $token = $h->orm->actAs('acme', fn () => presented($h, ['racks:write']));
    $call = fn (): CallResult => $h->apiCalls->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'T-1']);

    expect($call()->status)->toBe(CallResult::OK)
        ->and(Ran::$tenants)->toBe(['acme'])
        ->and($h->orm->actAs('acme', $call)->status)->toBe(CallResult::OK)
        ->and($h->orm->actAs('globex', $call)->reason)->toBe('tenant')
        ->and($h->orm->acrossTenants($call)->reason)->toBe('tenant')
        ->and(Ran::$tenants)->toBe(['acme', 'acme']);
});

it('reaches a panel\'s operation only with a token issued in it, for a member who still holds the permission', function () {
    $h = new Harness();
    $h->staff->members['ada'] = ['racks.audit'];
    $staff = $h->panels->within('admin', fn () => presented($h, ['racks:audit']));
    $call = fn (): CallResult => $h->apiCalls->call($staff, 'racks.audit', ['rack' => $h->rack()]);

    expect($call()->status)->toBe(CallResult::OK);

    $h->staff->members['ada'] = [];
    expect($call()->status)->toBe(CallResult::FORBIDDEN)
        ->and(Ran::$bodies)->toBe(['audit']);

    // A token issued outside the panel reaches nothing in it, whatever it names.
    $h->staff->members['ada'] = ['racks.audit'];
    $outside = presented($h, ['racks:read']);
    expect($h->apiCalls->call($outside, 'racks.audit', ['rack' => $h->rack()])->reason)->toBe('insufficient_scope');
    // Even one that somehow held the scope: the panel is the token's own.
    $holding = new PresentedApiToken(
        new ApiToken($outside->token->id, 'x', 'ada', 'e1', '', '', '', ['racks:audit'], $outside->token->createdAt),
        $outside->user,
        'e1',
    );
    expect($h->apiCalls->call($holding, 'racks.audit', ['rack' => $h->rack()])->reason)->toBe('panel');
});

it('runs an operation held to a host on that host alone', function () {
    $h = new Harness(hosts: true);
    $onOps = presented($h, ['racks:read'], host: 'ops.shop.test');

    expect($h->on('ops.shop.test', fn () => $h->apiCalls->call($onOps, 'racks.label', ['rack' => $h->rack()]))->status)->toBe(CallResult::OK)
        ->and($h->on('shop.test', fn () => $h->apiCalls->call($onOps, 'racks.label', ['rack' => $h->rack()]))->reason)->toBe('host');
});

it('refuses #[RequiresScope] on an action holder\'s class, which would expose nothing', function () {
    $h = new Harness();
    $h->actions->registerAll([ClassWide::class]);

    expect(fn () => ApiCalls::discover($h->actions, $h->queries, $h->entry))->toThrow(\LogicException::class, 'Name it on each #[Action] method');
});

#[\Tinvar\Attributes\RequiresScope('racks:read')]
final class ClassWide
{
    #[\Tinvar\Attributes\Action(name: 'racks.classWide'), \Tinvar\Attributes\Authorize('view', 'rack')]
    public function show(\Tinvar\Tests\Orm\Fixtures\Rack $rack): \Tinvar\Actions\ActionResult
    {
        return \Tinvar\Actions\ActionResult::ok();
    }
}
