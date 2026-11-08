<?php

declare(strict_types=1);

/**
 * A context token minted while staff impersonate its user (ADR 0194, "What a
 * review found"): it carries the impersonator and the impersonation, its
 * authHash is over the user's epoch bound to that impersonation, and a call
 * acting as the user with it names the impersonator.
 */

namespace Tinvar\Tests\Calls\Impersonated;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Calls\ContextTokens;
use Tinvar\Http\BuildRegistry;

const KEY = 'impersonated-tokens-test-key-32-bytes!!';

it('carries the impersonator and the impersonation in a context token minted during one, its auth bound to it', function () {
    $tokens = new ContextTokens(KEY, 'tool-grant', 60, 600, new BuildRegistry('b1'));
    $claims = $tokens->open($tokens->issue('', ['notes.read'], 'acme', 'app', 'ada', AuthContext::boundEpoch('4', 'imp-1'), impersonation: 'imp-1', impersonator: 'staff'));

    expect($claims->impersonator)->toBe('staff')
        ->and($claims->impersonation)->toBe('imp-1')
        ->and($claims->authHash)->toBe($tokens->authHash('ada', AuthContext::boundEpoch('4', 'imp-1')))
        ->and($claims->authHash)->not->toBe($tokens->authHash('ada', '4'));

    $plain = $tokens->open($tokens->issue('', ['notes.read'], 'acme', 'app', 'ada', '4'));
    expect($plain->impersonator)->toBeNull()
        ->and($plain->impersonation)->toBeNull()
        ->and(AuthContext::boundEpoch('4', null))->toBe('4');
});

it('names the impersonator inside actingAs() where it is given one, and no impersonation', function () {
    $auth = new AuthContext();
    $ada = new class implements Authenticatable {
        public function authIdentifier(): string
        {
            return 'ada';
        }
    };

    expect($auth->actingAs($ada, static fn (): array => [$auth->impersonator(), $auth->impersonation(), $auth->epoch()], '4', 'staff'))->toBe(['staff', null, '4'])
        ->and($auth->impersonator())->toBeNull();

    $auth->login($ada, '4', impersonator: 'staff', impersonation: 'imp-1');
    expect([$auth->epoch(), $auth->accountEpoch(), $auth->impersonation()])->toBe([AuthContext::boundEpoch('4', 'imp-1'), '4', 'imp-1'])
        ->and($auth->actingAs($ada, static fn (): array => [$auth->epoch(), $auth->accountEpoch(), $auth->impersonation(), $auth->impersonator()], '4'))->toBe(['4', '4', null, 'staff'])
        ->and($auth->epoch())->toBe(AuthContext::boundEpoch('4', 'imp-1'));
    $auth->reset();
    expect([$auth->epoch(), $auth->impersonation(), $auth->impersonator()])->toBe(['', null, null]);
});
