<?php

declare(strict_types=1);

/**
 * A form posted without script carrying its file to the action itself
 * (ADR 0127): multipart, the file under the parameter's own name and the
 * page's ticket beside it in `_upload_tickets[<name>]` -- each file taken
 * inside the action's own context exactly as /_upload takes one, and the
 * action called with its reference.
 */

use Tinvar\Actions\ActionRunner;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Tests\Uploads\Fixtures\ProfileActions;

use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\CSRF;
use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\P2;
use const Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE;

require_once __DIR__ . '/Fixtures/app.php';

/**
 * A browser's own multipart post of a form, with no script: the CSRF token
 * as a hidden field, and the files as PHP received them.
 *
 * @param array<string, mixed> $fields
 * @param array<string, string> $files field => bytes
 */
function postWithoutScript(App $app, string $action, array $fields, array $files, string $user = 'u-1', string $session = 'session-a'): Response
{
    $received = [];
    foreach ($files as $field => $bytes) {
        $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-noscript-');
        file_put_contents($path, $bytes);
        $received[$field] = new UploadedFile($path, "{$field}.bin", 'application/octet-stream', UPLOAD_ERR_OK, strlen($bytes));
    }

    try {
        return $app->kernel->handle(
            new Request(
                'POST',
                "/_action/{$action}",
                [
                    'content-type' => 'multipart/form-data; boundary=----tinvar',
                    'accept' => 'text/html,application/xhtml+xml',
                    'referer' => 'http://localhost/account',
                    'x-test-user' => $user,
                    'x-test-epoch' => 'e1',
                ],
                body: ['_token' => CSRF, ...$fields],
                cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF],
                files: $received,
            ),
            static fn () => Response::make(500, 'No page renders in these tests.'),
        );
    } finally {
        foreach ($received as $file) {
            @unlink($file->path);
        }
    }
}

it('takes the file a form posted without script under the ticket beside it, and calls the action with its reference', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1));

    $response = postWithoutScript($app, 'profiles.avatar', ['profile' => P1, 'avatar' => '', '_upload_tickets' => ['avatar' => $ticket]], ['avatar' => png()]);

    expect($response->status)->toBe(303)
        ->and(ProfileActions::$received?->type)->toBe('image/png')
        ->and($app->store->find((string) ProfileActions::$received?->id)?->claimedAt)->toBe($app->now)
        ->and($app->profile(P1)->avatarKey)->toStartWith('avatars/');
});

it('holds the file to everything /_upload would', function (Closure $post) {
    $app = new App(new MemoryStorage());
    ProfileActions::$received = null;

    $response = $post($app);

    expect($response->status)->toBe(422)
        ->and(ProfileActions::$received)->toBeNull()
        ->and($app->storage instanceof MemoryStorage ? $app->storage->keys() : null)->toBe([]);
})->with([
    'no ticket' => [fn (App $app) => postWithoutScript($app, 'profiles.avatar', ['profile' => P1], ['avatar' => png()])],
    'another user\'s ticket' => [fn (App $app) => postWithoutScript($app, 'profiles.avatar', ['profile' => P1, '_upload_tickets' => ['avatar' => $app->ticket($app->profile(P1), user: 'u-2')]], ['avatar' => png()])],
    'a ticket for another parameter' => [fn (App $app) => postWithoutScript($app, 'profiles.avatar', ['profile' => P1, '_upload_tickets' => ['avatar' => $app->ticket($app->profile(P1), action: 'profiles.document', field: 'document')]], ['avatar' => png()])],
    'a type it does not take' => [fn (App $app) => postWithoutScript($app, 'profiles.avatar', ['profile' => P1, '_upload_tickets' => ['avatar' => $app->ticket($app->profile(P1))]], ['avatar' => pdf()])],
    'too large' => [fn (App $app) => postWithoutScript($app, 'profiles.avatar', ['profile' => P1, '_upload_tickets' => ['avatar' => $app->ticket($app->profile(P1))]], ['avatar' => png() . random_bytes(5000)])],
]);

it('binds the file to the record the ticket names, as /_upload does', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1));

    $response = postWithoutScript($app, 'profiles.avatar', ['profile' => P2, '_upload_tickets' => ['avatar' => $ticket]], ['avatar' => png()]);

    expect($response->status)->toBe(422)
        ->and(ProfileActions::$received)->toBeNull();
});

it('takes no file for a field the action does not have, and keeps no ticket in the input', function () {
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1));
    $reference = (string) Tinvar\Tests\Uploads\Fixtures\json($app->upload($ticket, png()))['upload'];

    // A file under a name the action has no upload by is not taken.
    $response = postWithoutScript($app, 'profiles.avatar', ['profile' => P1, 'avatar' => $reference, '_upload_tickets' => ['stray' => $ticket]], ['stray' => png()]);

    expect($response->status)->toBe(303)
        ->and(ProfileActions::$received?->id)->toBe($reference)
        ->and($app->storage instanceof MemoryStorage ? count($app->storage->keys()) : null)->toBe(1);
});

it('still refuses the upload a form without script left out', function () {
    $app = new App(new MemoryStorage());

    $response = postWithoutScript($app, 'profiles.avatar', ['profile' => P1, 'avatar' => ''], []);

    expect($response->status)->toBe(422)
        ->and(ProfileActions::$received)->toBeNull();
});

it('says on the field what it refused, in the words a form shows', function () {
    $app = new App(new MemoryStorage());
    $request = static fn (string $bytes, array $tickets): Request => new Request(
        'POST',
        '/_action/profiles.avatar',
        ['content-type' => 'multipart/form-data; boundary=----tinvar'],
        cookies: [SESSION_COOKIE => 'session-a'],
        files: ['avatar' => new UploadedFile((string) (static function () use ($bytes): string {
            $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-noscript-');
            file_put_contents($path, $bytes);

            return $path;
        })(), 'a.png', 'image/png', UPLOAD_ERR_OK, strlen($bytes))],
    );
    $ticket = $app->ticket($app->profile(P1));
    $app->auth->login(new Tinvar\Tests\Uploads\Fixtures\User('u-1'), 'e1');
    $errors = static function (Closure $take): array {
        try {
            $take();
        } catch (Tinvar\Validation\ValidationException $e) {
            return $e->errors;
        }

        return [];
    };
    $endpoint = $app->kernel->uploads_;
    assert($endpoint !== null);

    expect($errors(fn () => $endpoint->takeInline($request(png(), []), 'profiles.avatar', [])))->toBe(['avatar' => [ActionRunner::UPLOAD_REFUSED]])
        ->and($errors(fn () => $endpoint->takeInline($request(pdf(), ['avatar' => $ticket]), 'profiles.avatar', ['_upload_tickets' => ['avatar' => $ticket]])))
        ->toBe(['avatar' => ['The file is not of a type this upload takes.']]);

    $taken = $endpoint->takeInline($request(png(), []), 'profiles.avatar', ['_upload_tickets' => ['avatar' => $ticket], 'profile' => P1]);
    expect(array_keys($taken))->toBe(['profile', 'avatar'])
        ->and($app->store->find((string) $taken['avatar'])?->type)->toBe('image/png');
});
