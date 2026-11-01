<?php

declare(strict_types=1);

/**
 * Base64url as WebAuthn writes it: no padding, and one spelling per value,
 * so a challenge or a credential id cannot be written two ways.
 */

namespace Tinvar\Tests\Accounts\Passkeys\Base64Url;

use Tinvar\Accounts\Passkeys\Base64Url;

it('round-trips every length of bytes without padding', function () {
    for ($length = 0; $length < 40; $length++) {
        $bytes = $length === 0 ? '' : random_bytes($length);
        $text = Base64Url::encode($bytes);

        expect($text)->not->toContain('=')->not->toContain('+')->not->toContain('/')
            ->and(Base64Url::decode($text))->toBe($bytes);
    }
});

it('encodes the url-safe alphabet', function () {
    expect(Base64Url::encode("\xfb\xff\xbf"))->toBe('-_-_');
});

it('refuses padding, the standard alphabet, stray characters and impossible lengths', function (string $text) {
    expect(Base64Url::decode($text))->toBeNull();
})->with([
    'padding' => ['YQ=='],
    'plus' => ['+_-_'],
    'slash' => ['/_-_'],
    'space' => ['YW Jj'],
    'newline' => ["YWJj\n"],
    'one character past a group' => ['YWJjZ'],
]);

it('refuses a second spelling of the same bytes', function () {
    // 'YQ' is "a"; 'YR' decodes to the same byte with a spare bit set.
    expect(Base64Url::decode('YQ'))->toBe('a')
        ->and(Base64Url::decode('YR'))->toBeNull();
});
