<?php

declare(strict_types=1);

/**
 * A file sent straight to the object store (ADR 0126): an intent under the
 * ticket, a URL presigned for its exact size, the browser's PUT, and the
 * completion -- which fetches the file back and keeps it only as /_upload
 * would, sniffed, drawn again and scanned, never as it was sent.
 */

use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Storage\Fixtures\FakeS3;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Uploads\UploadEndpoint;
use Tinvar\Uploads\Uploads;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\CSRF;
use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE;

require_once __DIR__ . '/Fixtures/app.php';
require_once __DIR__ . '/../Storage/Fixtures/s3.php';

/** @return array{App, FakeS3} */
function directApp(?Tinvar\Uploads\FileScanner $scanner = null, ?Tinvar\Uploads\UploadQuota $quota = null): array
{
    $s3 = new FakeS3();
    $app = new App($s3->storage(), scanner: $scanner, quota: $quota);
    $s3->now = $app->now;

    return [$app, $s3];
}

/** @param array<string, mixed> $body */
function postDirect(App $app, string $path, array $body, string $user = 'u-1', string $session = 'session-a'): Response
{
    return $app->kernel->handle(
        new Request('POST', $path, [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'x-xsrf-token' => CSRF,
            'x-test-user' => $user,
            'x-test-epoch' => 'e1',
        ], cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF]),
        static fn () => Response::make(500),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

/** @return array{string, array<string, mixed>} the ticket, and what /_upload/direct answered */
function begun(App $app, int $size): array
{
    $ticket = $app->ticket($app->profile(P1));
    $response = postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $ticket, 'size' => $size, 'name' => 'me.png']);
    expect($response->status)->toBe(201);

    return [$ticket, json($response)];
}

it('takes a file sent straight to the store, and keeps it as it was drawn again, under a key of its own', function () {
    [$app, $s3] = directApp();
    $bytes = png() . 'after the image';
    [$ticket, $intent] = begun($app, strlen($bytes));

    expect($intent['method'])->toBe('PUT')
        ->and($intent['headers'])->toBe(['content-type' => 'application/octet-stream', 'content-disposition' => 'attachment'])
        ->and($s3->browser('PUT', $intent['url'], $bytes, $intent['headers'])->status)->toBe(200);

    $done = postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']]);
    expect($done->status)->toBe(201);
    $reference = (string) json($done)['upload'];
    $kept = $app->store->find($reference);

    expect($kept?->type)->toBe('image/png')
        ->and($kept?->key)->toStartWith('avatars/')
        ->and($kept?->name)->toBe('me.png')
        // The file as sent is gone from the store; what is kept was redrawn.
        ->and(array_keys($s3->objects))->toBe([(string) $kept?->key])
        ->and($s3->objects[(string) $kept?->key])->not->toContain('after the image')
        ->and($app->store->find((string) $intent['intent']))->toBeNull()
        ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(200);
});

it('refuses a file sent as another type than it is, or as a page, and keeps nothing of it', function () {
    [$app, $s3] = directApp();
    $html = '<html><script>alert(1)</script></html>';
    [$ticket, $intent] = begun($app, strlen($html));

    // A page, sent with its own type: the store refuses the PUT.
    expect($s3->browser('PUT', $intent['url'], $html, ['content-type' => 'text/html', 'content-disposition' => 'inline'])->status)->toBe(403);
    // Sent as the headers say, it is still a page, which nothing keeps.
    $s3->browser('PUT', $intent['url'], $html, $intent['headers']);
    $done = postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']]);

    expect($done->status)->toBe(415)
        ->and($s3->objects)->toBe([]);
});

it('holds the intent to its ticket, its session and its user, and completes it once', function () {
    [$app, $s3] = directApp();
    $bytes = png();
    [$ticket, $intent] = begun($app, strlen($bytes));
    $s3->browser('PUT', $intent['url'], $bytes, $intent['headers']);
    $other = $app->ticket($app->profile(Tinvar\Tests\Uploads\Fixtures\P2));

    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $other, 'intent' => $intent['intent']])->status)->toBe(403)
        ->and(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']], session: 'session-b')->status)->toBe(403)
        ->and(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']], user: 'u-2')->status)->toBe(403)
        ->and(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(201)
        ->and(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(403);
});

it('names a size within the declared limit, and holds the file to it', function () {
    [$app, $s3] = directApp();
    $ticket = $app->ticket($app->profile(P1));

    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $ticket, 'size' => 4097])->status)->toBe(413)
        ->and(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $ticket, 'size' => 0])->status)->toBe(422)
        ->and(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $ticket, 'size' => '12'])->status)->toBe(400);

    // Nothing sent before completing: the file did not arrive.
    [$ticket, $intent] = begun($app, 100);
    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(422);

    // A larger file put there some other way is still held to the size named.
    [$ticket, $intent] = begun($app, 10);
    $s3->objects['' . substr((string) parse_url((string) $intent['url'], PHP_URL_PATH), strlen('/uploads/'))] = png();
    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(413)
        ->and($s3->objects)->toBe([]);
});

it('counts an intent against the cap, and prunes one never completed, file and all', function () {
    [$app, $s3] = directApp();
    for ($i = 0; $i < UploadEndpoint::MAX_PENDING; $i++) {
        begun($app, 10);
    }
    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $app->ticket($app->profile(P1)), 'size' => 10])->status)->toBe(429);

    $app->now += UploadEndpoint::COMPLETE_TTL + Uploads::PRUNE_GRACE + 1;
    $s3->now = $app->now;
    expect($app->uploads->prune())->toBe(UploadEndpoint::MAX_PENDING);
});

// An intent runs out in an hour and ten minutes, and what was PUT under it stays in the
// bucket until pruning removes it: counted until then, or a loop of begun
// and never-completed uploads held several times the quota and the cap.
it('counts an intent past its expiry against the quota and the cap until pruning removes its file', function () {
    [$app, $s3] = directApp(quota: Tinvar\Uploads\UploadQuota::perUser(4000));
    $ticket = $app->ticket($app->profile(P1));

    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $ticket, 'size' => 4000])->status)->toBe(201);
    $app->now += UploadEndpoint::COMPLETE_TTL + 1;
    $s3->now = $app->now;
    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $app->ticket($app->profile(P1)), 'size' => 4000])->status)->toBe(507);

    // Pruned, its file and its record gone: the room is given back.
    $app->now += Uploads::PRUNE_GRACE;
    $s3->now = $app->now;
    expect($app->uploads->prune())->toBe(1)
        ->and(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $app->ticket($app->profile(P1)), 'size' => 4000])->status)->toBe(201);
});

// Review: the intent ran out with its URL, so a large file whose PUT began
// in time but took longer than ten minutes to arrive could not be completed.
it('completes an upload whose PUT began in time and arrived after its URL ran out', function () {
    [$app, $s3] = directApp();
    $bytes = png();
    [$ticket, $intent] = begun($app, strlen($bytes));
    $s3->browser('PUT', $intent['url'], $bytes, $intent['headers']);

    $app->now += UploadEndpoint::DIRECT_TTL + Uploads::PRUNE_GRACE + 60;
    $s3->now = $app->now;
    $app->uploads->prune();

    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(201);
});

it('counts an expired upload against the cap until pruning removes its file', function () {
    [$app] = directApp();
    for ($i = 0; $i < UploadEndpoint::MAX_PENDING; $i++) {
        begun($app, 10);
    }
    $app->now += UploadEndpoint::COMPLETE_TTL + 1;

    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $app->ticket($app->profile(P1)), 'size' => 10])->status)->toBe(429);
});

it('scans what it keeps, as /_upload does', function () {
    [$app, $s3] = directApp(new class implements Tinvar\Uploads\FileScanner {
        public function scan(string $path): ?string
        {
            return 'Eicar-Test-Signature';
        }
    });
    $bytes = png();
    [$ticket, $intent] = begun($app, strlen($bytes));
    $s3->browser('PUT', $intent['url'], $bytes, $intent['headers']);

    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(422)
        ->and($s3->objects)->toBe([]);
});

/** The keys in the fake bucket under the incoming prefix. */
function incoming(FakeS3 $s3): array
{
    return array_values(array_filter(array_keys($s3->objects), static fn (string $key): bool => str_starts_with($key, UploadEndpoint::INCOMING_PREFIX . '/')));
}

// The URL /_upload/direct presigned is good for ten minutes, and completing
// the upload does not end it: a PUT replayed after /complete left an object
// no record named, outside the quota, the cap and pruning. The intent is
// kept, consumed, until the URL is dead and then some, and pruning removes
// what was put under it.
it('removes what a presigned PUT replayed after /complete leaves, once the URL is dead', function () {
    [$app, $s3] = directApp(quota: Tinvar\Uploads\UploadQuota::perUser(10_000));
    $s3->listing = false;
    $bytes = png();
    [$ticket, $intent] = begun($app, strlen($bytes));
    $s3->browser('PUT', $intent['url'], $bytes, $intent['headers']);
    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(201)
        ->and(incoming($s3))->toBe([]);

    // Replayed: the store takes it, and a second completion does not.
    expect($s3->browser('PUT', $intent['url'], $bytes, $intent['headers'])->status)->toBe(200)
        ->and(incoming($s3))->toHaveCount(1)
        ->and(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(403);

    // Not before the URL is dead and a PUT begun before then has had time to arrive.
    $app->now += UploadEndpoint::DIRECT_TTL + Uploads::PRUNE_GRACE + 1;
    $s3->now = $app->now;
    $app->uploads->prune();
    expect(incoming($s3))->toHaveCount(1);

    $app->now += UploadEndpoint::INCOMING_GRACE;
    $s3->now = $app->now;
    // The consumed intent, and the upload kept from it, never claimed by its action in the hour.
    expect($app->uploads->prune())->toBe(2)
        ->and(incoming($s3))->toBe([])
        ->and($s3->objects)->toBe([])
        ->and($app->uploads->prune())->toBe(0);
});

it('completes a file as large as the quota, the consumed intent counting for nothing', function () {
    $bytes = png();
    [$app, $s3] = directApp(quota: Tinvar\Uploads\UploadQuota::perUser(strlen($bytes) + 200));
    [$ticket, $intent] = begun($app, strlen($bytes));
    $s3->browser('PUT', $intent['url'], $bytes, $intent['headers']);

    expect(postDirect($app, UploadEndpoint::COMPLETE_PATH, ['ticket' => $ticket, 'intent' => $intent['intent']])->status)->toBe(201)
        ->and($app->store->pendingCount($app->uploads->ownerHash('u-1'), $app->now))->toBe(1);
});

// Where the store lists what is in it, pruning also removes whatever lies
// under the incoming prefix older than any intent could still want: what
// no record names -- a PUT that arrived after its record was pruned, a
// process killed mid-completion.
it('sweeps the incoming prefix of what is older than any intent could still want, where the store lists', function () {
    [$app, $s3] = directApp();
    $old = UploadEndpoint::INCOMING_PREFIX . '/' . str_repeat('a', 32);
    $young = UploadEndpoint::INCOMING_PREFIX . '/' . str_repeat('b', 32);
    $kept = 'avatars/' . str_repeat('c', 32) . '.png';
    $s3->objects = [$old => 'x', $young => 'y', $kept => 'z'];
    $s3->modified = [
        $old => $app->now - UploadEndpoint::COMPLETE_TTL - Uploads::PRUNE_GRACE - 1,
        $young => $app->now - UploadEndpoint::COMPLETE_TTL,
        $kept => 0,
    ];
    $s3->now = $app->now;

    expect($app->uploads->prune())->toBe(1)
        ->and(array_keys($s3->objects))->toBe([$young, $kept]);

    // A store that refuses to list is pruned by its records alone.
    $s3->listing = false;
    $s3->objects[$old] = 'x';
    expect($app->uploads->prune())->toBe(0)
        ->and($s3->objects)->toHaveKey($old);
});

// Review: the listing stopped at the limit counting young files too, so
// with that many arriving, an old one sorting after them was never reached.
it('sweeps an old file under the incoming prefix however many young ones sort before it', function () {
    [$app, $s3] = directApp();
    $young = [UploadEndpoint::INCOMING_PREFIX . '/' . str_repeat('a', 32), UploadEndpoint::INCOMING_PREFIX . '/' . str_repeat('b', 32)];
    $old = UploadEndpoint::INCOMING_PREFIX . '/' . str_repeat('c', 32);
    $s3->objects = [$young[0] => 'y', $young[1] => 'y', $old => 'x'];
    $s3->modified = [
        $young[0] => $app->now,
        $young[1] => $app->now,
        $old => $app->now - UploadEndpoint::COMPLETE_TTL - Uploads::PRUNE_GRACE - 1,
    ];

    expect($app->uploads->prune(limit: 2))->toBe(1)
        ->and(array_keys($s3->objects))->toBe($young);
});

it('is not there where files are kept on disk or in memory', function () {
    $app = new App(new MemoryStorage());

    expect(postDirect($app, UploadEndpoint::DIRECT_PATH, ['ticket' => $app->ticket($app->profile(P1)), 'size' => 10])->status)->toBe(404);
});

/** A stream that takes no more than $room bytes of all written to it, as a disk that fills up does. */
final class ShortWriteStream
{
    public static int $room = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $taken = min(strlen($data), self::$room);
        self::$room -= $taken;

        return $taken;
    }
}

// Review: the fetch counted the bytes the store sent, not what reached the
// temporary file, so a disk that filled kept a truncated file with a 201.
it('refuses to go on with a chunk the temporary file took only part of', function () {
    stream_wrapper_register('tinvar-short-write', ShortWriteStream::class);
    try {
        ShortWriteStream::$room = 3;
        $out = fopen('tinvar-short-write://temporary', 'wb');
        expect($out)->not->toBeFalse();

        UploadEndpoint::writeWhole($out, 'abc');
        UploadEndpoint::writeWhole($out, 'def');
    } finally {
        stream_wrapper_unregister('tinvar-short-write');
    }
})->throws(Tinvar\Storage\StorageException::class);
