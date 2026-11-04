<?php

declare(strict_types=1);

/**
 * The one way a provider's reason is cut down for an exception's message:
 * billing's Stripe client and mail's providers both go through it.
 */

namespace Tinvar\Tests\Observability\SafeReason;

use Tinvar\Observability\Redactor;
use Tinvar\Observability\SafeReason;

it('cuts each secret, then what the scrub cuts, onto one line', function () {
    $said = SafeReason::of("Bad key: sk_test_abc123\nfor account acct_1 (hunter2-secret)", ['hunter2-secret'], static fn (string $t): string => str_replace('sk_test_abc123', Redactor::REDACTED, $t));

    expect($said)->toBe('Bad key: ' . Redactor::REDACTED . ' for account acct_1 (' . Redactor::REDACTED . ')');
});

it('cuts a long reason on a character\'s edge, never inside one', function () {
    $said = SafeReason::of(str_repeat('é', 200), max: 11);

    expect($said)->toBe('ééééé...')
        ->and(mb_check_encoding($said, 'UTF-8'))->toBeTrue();
});

it('hands what is left through the Redactor\'s patterns', function () {
    expect(SafeReason::of('Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789'))->not->toContain('abcdefghijklmnopqrstuvwxyz0123456789');
});
