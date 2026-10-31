<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\ContentSecurityPolicyRules;

use Tinvar\Http\ContentSecurityPolicyRules;

/**
 * `tinvar check` fails a document policy that says nothing about
 * who may frame a page.
 */
it('refuses a policy with no frame-ancestors', function () {
    expect(ContentSecurityPolicyRules::check("script-src 'nonce-a' 'strict-dynamic'; object-src 'none'; base-uri 'none'"))
        ->toHaveCount(1)
        ->and(ContentSecurityPolicyRules::check("script-src 'nonce-a' 'strict-dynamic'; object-src 'none'; base-uri 'none'")[0])->toContain('frame-ancestors');
});

it('refuses frame-ancestors with no source', function () {
    expect(ContentSecurityPolicyRules::check("script-src 'self'; frame-ancestors"))->toHaveCount(1);
});

it('takes whichever ancestors the application names', function (string $policy) {
    expect(ContentSecurityPolicyRules::check($policy))->toBe([]);
})->with([
    "script-src 'self'; frame-ancestors 'none'",
    "script-src 'self'; frame-ancestors 'self'",
    "script-src 'self';  FRAME-ANCESTORS https://embed.example ; base-uri 'none'",
]);

it('holds the shop\'s document policy to it', function () {
    expect(ContentSecurityPolicyRules::check(\Shop\Kernel::contentSecurityPolicy('bm9uY2U=')))->toBe([])
        ->and(ContentSecurityPolicyRules::check(\Shop\Kernel::contentSecurityPolicy('bm9uY2U=', dev: true, reports: true)))->toBe([]);
});
