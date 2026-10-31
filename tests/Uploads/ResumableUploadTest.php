<?php

declare(strict_types=1);

/**
 * A resumable upload (ADR 0143): begun under the ticket for a size declared
 * up front, sent through PHP in chunks -- each appended only where the last
 * one ended -- or straight to an object store in parts, asked where it
 * stands whenever the browser comes back, and kept only once whole, exactly
 * as /_upload keeps a file: sniffed, drawn again, scanned, counted.
 */

use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Storage\Fixtures\FakeS3;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Uploads\UploadEndpoint;
use Tinvar\Uploads\Uploads;
use Tinvar\Uploads\UploadSession;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\CSRF;
use const Tinvar\Tests\Uploads\Fixtures\KEY;
use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\P2;
use const Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE;

require_once __DIR__ . '/Fixtures/app.php';
require_once __DIR__ . '/../Storage/Fixtures/s3.php';

/** A PDF of about $bytes, which nothing draws again: a file that takes several chunks. */
function largePdf(int $bytes): string
{
    return str_pad(pdf(), $bytes, "\n% filler\n");
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
function postSession(App $app, string $path, array $body, string $user = 'u-1', string $session = 'session-a', string $epoch = 'e1', array $headers = []): Response
{
    return $app->kernel->handle(
        new Request('POST', $path, [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'x-xsrf-token' => CSRF,
            'x-test-user' => $user,
            'x-test-epoch' => $epoch,
            ...$headers,
        ], cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF]),
        static fn () => Response::make(500),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

/** @param array<string, string> $headers */
function sendChunk(App $app, string $session, int $offset, string $bytes, string $user = 'u-1', string $cookie = 'session-a', array $headers = []): Response
{
    return $app->kernel->handle(
        new Request('POST', UploadEndpoint::SESSION_CHUNK_PATH, [
            'content-type' => 'application/offset+octet-stream',
            'accept' => 'application/json',
            'content-length' => (string) strlen($bytes),
            'upload-session' => $session,
            'upload-offset' => (string) $offset,
            'x-xsrf-token' => CSRF,
            'x-test-user' => $user,
            'x-test-epoch' => 'e1',
            ...$headers,
        ], cookies: [SESSION_COOKIE => $cookie, VerifyCsrfToken::COOKIE => CSRF]),
        static fn () => Response::make(500),
        $bytes,
    );
}

/** @return array<string, mixed> what /_upload/session answered, for a document of $size on P1 */
function beginSession(App $app, int $size, string $name = 'notes.pdf', string $field = 'document', bool $direct = false): array
{
    $action = "profiles.{$field}";
    $ticket = $app->ticket($app->profile(P1), action: $action, field: $field);
    $response = postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => $size, 'name' => $name, 'direct' => $direct]);
    expect($response->status)->toBe(201, $response->body());

    return json($response);
}

function offsetOf(App $app, string $session): Response
{
    return postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session]);
}

describe('through PHP, in chunks', function () {
    it('takes a file in chunks, says where it stands between them, and keeps it only once whole', function () {
        $app = new App($storage = new MemoryStorage());
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES * 2 + 1000);
        $begun = beginSession($app, strlen($file));

        expect($begun['mode'])->toBe(UploadSession::CHUNKS)
            ->and($begun['offset'])->toBe(0)
            ->and($begun['size'])->toBe(strlen($file))
            ->and($begun['chunkBytes'])->toBe(UploadEndpoint::CHUNK_BYTES)
            ->and($begun['expiresAt'])->toBe($app->now + UploadEndpoint::SESSION_TTL);
        $session = (string) $begun['session'];

        $first = sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES));
        expect($first->status)->toBe(200)
            ->and($first->header('upload-offset'))->toBe((string) UploadEndpoint::MIN_CHUNK_BYTES)
            ->and(json($first)['offset'])->toBe(UploadEndpoint::MIN_CHUNK_BYTES);

        // Whatever is held so far is nowhere an action declares, and no upload yet.
        $partial = array_values(array_filter($storage->keys(), static fn (string $k): bool => str_starts_with($k, UploadEndpoint::PARTIAL_PREFIX . '/')));
        expect($partial)->toHaveCount(1)
            ->and($storage->keys())->toBe($partial)
            ->and(json(offsetOf($app, $session)))->toMatchArray(['offset' => UploadEndpoint::MIN_CHUNK_BYTES, 'size' => strlen($file), 'name' => 'notes.pdf'])
            // Its record is no upload an action could be handed.
            ->and($app->call('profiles.document', ['profile' => P1, 'document' => $app->store->all()[0]->id])->status)->toBe(422);

        sendChunk($app, $session, UploadEndpoint::MIN_CHUNK_BYTES, substr($file, UploadEndpoint::MIN_CHUNK_BYTES, UploadEndpoint::MIN_CHUNK_BYTES));
        $done = sendChunk($app, $session, 2 * UploadEndpoint::MIN_CHUNK_BYTES, substr($file, 2 * UploadEndpoint::MIN_CHUNK_BYTES));

        expect($done->status)->toBe(201);
        $kept = $app->store->find((string) json($done)['upload']);
        expect($kept?->type)->toBe('application/pdf')
            ->and($kept?->key)->toStartWith('documents/')
            ->and($kept?->name)->toBe('notes.pdf')
            ->and($storage->contents((string) $kept?->key))->toBe($file)
            // The partial bytes are gone, and the session's record with them.
            ->and($storage->keys())->toBe([(string) $kept?->key])
            ->and($app->store->all())->toHaveCount(1)
            ->and($app->call('profiles.document', ['profile' => P1, 'document' => json($done)['upload']])->status)->toBe(200);
    });

    it('refuses an append at another offset than it holds, says which, and writes a chunk sent twice once', function () {
        $app = new App(new MemoryStorage());
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES * 2 + 10);
        $session = (string) beginSession($app, strlen($file))['session'];
        $chunk = substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES);

        expect(sendChunk($app, $session, 0, $chunk)->status)->toBe(200);
        $again = sendChunk($app, $session, 0, $chunk);
        $ahead = sendChunk($app, $session, 2 * UploadEndpoint::MIN_CHUNK_BYTES, substr($file, 2 * UploadEndpoint::MIN_CHUNK_BYTES));

        expect($again->status)->toBe(409)
            ->and($again->header('upload-offset'))->toBe((string) UploadEndpoint::MIN_CHUNK_BYTES)
            ->and(json($again)['error']['code'])->toBe('upload_offset')
            ->and($ahead->status)->toBe(409)
            ->and(json(offsetOf($app, $session))['offset'])->toBe(UploadEndpoint::MIN_CHUNK_BYTES);
    });

    it('holds each chunk to the cap, the floor, the size declared and the offset it names', function () {
        $app = new App(new MemoryStorage());
        $file = largePdf(UploadEndpoint::MAX_CHUNK_BYTES + UploadEndpoint::MIN_CHUNK_BYTES);
        $session = (string) beginSession($app, strlen($file))['session'];

        expect(sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MAX_CHUNK_BYTES + 1))->status)->toBe(413)
            // Short of the end, a chunk is at least the floor: a file is never a million appends.
            ->and(sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES - 1))->status)->toBe(400)
            ->and(sendChunk($app, $session, 0, '')->status)->toBe(400)
            ->and(sendChunk($app, $session, 0, 'x', headers: ['upload-offset' => '-1'])->status)->toBe(400)
            ->and(sendChunk($app, $session, 0, 'x', headers: ['upload-offset' => '0x10'])->status)->toBe(400);

        $short = beginSession($app, 10);
        expect(sendChunk($app, (string) $short['session'], 0, '%PDF-1.4 and more than ten')->status)->toBe(413)
            ->and(json(offsetOf($app, (string) $short['session']))['offset'])->toBe(0);
    });

    it('checks a chunk against the checksum sent with it, writing nothing that does not match', function () {
        $app = new App(new MemoryStorage());
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES + 10);
        $session = (string) beginSession($app, strlen($file))['session'];
        $chunk = substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES);
        $sum = static fn (string $bytes): string => 'sha256 ' . base64_encode(hash('sha256', $bytes, true));

        expect(sendChunk($app, $session, 0, $chunk, headers: ['upload-checksum' => $sum('other')])->status)->toBe(460)
            ->and(sendChunk($app, $session, 0, $chunk, headers: ['upload-checksum' => 'md5 abc'])->status)->toBe(400)
            ->and(json(offsetOf($app, $session))['offset'])->toBe(0)
            ->and(sendChunk($app, $session, 0, $chunk, headers: ['upload-checksum' => $sum($chunk)])->status)->toBe(200);
    });

    it('holds every use to the session, the user at their epoch, and the signature', function () {
        $app = new App(new MemoryStorage());
        $session = (string) beginSession($app, 100)['session'];
        $forged = substr($session, 0, -2) . (str_ends_with($session, 'AA') ? 'BB' : 'AA');

        expect(offsetOf($app, $session)->status)->toBe(200)
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session], session: 'session-b')->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session], user: 'u-2')->status)->toBe(403)
            // Signed out and in again: a new epoch, and the session is not theirs any more.
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session], epoch: 'e2')->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $forged])->status)->toBe(403)
            // A ticket is no session, and a session no ticket.
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $app->ticket($app->profile(P1))])->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $session, 'size' => 10])->status)->toBe(403)
            ->and(sendChunk($app, $session, 0, '%PDF-', cookie: 'session-b')->status)->toBe(403)
            ->and(sendChunk($app, $session, 0, '%PDF-', user: 'u-2')->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, [])->status)->toBe(400);
    });

    it('is begun only under a ticket for the size declared, within the limit, the cap and the quota', function () {
        $app = new App(new MemoryStorage(), quota: Tinvar\Uploads\UploadQuota::perUser(1000));
        $ticket = $app->ticket($app->profile(P1), action: 'profiles.document', field: 'document');
        $begin = static fn (array $body): int => postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, ...$body])->status;

        expect($begin(['size' => 0]))->toBe(422)
            ->and($begin(['size' => '10']))->toBe(400)
            ->and($begin([]))->toBe(400)
            ->and($begin(['size' => Uploads::DEFAULT_MAX_BYTES + 1]))->toBe(413)
            // The declared size is held against the quota from the start.
            ->and($begin(['size' => 1001]))->toBe(507)
            ->and($begin(['size' => 600]))->toBe(201)
            ->and($begin(['size' => 600]))->toBe(507);

        // And each counts toward the cap on uploads waiting.
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1), action: 'profiles.document', field: 'document');
        for ($i = 0; $i < UploadEndpoint::MAX_PENDING; $i++) {
            expect(postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => 10])->status)->toBe(201);
        }
        expect(postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => 10])->status)->toBe(429);
    });

    it('lives a day, then is refused, and pruning removes its bytes and gives its room back', function () {
        $app = new App($storage = new MemoryStorage(), quota: Tinvar\Uploads\UploadQuota::perUser(UploadEndpoint::MIN_CHUNK_BYTES * 2));
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES * 2);
        $session = (string) beginSession($app, strlen($file))['session'];
        sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES));

        $app->now += UploadEndpoint::SESSION_TTL - 1;
        expect(json(offsetOf($app, $session))['offset'])->toBe(UploadEndpoint::MIN_CHUNK_BYTES);
        $app->now += 1;
        expect(offsetOf($app, $session)->status)->toBe(403)
            ->and(sendChunk($app, $session, UploadEndpoint::MIN_CHUNK_BYTES, substr($file, UploadEndpoint::MIN_CHUNK_BYTES))->status)->toBe(403);

        $app->now += Uploads::PRUNE_GRACE + 1;
        expect($app->uploads->prune())->toBe(1)
            ->and($storage->keys())->toBe([])
            ->and($app->store->all())->toBe([]);
        beginSession($app, strlen($file));
    });

    it('is abandoned on request, its bytes removed and its room given back', function () {
        $app = new App($storage = new MemoryStorage(), quota: Tinvar\Uploads\UploadQuota::perUser(UploadEndpoint::MIN_CHUNK_BYTES * 2));
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES * 2);
        $session = (string) beginSession($app, strlen($file))['session'];
        sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES));

        expect(postSession($app, UploadEndpoint::SESSION_ABORT_PATH, ['session' => $session], user: 'u-2')->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_ABORT_PATH, ['session' => $session])->status)->toBe(204)
            ->and(postSession($app, UploadEndpoint::SESSION_ABORT_PATH, ['session' => $session])->status)->toBe(204)
            ->and($storage->keys())->toBe([])
            ->and($app->store->all())->toBe([])
            ->and(offsetOf($app, $session)->status)->toBe(404)
            ->and(json(offsetOf($app, $session))['error']['code'])->toBe('upload_session_gone')
            ->and(sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES))->status)->toBe(404)
            // Nothing written for a session that is gone.
            ->and($storage->keys())->toBe([]);
        beginSession($app, strlen($file));
    });

    // The last chunk's answer lost: sent again, or asked about, it answers
    // with the upload it already made -- and makes no second one, and
    // leaves nothing behind.
    it('answers a completed session with the upload it made, and nothing more', function () {
        $app = new App($storage = new MemoryStorage());
        $file = largePdf(UploadEndpoint::MIN_CHUNK_BYTES + 10);
        $session = (string) beginSession($app, strlen($file))['session'];
        sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES));
        $done = json(sendChunk($app, $session, UploadEndpoint::MIN_CHUNK_BYTES, substr($file, UploadEndpoint::MIN_CHUNK_BYTES)));

        $again = sendChunk($app, $session, UploadEndpoint::MIN_CHUNK_BYTES, substr($file, UploadEndpoint::MIN_CHUNK_BYTES));
        $asked = offsetOf($app, $session);
        expect($again->status)->toBe(201)
            ->and(json($again))->toBe($done)
            ->and(json($asked))->toMatchArray(['offset' => strlen($file), 'upload' => $done])
            // A first chunk replayed makes no file of its own.
            ->and(sendChunk($app, $session, 0, substr($file, 0, UploadEndpoint::MIN_CHUNK_BYTES))->status)->toBe(201)
            ->and($storage->keys())->toHaveCount(1)
            ->and($app->store->all())->toHaveCount(1)
            // To no one else.
            ->and(offsetOf($app, $session)->status)->toBe(200)
            ->and(postSession($app, UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session], user: 'u-2')->status)->toBe(403);

        // Once the action has claimed it, the session is over.
        expect($app->call('profiles.document', ['profile' => P1, 'document' => $done['upload']])->status)->toBe(200)
            ->and(offsetOf($app, $session)->status)->toBe(404);
    });

    it('keeps a whole file only as /_upload would: a page is refused, and nothing of it kept', function () {
        $app = new App($storage = new MemoryStorage());
        $html = '<html><script>alert(1)</script></html>';
        $session = (string) beginSession($app, strlen($html), 'me.png', 'avatar')['session'];

        expect(sendChunk($app, $session, 0, $html)->status)->toBe(415)
            ->and($storage->keys())->toBe([])
            ->and($app->store->all())->toBe([])
            ->and(offsetOf($app, $session)->status)->toBe(404);

        // An image is drawn again: what is kept is not what was sent.
        $bytes = png() . 'after the image';
        $session = (string) beginSession($app, strlen($bytes), 'me.png', 'avatar')['session'];
        $kept = $app->store->find((string) json(sendChunk($app, $session, 0, $bytes))['upload']);
        expect($kept?->type)->toBe('image/png')
            ->and($storage->contents((string) $kept?->key))->not->toContain('after the image');
    });

    it('holds a session to the target its ticket named', function () {
        $app = new App(new MemoryStorage());
        $other = new UploadSession(
            id: str_repeat('a', 32),
            mode: UploadSession::CHUNKS,
            action: 'profiles.document',
            field: 'document',
            targetClass: Tinvar\Tests\Uploads\Fixtures\Profile::class,
            targetId: P2,
            prefix: 'avatars',
            tenant: '',
            panel: '',
            sessionHash: $app->uploads->sessionHash('session-a'),
            authHash: $app->uploads->authHash('u-1', 'e1'),
            size: 10,
            issuedAt: $app->now,
            expiresAt: $app->now + 60,
        );

        // Signed, but naming another prefix than the parameter declares.
        expect(offsetOf($app, $other->encode(new Tinvar\Support\Signer(KEY, Tinvar\Uploads\UploadTicket::PURPOSE)))->status)->toBe(403);
    });
});

describe('straight to the store, in parts', function () {
    /** @return array{App, FakeS3} */
    function partsApp(?Tinvar\Uploads\UploadQuota $quota = null): array
    {
        $s3 = new FakeS3();
        $app = new App($s3->storage(), quota: $quota);
        $s3->now = $app->now;

        return [$app, $s3];
    }

    function askParts(App $app, string $session, string $user = 'u-1'): Response
    {
        return postSession($app, UploadEndpoint::SESSION_PARTS_PATH, ['session' => $session], user: $user);
    }

    it('hands out a URL for each part, bound to its number and length, and goes on from the parts the store holds', function () {
        [$app, $s3] = partsApp();
        $file = largePdf(Tinvar\Storage\S3\S3Storage::MIN_PART_BYTES + 100);
        $begun = beginSession($app, strlen($file), field: 'archive', direct: true);
        $session = (string) $begun['session'];
        $partBytes = Tinvar\Storage\S3\S3Storage::MIN_PART_BYTES;

        expect($begun['mode'])->toBe(UploadSession::PARTS)
            ->and($begun['partBytes'])->toBe($partBytes)
            ->and($s3->begun)->toHaveCount(1)
            // The store's id for the upload is nowhere but inside what PHP signed.
            ->and(json_encode($begun))->not->toContain((string) array_key_first($s3->begun));

        $granted = json(askParts($app, $session));
        expect($granted['offset'])->toBe(0)
            ->and(array_column($granted['parts'], 'number'))->toBe([1, 2])
            ->and(array_column($granted['parts'], 'size'))->toBe([$partBytes, 100])
            ->and($granted['parts'][0]['expiresAt'])->toBe($app->now + UploadEndpoint::PART_TTL);

        // A part of another length than signed is refused by the store.
        expect($s3->browser('PUT', $granted['parts'][0]['url'], substr($file, 0, $partBytes - 1))->status)->toBe(403)
            ->and($s3->browser('PUT', $granted['parts'][0]['url'], substr($file, 0, $partBytes))->status)->toBe(200);

        // Back after a refresh: the store says what it holds, and the rest is asked for.
        $resumed = json(askParts($app, $session));
        expect($resumed['offset'])->toBe($partBytes)
            ->and(array_column($resumed['parts'], 'number'))->toBe([2])
            ->and(json(offsetOf($app, $session))['offset'])->toBe($partBytes);

        // Not complete until every part is there.
        $early = postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session]);
        expect($early->status)->toBe(409)
            ->and($early->header('upload-offset'))->toBe((string) $partBytes);

        $s3->browser('PUT', $resumed['parts'][0]['url'], substr($file, $partBytes));
        $done = postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session]);

        expect($done->status)->toBe(201, $done->body());
        $kept = $app->store->find((string) json($done)['upload']);
        expect($kept?->type)->toBe('application/pdf')
            ->and($kept?->key)->toStartWith('documents/')
            ->and(array_keys($s3->objects))->toBe([(string) $kept?->key])
            ->and($s3->objects[(string) $kept?->key])->toBe($file)
            ->and($s3->uploads)->toBe([])
            ->and($app->store->all())->toHaveCount(1)
            // Completed: its URLs put nothing anywhere any more.
            ->and($s3->browser('PUT', $granted['parts'][0]['url'], substr($file, 0, $partBytes))->status)->toBe(404)
            // And asked again, it answers with what it made.
            ->and(json(postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session])))->toBe(json($done))
            ->and(askParts($app, $session)->status)->toBe(404)
            ->and($app->call('profiles.archive', ['profile' => P1, 'archive' => json($done)['upload']])->status)->toBe(200);
    });

    it('refuses a part the store holds at another size than the one it was granted', function () {
        [$app, $s3] = partsApp();
        $file = largePdf(Tinvar\Storage\S3\S3Storage::MIN_PART_BYTES + 100);
        $session = (string) beginSession($app, strlen($file), field: 'archive', direct: true)['session'];
        $id = (string) array_key_first($s3->begun);
        // Put there some other way, at the wrong size.
        $s3->uploads[$id][1] = substr($file, 0, 1000);
        $s3->uploads[$id][2] = substr($file, Tinvar\Storage\S3\S3Storage::MIN_PART_BYTES);

        expect(json(askParts($app, $session))['offset'])->toBe(0)
            ->and(postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session])->status)->toBe(409)
            ->and($app->store->all())->toHaveCount(1);
    });

    it('keeps what was sent in parts only as /_upload would', function () {
        [$app, $s3] = partsApp();
        $html = '<html><script>alert(1)</script></html>';
        $session = (string) beginSession($app, strlen($html), 'me.png', 'avatar', direct: true)['session'];
        $s3->browser('PUT', json(askParts($app, $session))['parts'][0]['url'], $html);

        expect(postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session])->status)->toBe(415)
            ->and($s3->objects)->toBe([])
            ->and($s3->uploads)->toBe([])
            ->and($app->store->all())->toBe([]);
    });

    it('aborts the store\'s upload when the session is abandoned, or pruned once it has run out', function () {
        [$app, $s3] = partsApp(Tinvar\Uploads\UploadQuota::perUser(1000));
        $session = (string) beginSession($app, 1000, direct: true)['session'];
        $s3->browser('PUT', json(askParts($app, $session))['parts'][0]['url'], largePdf(1000));

        expect(postSession($app, UploadEndpoint::SESSION_ABORT_PATH, ['session' => $session])->status)->toBe(204)
            ->and($s3->uploads)->toBe([])
            ->and($app->store->all())->toBe([]);

        beginSession($app, 1000, direct: true);
        expect($s3->uploads)->toHaveCount(1);
        $app->now += UploadEndpoint::SESSION_TTL + Uploads::PRUNE_GRACE + 1;
        $s3->now = $app->now;
        expect($app->uploads->prune())->toBe(1)
            ->and($s3->uploads)->toBe([])
            ->and($app->store->all())->toBe([]);
    });

    // A multipart upload no record names -- a process killed between
    // beginning it and recording it -- is aborted once it is older than
    // any session could be, where the store lists its uploads.
    it('sweeps the store of uploads begun under the partial prefix longer ago than any session lives', function () {
        [$app, $s3] = partsApp();
        $storage = $s3->storage();
        $old = $storage->beginMultipart(UploadEndpoint::PARTIAL_PREFIX . '/' . str_repeat('a', 32));
        $s3->now += UploadEndpoint::SESSION_TTL + Uploads::PRUNE_GRACE + 1;
        $app->now = $s3->now;
        $young = $storage->beginMultipart(UploadEndpoint::PARTIAL_PREFIX . '/' . str_repeat('b', 32));
        $elsewhere = $storage->beginMultipart('avatars/' . str_repeat('c', 32));

        $app->uploads->prune();
        expect(array_keys($s3->uploads))->toBe([$young, $elsewhere])
            ->and($old)->not->toBe($young);
    });

    it('holds the parts and the completion to the session\'s binding', function () {
        [$app] = partsApp();
        $session = (string) beginSession($app, 100, direct: true)['session'];

        expect(askParts($app, $session, user: 'u-2')->status)->toBe(403)
            ->and(postSession($app, UploadEndpoint::SESSION_COMPLETE_PATH, ['session' => $session], session: 'session-b')->status)->toBe(403)
            // Sent in parts, a session takes no chunk through PHP.
            ->and(sendChunk($app, $session, 0, '%PDF-')->status)->toBe(400);
    });
});

it('begins a session only in a mode its storage can take', function () {
    // Memory takes chunks, and has no store to send parts to.
    $app = new App(new MemoryStorage());
    $ticket = $app->ticket($app->profile(P1), action: 'profiles.document', field: 'document');
    expect(postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => 10, 'direct' => true])->status)->toBe(404);

    // An object store takes parts, and cannot be appended to through PHP.
    $s3 = new FakeS3();
    $app = new App($s3->storage());
    $ticket = $app->ticket($app->profile(P1), action: 'profiles.document', field: 'document');
    expect(postSession($app, UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => 10])->status)->toBe(404);
});
