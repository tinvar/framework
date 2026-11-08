<?php

declare(strict_types=1);

/**
 * Every kind of API token is cut out of what is written down (ADR 0159,
 * 0193): the log's Redactor and the dev toolbar's recording take a token of
 * each ApiTokenKind out of free text, and the toolbar drops the text of a
 * token just issued, which a page's answer carries as `plaintext`. Read
 * from ApiTokenKind::cases(), so a kind added later is held to it too.
 */

namespace Tinvar\Tests\Api\ApiTokenRedaction;

use Tinvar\Api\ApiTokenKind;
use Tinvar\Api\ApiTokenSecret;
use Tinvar\Dev\Recording;
use Tinvar\Observability\Redactor;

it('takes a token of every kind out of a log line and out of the toolbar\'s recording', function (ApiTokenKind $kind) {
    [$token] = ApiTokenSecret::issue(str_repeat('a1', 16), $kind);
    $secret = substr($token, 37);

    $logged = new Redactor()->text("The sync failed for {$token} at noon.");
    $recorded = json_encode(Recording::redact(['message' => "The sync failed for {$token} at noon.", 'plaintext' => $token]), JSON_THROW_ON_ERROR);

    expect($logged)->not->toContain($secret)
        ->and($logged)->toContain('[api-token]')
        ->and($recorded)->not->toContain($secret);
})->with(ApiTokenKind::cases());

it('drops the text of a token just issued wherever a key names it plaintext', function () {
    expect(new Redactor()->isSensitiveKey('plaintext'))->toBeTrue()
        ->and(json_encode(Recording::redact(['issued' => ['plaintext' => 'anything at all']]), JSON_THROW_ON_ERROR))->not->toContain('anything at all');
});
