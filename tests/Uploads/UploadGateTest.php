<?php

declare(strict_types=1);

/**
 * An upload action's gates (#[RequiresPlan], ADR 0150) run once its ticket
 * has been held to the request -- the one check an upload has -- and never
 * for a request the ticket refuses: a caller with a ticket bound to another
 * session, user, tenant or panel is answered the ticket's 403, and learns
 * nothing of what the gate would have said.
 */

use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Tests\Uploads\Fixtures\CheckBadge;
use Tinvar\Uploads\UploadEndpoint;

use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\CSRF;
use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE;

require_once __DIR__ . '/Fixtures/app.php';

/** POSTs /_upload/session under $ticket, from the browser session $cookie. */
function beginGatedSession(App $app, string $ticket, string $cookie, array $headers = []): Response
{
    return $app->kernel->handle(
        new Request('POST', UploadEndpoint::SESSION_PATH, [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'x-xsrf-token' => CSRF,
            'x-test-user' => 'u-1',
            'x-test-epoch' => 'e1',
            ...$headers,
        ], cookies: [SESSION_COOKIE => $cookie, VerifyCsrfToken::COOKIE => CSRF]),
        static fn () => Response::make(500),
        json_encode(['ticket' => $ticket, 'size' => 1024, 'name' => 'me.png'], JSON_THROW_ON_ERROR),
    );
}

it('runs the gate for an upload whose ticket holds, and refuses the upload when the gate does', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1), action: 'profiles.gatedAvatar');

    expect($app->upload($ticket, png(), headers: ['x-badge' => 'gold'])->status)->toBe(201)
        ->and(CheckBadge::$asked)->toBe(1)
        ->and($app->upload($ticket, png())->status)->toBe(402)
        ->and(CheckBadge::$asked)->toBe(2);
});

it('never asks the gate for a ticket bound to another session: the refusal is the ticket\'s, not the gate\'s', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1), action: 'profiles.gatedAvatar', session: 'session-b');

    $response = $app->upload($ticket, png(), session: 'session-a');

    expect($response->status)->toBe(403)
        ->and(CheckBadge::$asked)->toBe(0);
});

it('holds a refresh and a resumable session to the ticket before their gate too', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1), action: 'profiles.gatedAvatar', session: 'session-b');

    expect($app->refresh($ticket, session: 'session-a')->status)->toBe(403)
        ->and(beginGatedSession($app, $ticket, 'session-a')->status)->toBe(403)
        ->and(CheckBadge::$asked)->toBe(0);

    // And run once it holds: the session is begun only past the gate.
    $own = $app->ticket($app->profile(P1), action: 'profiles.gatedAvatar');
    expect(beginGatedSession($app, $own, 'session-a')->status)->toBe(402)
        ->and(beginGatedSession($app, $own, 'session-a', ['x-badge' => 'gold'])->status)->toBe(201)
        ->and(CheckBadge::$asked)->toBe(2);
});
