<?php

declare(strict_types=1);

/**
 * The mail providers' webhooks through the Kernel, which mounts them at
 * /_mail/events/*: a provider's server POSTs with its signature and no
 * session, cookie or CSRF token, and opens no session by calling.
 */

namespace Tinvar\Tests\Mail\MailEventsKernel;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Mail\ArraySuppressions;
use Tinvar\Mail\MailEventsEndpoint;
use Tinvar\Mail\Providers\ResendWebhook;
use Tinvar\Mail\Providers\SvixSignature;
use Tinvar\Mail\SuppressionReason;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

const KEY = 'mail-events-kernel-test-key-of-32-bytes!';
const WEBHOOK_KEY = 'resend-webhook-secret-32-bytes!!';

/** @return array{Kernel, ArraySuppressions} */
function kernel(): array
{
    $suppressions = new ArraySuppressions();

    return [new Kernel(
        orm: new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        // Every POST anywhere else must carry its session's CSRF token.
        csrf: new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (Request $r): string => $r->cookie('session') ?? ''),
        mailEvents: new MailEventsEndpoint([new ResendWebhook('whsec_' . base64_encode(WEBHOOK_KEY))], new ArrayNonceStore(), $suppressions),
    ), $suppressions];
}

function delivery(string $body, string $signature): Request
{
    $now = time();

    return new Request('POST', '/_mail/events/resend', [
        'content-type' => 'application/json',
        'svix-id' => 'msg_kernel_1',
        'svix-timestamp' => (string) $now,
        'svix-signature' => 'v1,' . $signature,
    ]);
}

// Through the Kernel, which mounts the endpoint: a test of modules wired
// together, run in the whole suite only (bin/module-alone, ADR 0114).
describe('the endpoint, mounted', function () {
    it('takes a signed delivery with no session or CSRF token, and opens no session for it', function () {
        [$kernel, $suppressions] = kernel();
        $body = json_encode(['type' => 'email.complained', 'created_at' => gmdate('c'), 'data' => ['email_id' => 'e-1', 'to' => ['ada@example.test']]], JSON_THROW_ON_ERROR);
        $request = delivery($body, SvixSignature::sign(WEBHOOK_KEY, 'msg_kernel_1', time(), $body));

        $response = $kernel->handle($request, static fn (): Response => Response::make(500), $body);

        expect($response->status)->toBe(200)
            ->and($response->cookies())->toBe([])
            ->and($kernel->opensNoSession($request))->toBeTrue()
            ->and($suppressions->held['ada@example.test']->reason)->toBe(SuppressionReason::Complaint);
    });

    it('refuses a delivery whose signature does not verify, and a GET', function () {
        [$kernel, $suppressions] = kernel();
        $body = json_encode(['type' => 'email.complained', 'data' => ['email_id' => 'e-1', 'to' => ['ada@example.test']]], JSON_THROW_ON_ERROR);
        $respond = static fn (): Response => Response::make(500);

        expect($kernel->handle(delivery($body, base64_encode(str_repeat('x', 32))), $respond, $body)->status)->toBe(401)
            ->and($kernel->handle(new Request('GET', '/_mail/events/resend'), $respond)->status)->toBe(405)
            ->and($kernel->handle(new Request('POST', '/_mail/events/brevo'), $respond, '{}')->status)->toBe(404)
            ->and($suppressions->held)->toBe([]);
    });
})->group('wired');
