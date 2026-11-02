<?php

declare(strict_types=1);

/**
 * Personal access tokens (ADR 0151): issued by a signed-in user for the
 * scopes the application declares, bound to their user and epoch, tenant,
 * panel and host, listed and revoked by them, and checked at every use.
 */

namespace Tinvar\Tests\Api\ApiTokens;

use Carbon\CarbonImmutable;
use Tinvar\Api\ApiToken;
use Tinvar\Api\ApiTokenSecret;
use Tinvar\Api\ApiTokenStatus;
use Tinvar\Api\ApiTokens;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\User;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

function issued(Harness $h, string $user = 'ada', array $scopes = ['racks:read'], ?CarbonImmutable $expiresAt = null): string
{
    return $h->as($user, fn () => $h->apiTokens->issue('Nightly export', $scopes, $expiresAt))->plaintext;
}

it('issues a token for the signed-in user, keeps only its digest, and signs them in with it', function () {
    $h = new Harness();
    $issued = $h->orm->actAs('acme', fn () => $h->as('ada', fn () => $h->apiTokens->issue('  Nightly export ', ['racks:write', 'racks:read', 'racks:read'])));
    [$id, $secret] = ApiTokenSecret::parse($issued->plaintext) ?? throw new \LogicException('not a token');
    [$kept, $digest] = $h->tokenStore->find($id) ?? throw new \LogicException('not kept');

    expect($kept)->toEqual($issued->token)
        ->and($kept->name)->toBe('Nightly export')
        ->and($kept->user)->toBe('ada')
        ->and($kept->epoch)->toBe('e1')
        ->and($kept->tenant)->toBe('acme')
        ->and($kept->panel)->toBe('')
        ->and($kept->scopes)->toBe(['racks:read', 'racks:write'])
        ->and($digest)->toBe(hash('sha256', $secret))
        ->and(serialize($h->tokenStore->tokens))->not->toContain($secret)
        ->and(print_r($issued, true))->not->toContain($secret);

    $presented = $h->apiTokens->authenticate($issued->plaintext);
    expect($presented?->user->authIdentifier())->toBe('ada')
        ->and($presented?->epoch)->toBe('e1')
        ->and($presented?->token->id)->toBe($id);
});

it('offers the scopes the application declares in the panel in force, and refuses any other', function () {
    $h = new Harness();

    expect($h->apiTokens->grantable())->toBe(['racks:read', 'racks:write'])
        ->and($h->panels->within('admin', fn () => $h->apiTokens->grantable()))->toBe(['racks:audit'])
        ->and(fn () => issued($h, scopes: ['racks:audit']))->toThrow(\InvalidArgumentException::class, 'No API here requires racks:audit. Choose from racks:read, racks:write.')
        ->and(fn () => issued($h, scopes: ['orders:read']))->toThrow(\InvalidArgumentException::class, 'No API here requires orders:read')
        ->and(fn () => issued($h, scopes: []))->toThrow(\InvalidArgumentException::class, 'at least one scope')
        ->and(fn () => issued($h, scopes: ['Racks Read']))->toThrow(\InvalidArgumentException::class, 'is not a scope');
});

it('refuses a name that will not do, and an expiry that has passed', function () {
    $h = new Harness();
    $now = CarbonImmutable::createFromTimestampUTC($h->now);

    expect(fn () => $h->as('ada', fn () => $h->apiTokens->issue('   ', ['racks:read'])))->toThrow(\InvalidArgumentException::class, 'Name the token')
        ->and(fn () => $h->as('ada', fn () => $h->apiTokens->issue(str_repeat('x', ApiToken::MAX_NAME_LENGTH + 1), ['racks:read'])))->toThrow(\InvalidArgumentException::class, 'Name the token')
        ->and(fn () => $h->as('ada', fn () => $h->apiTokens->issue("bell\x07", ['racks:read'])))->toThrow(\InvalidArgumentException::class, 'Name the token')
        ->and(fn () => issued($h, expiresAt: $now))->toThrow(\InvalidArgumentException::class, 'in the future');
});

it('issues none with nobody signed in, across tenants, or for a caller a token signed in', function () {
    $h = new Harness();

    expect(fn () => $h->apiTokens->issue('x', ['racks:read']))->toThrow(\LogicException::class, 'nobody is')
        ->and(fn () => $h->orm->acrossTenants(fn () => issued($h)))->toThrow(\LogicException::class, 'acrossTenants')
        ->and(function () use ($h): void {
            $h->users->epochs['ada'] = 'e1';
            $h->auth->login(new User('ada'), 'e1', apiToken: 'a-token');
            try {
                $h->apiTokens->issue('x', ['racks:read']);
            } finally {
                $h->auth->reset();
            }
        })->toThrow(\LogicException::class, 'cannot issue another');
});

it('holds a user to as many active tokens as the application allows', function () {
    $h = new Harness();
    foreach (range(1, 3) as $_) {
        issued($h);
    }

    expect(fn () => issued($h))->toThrow(\InvalidArgumentException::class, 'You hold 3 tokens, the most you may');

    $h->as('ada', fn () => $h->apiTokens->revoke($h->as('ada', fn () => $h->apiTokens->mine())[0]->id));
    expect(issued($h))->toStartWith(ApiTokenSecret::PREFIX);
});

it('refuses a token that is not one, unknown, or whose secret does not match', function () {
    $h = new Harness();
    $token = issued($h);
    [$id] = ApiTokenSecret::parse($token) ?? throw new \LogicException('not a token');
    $flipped = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

    expect($h->apiTokens->authenticate('not a token'))->toBeNull()
        ->and($h->apiTokens->authenticate($flipped))->toBeNull()
        ->and($h->apiTokens->authenticate('tvr_' . str_repeat('0', 32) . substr($token, 36)))->toBeNull()
        ->and($h->apiTokens->authenticate(strtoupper($token)))->toBeNull()
        ->and($h->apiTokens->authenticate($token)?->token->id)->toBe($id);
});

it('ends a token revoked, expired, or whose user is gone or on another epoch, and says why in the list', function () {
    $h = new Harness();
    $now = CarbonImmutable::createFromTimestampUTC($h->now);
    $revoked = issued($h);
    $h->now += 1;
    $expiring = issued($h, expiresAt: $now->addHour());
    $h->now += 1;
    $kept = issued($h);
    $status = fn (): array => $h->as('ada', fn () => array_map(fn (ApiToken $t): string => $h->apiTokens->statusOf($t)->value, $h->apiTokens->mine()));

    $h->as('ada', fn () => $h->apiTokens->revoke((string) ApiTokenSecret::parse($revoked)[0]));
    expect($h->apiTokens->authenticate($revoked))->toBeNull()
        ->and($h->apiTokens->authenticate($expiring))->not->toBeNull()
        ->and($status())->toBe(['active', 'active', 'revoked']);

    $h->now += 3598;
    expect($h->apiTokens->authenticate($expiring))->toBeNull()
        ->and($h->apiTokens->authenticate($kept))->not->toBeNull()
        ->and($status())->toBe(['active', 'expired', 'revoked']);

    // Signing out everywhere moves the epoch: every token ends with it.
    $h->users->epochs['ada'] = 'e2';
    expect($h->apiTokens->authenticate($kept))->toBeNull()
        ->and($h->as('ada', fn () => array_map(fn (ApiToken $t): ApiTokenStatus => $h->apiTokens->statusOf($t), $h->apiTokens->mine()), 'e2'))
        ->toBe([ApiTokenStatus::SignedOut, ApiTokenStatus::SignedOut, ApiTokenStatus::Revoked]);

    unset($h->users->epochs['ada']);
    expect($h->apiTokens->authenticate($kept))->toBeNull();
});

it('lists and revokes a user\'s own tokens in the tenant in force, and nobody else\'s', function () {
    $h = new Harness();
    $ada = $h->orm->actAs('acme', fn () => issued($h, 'ada'));
    $bob = $h->orm->actAs('acme', fn () => issued($h, 'bob'));
    $adaId = (string) ApiTokenSecret::parse($ada)[0];

    expect($h->orm->actAs('acme', fn () => $h->as('bob', fn () => array_map(fn (ApiToken $t): string => $t->id, $h->apiTokens->mine()))))->toBe([(string) ApiTokenSecret::parse($bob)[0]])
        ->and($h->orm->actAs('globex', fn () => $h->as('ada', fn () => $h->apiTokens->mine())))->toBe([])
        ->and($h->orm->actAs('acme', fn () => $h->as('bob', fn () => $h->apiTokens->revoke($adaId))))->toBeFalse()
        ->and($h->orm->actAs('globex', fn () => $h->as('ada', fn () => $h->apiTokens->revoke($adaId))))->toBeFalse()
        ->and($h->apiTokens->authenticate($ada))->not->toBeNull()
        ->and($h->orm->actAs('acme', fn () => $h->as('ada', fn () => $h->apiTokens->revoke($adaId))))->toBeTrue()
        ->and($h->orm->actAs('acme', fn () => $h->as('ada', fn () => $h->apiTokens->revoke($adaId))))->toBeFalse()
        ->and($h->apiTokens->authenticate($ada))->toBeNull();
});

it('records a token\'s use at most once a minute', function () {
    $h = new Harness();
    $token = issued($h);
    $id = (string) ApiTokenSecret::parse($token)[0];
    $lastUsed = fn (): ?int => $h->tokenStore->find($id)[0]->lastUsedAt?->getTimestamp();

    expect($lastUsed())->toBeNull();
    $h->apiTokens->authenticate($token);
    expect($lastUsed())->toBe($h->now);

    $first = $h->now;
    $h->now += ApiTokens::USE_RECORDED_EVERY - 1;
    $h->apiTokens->authenticate($token);
    expect($lastUsed())->toBe($first);

    $h->now += 1;
    $h->apiTokens->authenticate($token);
    expect($lastUsed())->toBe($h->now);
});

it('binds a token to the host it was issued on, where the application routes by host', function () {
    $h = new Harness(hosts: true);
    $onShop = $h->on('shop.test', fn () => issued($h));

    expect($h->tokenStore->find((string) ApiTokenSecret::parse($onShop)[0])[0]->host)->toBe('shop.test')
        ->and($h->on('shop.test', fn () => $h->apiTokens->authenticate($onShop)))->not->toBeNull()
        ->and($h->on('ops.shop.test', fn () => $h->apiTokens->authenticate($onShop)))->toBeNull();

    // Issued with no request in hand, naming no host: good nowhere over HTTP.
    $nowhere = issued($h);
    expect($h->on('shop.test', fn () => $h->apiTokens->authenticate($nowhere)))->toBeNull()
        ->and($h->as('bob', fn () => $h->apiTokens->issue('Named', ['racks:read'], host: 'ops.shop.test'))->token->host)->toBe('ops.shop.test')
        ->and(fn () => $h->as('bob', fn () => $h->apiTokens->issue('Named', ['racks:read'], host: 'evil.test')))->toThrow(\InvalidArgumentException::class, 'not a host this application serves');
});
