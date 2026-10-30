<?php

declare(strict_types=1);

/**
 * The token a marketing email's link to its reader's preferences carries
 * (ADR 0107): bound to one subscriber, one tenant and its own purpose, and
 * good until it expires -- refused, the same way, for every other reason.
 */

namespace Tinvar\Tests\Mail\PreferenceLinks;

use Tinvar\Mail\PreferenceLinks;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;

const KEY = 'preference-links-test-key-0123456789ab';
const NOW = 1_900_000_000;

function links(int $now = NOW, string $key = KEY): PreferenceLinks
{
    return PreferenceLinks::fromKey($key, static fn (): int => $now);
}

it('names its subscriber and tenant back', function () {
    expect(links()->verify(links()->token('c-1', 'acme')))->toBe(['subscriber' => 'c-1', 'tenant' => 'acme'])
        ->and(links()->verify(links()->token('c-1')))->toBe(['subscriber' => 'c-1', 'tenant' => '']);
});

it('is good for its lifetime, and not a second after', function () {
    $token = links()->token('c-1');

    expect(links(NOW + PreferenceLinks::LIFETIME - 1)->verify($token))->not->toBeNull()
        ->and(links(NOW + PreferenceLinks::LIFETIME)->verify($token))->toBeNull()
        ->and(links(NOW - 120)->verify($token))->toBeNull('issued in the future');
});

it('refuses one made with another key, for another purpose, altered, or not a token', function () {
    $token = links()->token('c-1');
    [$version, $payload, $mac] = explode('.', $token);
    $claims = json_decode((string) Signer::decode($payload), true);
    $altered = $version . '.' . Signer::encode((string) json_encode([...$claims, 'p' => 'c-2'])) . '.' . $mac;
    $unsubscribe = substr((string) parse_url(UnsubscribeLinks::fromKey(KEY, 'https://shop.test', static fn (): int => NOW)->url('newsletter', 'c-1'), PHP_URL_PATH), \strlen(UnsubscribeLinks::PATH) + 1);
    $otherPurpose = SignedToken::encode(new Signer(KEY, PreferenceLinks::PURPOSE), 'route-token', $claims);
    $longer = SignedToken::encode(new Signer(KEY, PreferenceLinks::PURPOSE), PreferenceLinks::PURPOSE, [...$claims, 'e' => NOW + PreferenceLinks::LIFETIME + 1]);

    foreach ([links(key: KEY . '-other')->token('c-1'), $altered, $unsubscribe, $otherPurpose, $longer, 'v1.not.a-token', '', str_repeat('a', 5000)] as $forged) {
        expect(links()->verify($forged))->toBeNull();
    }
});

it('refuses to name a subscriber or a tenant a header or a log could be split on', function (string $subscriber, string $tenant) {
    expect(fn () => links()->token($subscriber, $tenant))->toThrow(\InvalidArgumentException::class);
})->with([
    'no subscriber' => ['', ''],
    'a line break' => ["c-1\r\nx", ''],
    'a NUL in the tenant' => ['c-1', "acme\0"],
]);
