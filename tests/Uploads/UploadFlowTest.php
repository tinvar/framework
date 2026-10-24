<?php

declare(strict_types=1);

/**
 * The upload-ticket flow end to end, through the Kernel: a ticket issued as
 * a loader issues it, a file POSTed to /_upload under it, and an action
 * called with the reference /_upload answered -- and every way of tampering
 * with one of them, or using it where it was not bound.
 */

use Tinvar\Actions\ActionRunner;
use Tinvar\Storage\LocalStorage;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Uploads\PendingUpload;
use Tinvar\Uploads\UploadEndpoint;
use Tinvar\Uploads\Uploads;
use Tinvar\Uploads\UploadTicket;
use Tinvar\Uploads\UploadTickets;

use function Tinvar\Tests\Uploads\Fixtures\jpeg;
use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\A1;
use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\P2;
use const Tinvar\Tests\Uploads\Fixtures\P3;

require_once __DIR__ . '/Fixtures/app.php';

/** Uploads a PNG for profile p-1 and returns the reference. */
function uploadedPng(App $app, string $field = 'avatar', string $action = 'profiles.avatar'): string
{
    $response = $app->upload($app->ticket($app->profile(P1), action: $action, field: $field), png());
    expect($response->status)->toBe(201);

    return (string) json($response)['upload'];
}

describe('a ticket', function () {
    it('is issued from the container a loader is built by, for a saved record of the declared model', function () {
        $app = new App(new MemoryStorage());

        expect(($app->container->resolver())(UploadTickets::class))->toBe($app->kernel->uploadTickets);

        $ticket = UploadTicket::decode($app->ticket($app->profile(P1)), $app->uploads->ticketSigner, $app->now);
        expect($ticket->action)->toBe('profiles.avatar')
            ->and($ticket->field)->toBe('avatar')
            ->and($ticket->targetClass)->toBe(Tinvar\Tests\Uploads\Fixtures\Profile::class)
            ->and($ticket->targetId)->toBe(P1)
            ->and($ticket->prefix)->toBe('avatars')
            ->and($ticket->expiresAt)->toBe($app->now + UploadTicket::DEFAULT_TTL);
    });

    it('names an action by its class and method as well as by its wire name', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1), action: [Tinvar\Tests\Uploads\Fixtures\ProfileActions::class, 'avatar']);

        expect(UploadTicket::decode($ticket, $app->uploads->ticketSigner, $app->now)->action)->toBe('profiles.avatar');
    });

    it('is refused for another model than the parameter is attached to, a field with no upload, or nobody signed in', function () {
        $app = new App(new MemoryStorage());

        expect(fn () => $app->ticket($app->album(A1)))->toThrow(LogicException::class, 'attaches')
            ->and(fn () => $app->ticket($app->profile(P1), field: 'profile'))->toThrow(LogicException::class, 'no #[Uploaded]')
            ->and(fn () => $app->kernel->uploadTickets?->issue(
                new Tinvar\Http\Request('GET', '/', cookies: [Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE => 's']),
                'profiles.avatar',
                'avatar',
                $app->profile(P1),
            ))->toThrow(LogicException::class, 'signed-in');
    });
});

describe('/_upload', function () {
    it('stores the file under a key of its own making, and answers with a reference', function () {
        $app = new App($storage = new MemoryStorage());

        $response = $app->upload($app->ticket($app->profile(P1)), $bytes = png(), name: 'holiday photo.png');

        expect($response->status)->toBe(201)
            ->and($response->header('cache-control'))->toBe('private, no-store');
        $body = json($response);
        expect($body['upload'])->toMatch('/^[a-f0-9]{32}$/')
            ->and($body['name'])->toBe('holiday photo.png')
            ->and($body['type'])->toBe('image/png')
            ->and($body['size'])->toBe(\strlen($bytes));

        $pending = $app->store->find((string) $body['upload']);
        expect($pending)->toBeInstanceOf(PendingUpload::class)
            ->and($pending?->key)->toMatch('#^avatars/[a-f0-9]{32}\.png$#')
            ->and($storage->contents((string) $pending?->key))->toBe($bytes)
            ->and($pending?->claimedAt)->toBeNull()
            ->and($pending?->expiresAt)->toBe($app->now + Uploads::PENDING_TTL);
    });

    it('refuses another user\'s ticket', function () {
        $app = new App($storage = new MemoryStorage());
        $ticket = $app->ticket($app->profile(P3), user: 'u-2');

        $response = $app->upload($ticket, png(), user: 'u-1');

        expect($response->status)->toBe(403)
            ->and(json($response)['error']['code'])->toBe('upload_refused')
            ->and($storage->keys())->toBe([]);
    });

    it('refuses the same user\'s ticket from another session, or from before their auth epoch moved', function () {
        $app = new App($storage = new MemoryStorage());

        expect($app->upload($app->ticket($app->profile(P1), session: 'session-b'), png())->status)->toBe(403)
            ->and($app->upload($app->ticket($app->profile(P1), epoch: 'e0'), png())->status)->toBe(403)
            ->and($storage->keys())->toBe([]);
    });

    it('refuses an expired ticket', function () {
        $app = new App($storage = new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));

        $app->now += UploadTicket::DEFAULT_TTL;

        expect($app->upload($ticket, png())->status)->toBe(403)
            ->and($storage->keys())->toBe([]);
    });

    it('refuses a ticket with any byte of it changed, or a token of another kind', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));
        [$version, $payload, $mac] = explode('.', $ticket);

        // The target swapped for another user's profile, re-encoded, under the old MAC.
        $claims = json_decode((string) Tinvar\Support\Signer::decode($payload), true);
        $claims['k'] = P3;
        $forged = $version . '.' . Tinvar\Support\Signer::encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.' . $mac;

        // Signed with the right key for another purpose.
        $fragmentSigned = Tinvar\Render\SignedToken::encode(
            new Tinvar\Support\Signer(Tinvar\Tests\Uploads\Fixtures\KEY, Tinvar\Render\FragmentToken::PURPOSE),
            UploadTicket::PURPOSE,
            $claims,
        );

        foreach ([$forged, $fragmentSigned, substr($ticket, 0, -2) . 'AA', 'v1.e30.AAAA'] as $bad) {
            $response = $app->upload($bad, png());
            expect($response->status)->toBe(403)
                ->and(json($response)['error']['code'])->toBe('upload_refused');
        }

        // Longer than any ticket is read at all.
        expect($app->upload(str_repeat('a', UploadEndpoint::MAX_TICKET_BYTES + 1), png())->status)->toBe(400)
            ->and($app->store instanceof Tinvar\Uploads\ArrayUploadStore ? $app->store->all() : [])->toBe([]);
    });

    it('refuses a ticket for another tenant than the request is in', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1), tenant: 'acme');

        expect($app->upload($ticket, png(), headers: ['x-test-tenant' => 'globex'])->status)->toBe(403)
            ->and($app->upload($ticket, png(), headers: ['x-test-tenant' => 'acme'])->status)->toBe(201);
    });

    it('keeps a path in the client\'s filename as metadata, and stores nothing outside its root', function () {
        $root = sys_get_temp_dir() . '/tinvar-upload-root-' . bin2hex(random_bytes(4));
        $app = new App(new LocalStorage($root));

        try {
            $response = $app->upload($app->ticket($app->profile(P1)), png(), name: '../../../etc/passwd');
            expect($response->status)->toBe(201);

            $pending = $app->store->find((string) json($response)['upload']);
            expect($pending?->name)->toBe('passwd')
                ->and($pending?->key)->toMatch('#^avatars/[a-f0-9]{32}\.png$#')
                ->and(is_file($root . '/' . $pending?->key))->toBeTrue()
                ->and(glob($root . '/*'))->toBe([$root . '/avatars']);

            // A Windows path, and a name made only of what is stripped.
            $windows = $app->upload($app->ticket($app->profile(P1)), png(), name: '..\\..\\boot.ini');
            expect($app->store->find((string) json($windows)['upload'])?->name)->toBe('boot.ini');
            $nothing = $app->upload($app->ticket($app->profile(P1)), png(), name: "../..\x00/");
            expect($app->store->find((string) json($nothing)['upload'])?->name)->toBe('upload');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    });

    it('refuses a file over the declared size, and one PHP itself refused as too large', function () {
        $app = new App($storage = new MemoryStorage());

        $big = png(64, 64);
        expect(\strlen($big))->toBeGreaterThan(0);
        $padded = $big . str_repeat("\0", 4097 - \strlen($big));

        $response = $app->upload($app->ticket($app->profile(P1)), $padded);
        expect($response->status)->toBe(413)
            ->and(json($response)['error']['code'])->toBe('upload_too_large')
            ->and($app->upload($app->ticket($app->profile(P1)), png(), error: UPLOAD_ERR_INI_SIZE)->status)->toBe(413)
            ->and($app->upload($app->ticket($app->profile(P1)), png(), headers: ['content-length' => (string) (Uploads::DEFAULT_MAX_BYTES * 2)])->status)->toBe(413)
            ->and($storage->keys())->toBe([]);
    });

    it('refuses a file whose bytes are not a declared type, whatever its name and type say', function () {
        $app = new App($storage = new MemoryStorage());

        $html = '<!doctype html><script>alert(document.cookie)</script>';
        $response = $app->upload($app->ticket($app->profile(P1)), $html, name: 'me.png', clientType: 'image/png');
        expect($response->status)->toBe(415)
            ->and(json($response)['error']['code'])->toBe('upload_type');

        // A real file of a type uploads know, and this parameter does not take.
        expect($app->upload($app->ticket($app->profile(P1)), pdf(), name: 'me.png')->status)->toBe(415)
            // Only the start of a PNG.
            ->and($app->upload($app->ticket($app->profile(P1)), "\x89PNG\r\n\x1a\n" . str_repeat('x', 64))->status)->toBe(415)
            // An SVG, which can carry script, is not a type uploads take at all.
            ->and($app->upload($app->ticket($app->profile(P1)), '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', name: 'a.svg', clientType: 'image/svg+xml')->status)->toBe(415)
            ->and($storage->keys())->toBe([]);

        // A JPEG named as a PNG is a JPEG: stored as one, and its key says so.
        $jpeg = $app->upload($app->ticket($app->profile(P1)), jpeg(), name: 'me.png', clientType: 'image/png');
        expect($jpeg->status)->toBe(201)
            ->and(json($jpeg)['type'])->toBe('image/jpeg')
            ->and($app->store->find((string) json($jpeg)['upload'])?->key)->toEndWith('.jpg');
    });

    it('refuses an empty file and a request with no file', function () {
        $app = new App(new MemoryStorage());

        expect($app->upload($app->ticket($app->profile(P1)), '')->status)->toBe(422)
            ->and($app->upload($app->ticket($app->profile(P1)), png(), error: UPLOAD_ERR_NO_FILE)->status)->toBe(422);
    });

    it('is behind CSRF and authentication, and takes a multipart POST only', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));

        expect($app->upload($ticket, png(), headers: ['x-xsrf-token' => 'another'])->status)->toBe(419);

        $anonymous = $app->kernel->handle(
            new Tinvar\Http\Request(
                'POST',
                '/_upload',
                ['content-type' => 'multipart/form-data; boundary=x', 'x-xsrf-token' => Tinvar\Tests\Uploads\Fixtures\CSRF],
                body: ['ticket' => $ticket],
                cookies: [Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE => 'session-a', Tinvar\Http\Middleware\VerifyCsrfToken::COOKIE => Tinvar\Tests\Uploads\Fixtures\CSRF],
            ),
            static fn () => Tinvar\Http\Response::make(500),
        );
        expect($anonymous->status)->toBe(401);

        $get = $app->kernel->handle(new Tinvar\Http\Request('GET', '/_upload'), static fn () => Tinvar\Http\Response::make(500));
        expect($get->status)->toBe(405);

        $json = $app->upload($ticket, png(), headers: ['content-type' => 'application/json']);
        expect($json->status)->toBe(415);
    });

    it('caps the uploads one user has waiting to be claimed', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));

        for ($i = 0; $i < UploadEndpoint::MAX_PENDING; $i++) {
            expect($app->upload($ticket, png())->status)->toBe(201);
        }

        $refused = $app->upload($ticket, png());
        expect($refused->status)->toBe(429)
            ->and(json($refused)['error']['code'])->toBe('upload_pending');
    });
});

describe('an action taking an upload', function () {
    it('is handed the upload its reference names, and claims it', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        $response = $app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference]);

        expect($response->status)->toBe(200)
            ->and(Tinvar\Tests\Uploads\Fixtures\ProfileActions::$received?->id)->toBe($reference)
            ->and(Tinvar\Tests\Uploads\Fixtures\ProfileActions::$received?->type)->toBe('image/png')
            ->and($app->store->find($reference)?->claimedAt)->toBe($app->now)
            ->and($app->profile(P1)->avatarKey)->toMatch('#^avatars/#');
    });

    it('refuses a reference made for another record of the same model', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        // u-1 owns p-2 as well, so the call is authorized: the upload is what is refused.
        $response = $app->call('profiles.avatar', ['profile' => P2, 'avatar' => $reference]);

        expect($response->status)->toBe(422)
            ->and(json($response)['errors']['avatar'])->toBe([ActionRunner::UPLOAD_REFUSED])
            ->and($app->store->find($reference)?->claimedAt)->toBeNull();
    });

    it('refuses a reference made for another action, another parameter, or another model', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        expect($app->call('profiles.document', ['profile' => P1, 'document' => $reference])->status)->toBe(422)
            ->and($app->call('albums.cover', ['album' => A1, 'cover' => $reference])->status)->toBe(422)
            ->and($app->store->find($reference)?->claimedAt)->toBeNull();
    });

    it('refuses a reference uploaded by another user, or in another session', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        // u-2 calling with u-1's reference, on u-2's own profile.
        $theirs = $app->call('profiles.avatar', ['profile' => P3, 'avatar' => $reference], user: 'u-2');
        expect($theirs->status)->toBe(422);

        $elsewhere = $app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference], session: 'session-b');
        expect($elsewhere->status)->toBe(422)
            ->and($app->store->find($reference)?->claimedAt)->toBeNull();
    });

    it('refuses a reference once it has been claimed, and once it has expired', function () {
        $app = new App(new MemoryStorage());
        $first = uploadedPng($app);
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $first])->status)->toBe(200)
            ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $first])->status)->toBe(422);

        $second = uploadedPng($app);
        $app->now += Uploads::PENDING_TTL;
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $second])->status)->toBe(422);
    });

    it('refuses a reference whose file is gone, a malformed one, and none at all', function () {
        $app = new App($storage = new MemoryStorage());
        $reference = uploadedPng($app);
        $storage->delete((string) $app->store->find($reference)?->key);

        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(422)
            ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => '../../etc/passwd'])->status)->toBe(422)
            ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => ['nested' => 'array']])->status)->toBe(422);

        $none = $app->call('profiles.avatar', ['profile' => P1]);
        expect(json($none)['errors']['avatar'])->toBe(['avatar is required.']);

        // An optional upload left out is null in the body.
        $optional = $app->call('profiles.document', ['profile' => P1]);
        expect($optional->status)->toBe(200)
            ->and(json($optional)['value'])->toBe(['attached' => false]);
    });

    it('gives the claim back when its body fails, so the upload can be used again', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        Tinvar\Tests\Uploads\Fixtures\ProfileActions::$fail = true;
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(500)
            ->and($app->store->find($reference)?->claimedAt)->toBeNull()
            ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(200);
    });

    // Given back after a body that had saved the key, the upload would be
    // pruned as unclaimed and the record left naming a file that is gone:
    // the body's writes roll back with the claim, so it is given back only
    // when nothing names it.
    it('rolls back what a failed body wrote before giving its upload back', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        Tinvar\Tests\Uploads\Fixtures\ProfileActions::$failAfterSave = true;
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(500)
            ->and($app->store->find($reference)?->claimedAt)->toBeNull()
            ->and($app->orm->connection()->select('SELECT avatar_key FROM profiles WHERE id = ?', [P1]))->toBe([['avatar_key' => null]]);
    });

    it('judges an upload in a validate-only call without claiming it', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        $valid = $app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference], headers: ['precognition' => 'true', 'precognition-validate-only' => 'avatar']);
        $invalid = $app->call('profiles.avatar', ['profile' => P2, 'avatar' => $reference], headers: ['precognition' => 'true', 'precognition-validate-only' => 'avatar']);

        expect($valid->status)->toBe(204)
            ->and($invalid->status)->toBe(422)
            ->and($app->store->find($reference)?->claimedAt)->toBeNull();
    });

    it('still runs its own authorization: a reference does not stand in for the record\'s check', function () {
        $app = new App(new MemoryStorage());
        $reference = uploadedPng($app);

        // u-2 may not update p-1, upload or no upload.
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference], user: 'u-2')->status)->toBe(403);
    });
});

describe('pruning', function () {
    it('removes what nobody claimed once it has expired, and never a claimed upload\'s file', function () {
        $app = new App($storage = new MemoryStorage());
        $claimed = uploadedPng($app);
        expect($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $claimed])->status)->toBe(200);
        $abandoned = uploadedPng($app);
        $fresh = null;

        $claimedKey = (string) $app->store->find($claimed)?->key;
        $abandonedKey = (string) $app->store->find($abandoned)?->key;

        // Not yet: expired, but within the grace a slow clock elsewhere is given.
        $app->now += Uploads::PENDING_TTL;
        expect($app->uploads->prune())->toBe(0);

        $app->now += Uploads::PRUNE_GRACE;
        $fresh = uploadedPng($app);
        expect($app->uploads->prune())->toBe(1)
            ->and($storage->contents($abandonedKey))->toBeNull()
            ->and($app->store->find($abandoned))->toBeNull()
            ->and($storage->contents($claimedKey))->not->toBeNull()
            ->and($app->store->find($fresh))->not->toBeNull();

        // A claim is remembered a day, then forgotten -- the file kept.
        $app->now += Uploads::CLAIM_RETENTION;
        $app->uploads->prune();
        expect($app->store->find($claimed))->toBeNull()
            ->and($storage->contents($claimedKey))->not->toBeNull();
    });

    it('is a scheduled task the application lists, given its Uploads by the container', function () {
        $app = new App(new MemoryStorage());
        $abandoned = uploadedPng($app);
        $app->now += Uploads::PENDING_TTL + Uploads::PRUNE_GRACE;

        $attribute = (new ReflectionClass(Tinvar\Uploads\PruneUploads::class))->getAttributes(Tinvar\Attributes\Schedule::class)[0]->newInstance();
        expect($attribute->expression)->toBe('*/15 * * * *');

        $task = new Tinvar\Uploads\PruneUploads();
        $task(($app->container->resolver())(Uploads::class));
        expect($app->store->find($abandoned))->toBeNull();
    });
});
