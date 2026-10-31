<?php

declare(strict_types=1);

/**
 * The pieces of the upload flow on their own: the ticket's envelope and
 * claims, sniffing, the client's filename, what ActionRegistry refuses to
 * compile, and the store's claim.
 */

namespace Tinvar\Tests\Uploads\Parts;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Uploaded;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Tests\Uploads\Fixtures\Profile;
use Tinvar\Uploads\ArrayUploadStore;
use Tinvar\Uploads\DatabaseUploadStore;
use Tinvar\Storage\FileTypes;
use Tinvar\Uploads\PendingUpload;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadName;
use Tinvar\Uploads\UploadStore;
use Tinvar\Uploads\UploadTicket;

use function Tinvar\Tests\Uploads\Fixtures\jpeg;
use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

require_once __DIR__ . '/Fixtures/app.php';

const PARTS_KEY = 'upload-parts-test-application-key-0123456789';

function ticketAt(int $issuedAt, int $expiresAt): UploadTicket
{
    return new UploadTicket(
        action: 'profiles.avatar',
        field: 'avatar',
        targetClass: Profile::class,
        targetId: 'p',
        prefix: 'avatars',
        tenant: '',
        panel: '',
        sessionHash: 's',
        authHash: 'u',
        expiresAt: $expiresAt,
        issuedAt: $issuedAt,
    );
}

describe('an upload ticket', function () {
    $signer = new Signer(PARTS_KEY, UploadTicket::PURPOSE);

    it('round-trips every claim', function () use ($signer) {
        $ticket = ticketAt(1000, 2000);

        expect(UploadTicket::decode($ticket->encode($signer), $signer, 1500))->toEqual($ticket);
    });

    it('is refused expired, expiring further out than any is minted for, or with no issue time', function () use ($signer) {
        expect(fn () => UploadTicket::decode(ticketAt(1000, 2000)->encode($signer), $signer, 2000))->toThrow(TokenError::class, 'expired')
            ->and(fn () => UploadTicket::decode(ticketAt(1000, 1000 + UploadTicket::MAX_TTL + 1)->encode($signer), $signer, 1500))->toThrow(TokenError::class, 'further out')
            ->and(fn () => UploadTicket::decode(ticketAt(0, 2000)->encode($signer), $signer, 1500))->toThrow(TokenError::class, 'issue time');
    });

    it('is refused signed by another key or for another purpose, even with the same claims', function () use ($signer) {
        $other = new Signer('another-application-key-entirely-0123456789', UploadTicket::PURPOSE);
        $fragment = new Signer(PARTS_KEY, FragmentToken::PURPOSE);
        $claims = ['a' => 'profiles.avatar', 'f' => 'avatar', 'm' => Profile::class, 'k' => 'p', 'x' => 'avatars', 'y' => '', 'n' => '', 's' => 's', 'u' => 'u', 'e' => 2000, 'i' => 1000];

        expect(fn () => UploadTicket::decode(ticketAt(1000, 2000)->encode($other), $signer, 1500))->toThrow(TokenError::class)
            ->and(fn () => UploadTicket::decode(SignedToken::encode($signer, FragmentToken::PURPOSE, $claims), $signer, 1500))->toThrow(TokenError::class)
            ->and(fn () => UploadTicket::decode(SignedToken::encode($fragment, UploadTicket::PURPOSE, $claims), $signer, 1500))->toThrow(TokenError::class);
    });

    it('is refused with a claim missing or of the wrong type', function (string $field, mixed $value) use ($signer) {
        $claims = ['a' => 'profiles.avatar', 'f' => 'avatar', 'm' => Profile::class, 'k' => 'p', 'x' => 'avatars', 'y' => '', 'n' => '', 's' => 's', 'u' => 'u', 'e' => 2000, 'i' => 1000];
        $claims[$field] = $value;

        expect(fn () => UploadTicket::decode(SignedToken::encode($signer, UploadTicket::PURPOSE, $claims), $signer, 1500))->toThrow(TokenError::class);
    })->with([
        'no action' => ['a', ''],
        'a numeric target' => ['k', 7],
        'no prefix' => ['x', null],
        'no session' => ['s', ''],
        'no user' => ['u', ''],
        'a string expiry' => ['e', '2000'],
    ]);
});

describe('sniffing', function () {
    it('names each recognised type from its bytes alone', function () {
        foreach (['png' => [png(), 'image/png'], 'jpeg' => [jpeg(), 'image/jpeg'], 'pdf' => [pdf(), 'application/pdf']] as $name => [$bytes, $type]) {
            $path = tempnam(sys_get_temp_dir(), 'tinvar-sniff-');
            file_put_contents($path, $bytes);
            expect(FileTypes::sniffFile($path))->toBe($type, $name);
            unlink($path);
        }

        expect(FileTypes::sniff('GIF89a' . str_repeat("\0", 10)))->toBe('image/gif')
            ->and(FileTypes::sniff("RIFF\0\0\0\0WEBPVP8 "))->toBe('image/webp');
    });

    it('recognises nothing a browser would run, whatever it starts with', function (string $bytes) {
        expect(FileTypes::sniff($bytes))->toBeNull();
    })->with([
        'html' => ['<!doctype html><script>'],
        'svg' => ['<svg xmlns="http://www.w3.org/2000/svg">'],
        'xml' => ['<?xml version="1.0"?>'],
        'javascript' => ['alert(document.cookie)'],
        'a PNG signature with no IHDR after it' => ["\x89PNG\r\n\x1a\n<script>alert"],
        'a RIFF that is not a WebP' => ["RIFF\0\0\0\0AVI LIST"],
        'nothing' => [''],
    ]);

    it('refuses an image whose header does not read as that image', function () {
        $path = tempnam(sys_get_temp_dir(), 'tinvar-sniff-');
        // A JPEG's first bytes, and nothing an image reader can use.
        file_put_contents($path, "\xFF\xD8\xFF" . str_repeat('<html>', 20));
        expect(FileTypes::sniffFile($path))->toBeNull();
        unlink($path);
    });
});

describe('a client\'s filename', function () {
    it('is kept as its last segment, without what would forge a header or a log line', function (string $given, string $kept) {
        expect(UploadName::clean($given))->toBe($kept);
    })->with([
        'a plain name' => ['receipt.jpg', 'receipt.jpg'],
        'traversal' => ['../../etc/passwd', 'passwd'],
        'a windows path' => ['C:\\Users\\me\\..\\receipt.jpg', 'receipt.jpg'],
        'control characters' => ["a\r\nset-cookie: x\0.png", 'aset-cookie: x.png'],
        'a right-to-left override' => ["photo\u{202E}gpj.exe", 'photogpj.exe'],
        'not UTF-8' => ["r\xFF\xFEceipt.png", 'r??ceipt.png'],
        'only dots' => ['..', 'upload'],
        'nothing' => ['', 'upload'],
    ]);

    it('is cut to 255 bytes without splitting a character', function () {
        $name = UploadName::clean(str_repeat('é', 200) . '.png');

        expect(strlen($name))->toBeLessThanOrEqual(255)
            ->and(mb_check_encoding($name, 'UTF-8'))->toBeTrue();
    });
});

final class NoDeclaration
{
    #[Action(name: 'bad.none'), Authorize('update', 'profile')]
    public function run(Profile $profile, Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class DeclaredOnAString
{
    #[Action(name: 'bad.string'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'a', types: ['image/png'])] string $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class AttachedToNothing
{
    #[Action(name: 'bad.target'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'album', prefix: 'a', types: ['image/png'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class AttachedToAnOptionalRecord
{
    #[Action(name: 'bad.optional'), Authorize('update', 'profile')]
    public function run(?Profile $profile, #[Uploaded(to: 'profile', prefix: 'a', types: ['image/png'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class AnUnsafePrefix
{
    #[Action(name: 'bad.prefix'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: '../avatars', types: ['image/png'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class AnUnknownType
{
    #[Action(name: 'bad.type'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'a', types: ['image/svg+xml'])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class NoTypes
{
    #[Action(name: 'bad.notypes'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'a', types: [])] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

final class NoBytes
{
    #[Action(name: 'bad.bytes'), Authorize('update', 'profile')]
    public function run(Profile $profile, #[Uploaded(to: 'profile', prefix: 'a', types: ['image/png'], maxBytes: 0)] Upload $file): ActionResult
    {
        return ActionResult::ok();
    }
}

describe('ActionRegistry', function () {
    it('refuses an upload parameter it could not hold an upload to', function (string $class, string $message) {
        expect(fn () => (new ActionRegistry())->register($class))->toThrow(ActionException::class, $message);
    })->with([
        'no #[Uploaded]' => [NoDeclaration::class, 'declares no #[Uploaded]'],
        '#[Uploaded] on a string' => [DeclaredOnAString::class, 'is not typed Upload'],
        'attached to no parameter' => [AttachedToNothing::class, 'not a required model parameter'],
        'attached to an optional record' => [AttachedToAnOptionalRecord::class, 'not a required model parameter'],
        'a prefix with a traversal' => [AnUnsafePrefix::class, 'is not one a storage key can carry'],
        'a type uploads do not take' => [AnUnknownType::class, 'do not recognise'],
        'no types' => [NoTypes::class, 'names no types'],
        'no bytes' => [NoBytes::class, 'which is none'],
    ]);

    it('records what each upload parameter takes, and whether it may be left out', function () {
        $registry = new ActionRegistry();
        $registry->register(\Tinvar\Tests\Uploads\Fixtures\ProfileActions::class);

        $avatar = $registry->get('profiles.avatar');
        $document = $registry->get('profiles.document');

        expect($avatar->uploads['avatar']->prefix)->toBe('avatars')
            ->and($avatar->uploads['avatar']->types)->toBe(['image/png', 'image/jpeg'])
            ->and($avatar->optionalUploads)->toBe([])
            ->and($document->optionalUploads)->toBe(['document' => true]);
    });
});

dataset('upload stores', [
    'array' => [fn (): UploadStore => new ArrayUploadStore()],
    'database' => [function (): UploadStore {
        $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:', 'test');
        DatabaseUploadStore::define(new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));

        return new DatabaseUploadStore($connection);
    }],
]);

function pendingUpload(string $id, int $expiresAt, string $owner = 'u', string $epoch = 'e1'): PendingUpload
{
    return new PendingUpload($id, "avatars/{$id}.png", 'a', 'f', 'C', 't', 'avatars', '', '', 's', "{$owner}@{$epoch}", 'n.png', 'image/png', 3, 1000, $expiresAt, ownerHash: $owner);
}

describe('an upload store', function () {
    it('claims an upload once, only unexpired, and gives a claim back', function (\Closure $make) {
        /** @var UploadStore $store */
        $store = $make();
        $store->add(pendingUpload(str_repeat('a', 32), 2000));

        expect($store->claim(str_repeat('a', 32), 1500))->toBeTrue()
            ->and($store->claim(str_repeat('a', 32), 1500))->toBeFalse()
            ->and($store->find(str_repeat('a', 32))?->claimedAt)->toBe(1500);

        $store->release(str_repeat('a', 32));
        expect($store->find(str_repeat('a', 32))?->claimedAt)->toBeNull()
            ->and($store->claim(str_repeat('a', 32), 2000))->toBeFalse()
            ->and($store->claim('nope', 1500))->toBeFalse();
    })->with('upload stores');

    it('counts what one owner has waiting, and lists what expired unclaimed, oldest first', function (\Closure $make) {
        $store = $make();
        $store->add(pendingUpload(str_repeat('a', 32), 3000));
        // The same owner after a sign-in moved their epoch: still theirs.
        $store->add(pendingUpload(str_repeat('b', 32), 2000, epoch: 'e2'));
        $store->add(pendingUpload(str_repeat('c', 32), 2500, owner: 'someone else'));
        $store->add(pendingUpload(str_repeat('d', 32), 1800));
        $store->claim(str_repeat('d', 32), 1700);

        expect($store->pendingCount('u', 1500))->toBe(2)
            ->and(array_map(fn (PendingUpload $u): string => $u->id, $store->expired(2600, 10)))->toBe([str_repeat('b', 32), str_repeat('c', 32)])
            ->and($store->forgetClaimedBefore(1701))->toBe(1)
            ->and($store->find(str_repeat('d', 32)))->toBeNull();

        $store->forget(str_repeat('b', 32));
        expect($store->find(str_repeat('b', 32)))->toBeNull();
    })->with('upload stores');

    it('adds within the cap only, counting the owner alone', function (\Closure $make) {
        /** @var UploadStore $store */
        $store = $make();
        $store->add(pendingUpload(str_repeat('a', 32), 3000));
        $store->add(pendingUpload(str_repeat('c', 32), 3000, owner: 'someone else'));

        expect($store->addWithin(pendingUpload(str_repeat('b', 32), 3000), 2, 1500))->toBeTrue()
            ->and($store->addWithin(pendingUpload(str_repeat('d', 32), 3000), 2, 1500))->toBeFalse()
            ->and($store->find(str_repeat('d', 32)))->toBeNull()
            // Expired, an upload still counts: its file is kept until it is
            // pruned, and only then is its place given back.
            ->and($store->addWithin(pendingUpload(str_repeat('d', 32), 4000), 2, 3000))->toBeFalse();
        $store->forget(str_repeat('a', 32));
        expect($store->addWithin(pendingUpload(str_repeat('d', 32), 4000), 2, 3000))->toBeTrue();
    })->with('upload stores');

    it('holds an owner to a quota of bytes, claimed and waiting, and forgets a file by its key', function (\Closure $make) {
        /** @var UploadStore $store */
        $store = $make();
        // 3 bytes each: one waiting, one claimed, one expired and not yet
        // pruned -- its file still kept -- and one someone else's.
        $store->add(pendingUpload(str_repeat('a', 32), 3000));
        $store->add(pendingUpload(str_repeat('b', 32), 3000));
        $store->claim(str_repeat('b', 32), 1000);
        $store->add(pendingUpload(str_repeat('c', 32), 1200));
        $store->add(pendingUpload(str_repeat('d', 32), 3000, owner: 'someone else'));

        expect($store->bytesHeld('u', 1500))->toBe(9)
            ->and($store->addWithin(pendingUpload(str_repeat('e', 32), 3000), 20, 1500, quotaBytes: 11))->toBeFalse()
            ->and($store->addWithin(pendingUpload(str_repeat('e', 32), 3000), 20, 1500, quotaBytes: 12))->toBeTrue()
            ->and($store->bytesHeld('u', 1500))->toBe(12);

        $store->forgetKey('avatars/' . str_repeat('b', 32) . '.png');
        $store->forget(str_repeat('c', 32));
        expect($store->find(str_repeat('b', 32)))->toBeNull()
            ->and($store->bytesHeld('u', 1500))->toBe(6);
    })->with('upload stores');

    it('gives a table made before it looked uploads up by key the index, once', function () {
        $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:', 'test');
        $schema = new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));
        DatabaseUploadStore::define($schema);
        $connection->execute('DROP INDEX uploads_key');
        $indexes = static fn (): array => array_column($connection->select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'uploads_key'"), 'name');

        expect($indexes())->toBe([]);
        DatabaseUploadStore::indexKeys($schema);
        DatabaseUploadStore::indexKeys($schema);
        expect($indexes())->toBe(['uploads_key']);
    });

    // Counted and added apart, uploads sent at once each found room and were
    // all kept. Processes of their own, each with its own connection to one
    // file, as workers are.
    it('keeps no more than the cap of uploads sent at once', function () {
        if (!\function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is not available.');
        }
        $path = sys_get_temp_dir() . '/tinvar-uploads-race-' . bin2hex(random_bytes(4)) . '.sqlite';
        $connection = \Tinvar\Orm\PdoConnection::sqlite($path, 'test', create: true);
        DatabaseUploadStore::define(new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));
        $start = microtime(true) + 0.3;

        $pids = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $mine = new DatabaseUploadStore(\Tinvar\Orm\PdoConnection::sqlite($path, 'test'));
                time_sleep_until($start);
                for ($j = 0; $j < 3; $j++) {
                    $mine->addWithin(pendingUpload(bin2hex(random_bytes(16)), 3000), 5, 1500);
                }
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        try {
            expect(new DatabaseUploadStore($connection)->pendingCount('u', 1500))->toBe(5);
        } finally {
            foreach (glob($path . '*') ?: [] as $file) {
                @unlink($file);
            }
        }
    });

    it('brings a table made before uploads had an owner up to date, keeping its rows', function () {
        $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:', 'test');
        $schema = new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));

        // As define() made it when the cap counted the user binding.
        $schema->create('uploads', static function (\Tinvar\Orm\Schema\Table $uploads): void {
            $uploads->string('id', 64)->primary();
            $uploads->string('storage_key', 255);
            $uploads->string('action', 191);
            $uploads->string('field', 64);
            $uploads->string('target_class', 255);
            $uploads->string('target_id', 191);
            $uploads->string('prefix', 191);
            $uploads->string('tenant', 191);
            $uploads->string('panel', 64);
            $uploads->string('session_hash', 64);
            $uploads->string('auth_hash', 64);
            $uploads->string('name', 255);
            $uploads->string('type', 64);
            $uploads->bigInteger('size');
            $uploads->bigInteger('created_at');
            $uploads->bigInteger('expires_at');
            $uploads->bigInteger('claimed_at')->nullable();
            $uploads->index(['claimed_at', 'expires_at'], 'uploads_unclaimed');
            $uploads->index(['auth_hash'], 'uploads_owner');
        });
        $connection->execute(
            "INSERT INTO uploads VALUES (?, 'avatars/x.png', 'a', 'f', 'C', 't', 'avatars', '', '', 's', 'u@e1', 'n.png', 'image/png', 3, 1000, 3000, NULL)",
            [str_repeat('a', 32)],
        );

        DatabaseUploadStore::upgrade($schema);
        DatabaseUploadStore::upgrade($schema);

        $store = new DatabaseUploadStore($connection);
        $store->add(pendingUpload(str_repeat('b', 32), 3000));

        $indexes = array_column($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name = 'uploads_owner'"), 'sql');
        expect($store->find(str_repeat('a', 32))?->ownerHash)->toBe('')
            ->and($store->pendingCount('u', 1500))->toBe(1)
            ->and($indexes)->toHaveCount(1)
            ->and((string) $indexes[0])->toContain('owner_hash');
    });
});
