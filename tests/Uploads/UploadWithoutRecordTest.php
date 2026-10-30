<?php

declare(strict_types=1);

/**
 * An upload for a record not yet created (ADR 0127): a parameter declared
 * `#[Uploaded(to: null)]`, its ticket naming no record, its upload bound to
 * the action and parameter, the session, the user at their epoch, the
 * tenant and the panel -- and to no record, which nothing can stand in for.
 */

use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Tests\Uploads\Fixtures\ProfileActions;
use Tinvar\Uploads\UploadTicket;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\P1;

require_once __DIR__ . '/Fixtures/app.php';

function newProfileTicket(App $app, string $user = 'u-1', string $session = 'session-a'): string
{
    return $app->ticket(null, user: $user, session: $session, action: 'profiles.create', field: 'avatar');
}

it('takes a file under a ticket naming no record, for the action that makes one', function () {
    $app = new App(new MemoryStorage());
    $upload = $app->upload(newProfileTicket($app), png());
    expect($upload->status)->toBe(201);
    $reference = (string) json($upload)['upload'];

    $called = $app->call('profiles.create', ['avatar' => $reference]);

    expect($called->status)->toBe(200)
        ->and(ProfileActions::$received?->id)->toBe($reference)
        ->and($app->store->find($reference)?->targetClass)->toBe('')
        ->and($app->store->find($reference)?->targetId)->toBe('');
});

it('is issued for no record where the parameter names none, and for one only where it does', function () {
    $app = new App(new MemoryStorage());

    expect(fn () => $app->ticket($app->profile(P1), action: 'profiles.create', field: 'avatar'))->toThrow(LogicException::class, 'to: null')
        ->and(fn () => $app->ticket(null))->toThrow(LogicException::class, 'asked for none');
});

it('is still the session\'s, the user\'s and the action\'s alone', function () {
    $app = new App(new MemoryStorage());
    $ticket = newProfileTicket($app);

    expect($app->upload($ticket, png(), user: 'u-2')->status)->toBe(403)
        ->and($app->upload($ticket, png(), session: 'session-b')->status)->toBe(403);

    $reference = (string) json($app->upload($ticket, png()))['upload'];
    expect($app->call('profiles.create', ['avatar' => $reference], user: 'u-2')->status)->toBe(422)
        ->and($app->call('profiles.create', ['avatar' => $reference], session: 'session-b')->status)->toBe(422)
        // Nor does it stand in for a record-bound one, or one for it.
        ->and($app->call('profiles.avatar', ['profile' => P1, 'avatar' => $reference])->status)->toBe(422);

    $bound = (string) json($app->upload($app->ticket($app->profile(P1)), png()))['upload'];
    expect($app->call('profiles.create', ['avatar' => $bound])->status)->toBe(422);
});

it('refuses a ticket whose record does not agree with the declaration, either way round', function () {
    $app = new App(new MemoryStorage());
    $real = UploadTicket::decode(newProfileTicket($app), $app->uploads->ticketSigner, $app->now);
    $bound = UploadTicket::decode($app->ticket($app->profile(P1)), $app->uploads->ticketSigner, $app->now);

    // Signed with the real key: what is checked is the declaration, not the MAC.
    $withRecord = new UploadTicket(...[...get_object_vars($real), 'targetClass' => Tinvar\Tests\Uploads\Fixtures\Profile::class, 'targetId' => P1])->encode($app->uploads->ticketSigner);
    $withId = new UploadTicket(...[...get_object_vars($real), 'targetId' => P1])->encode($app->uploads->ticketSigner);
    $withoutRecord = new UploadTicket(...[...get_object_vars($bound), 'targetClass' => '', 'targetId' => ''])->encode($app->uploads->ticketSigner);

    expect($app->upload($withRecord, png())->status)->toBe(403)
        ->and($app->upload($withId, png())->status)->toBe(403)
        ->and($app->upload($withoutRecord, png())->status)->toBe(403);
});

it('is issued again for a page that has outlived it, with no record to find', function () {
    $app = new App(new MemoryStorage());
    $ticket = newProfileTicket($app);
    $app->now += UploadTicket::DEFAULT_TTL + 10;

    $refreshed = $app->refresh($ticket);
    expect($refreshed->status)->toBe(200)
        ->and($app->upload((string) json($refreshed)['ticket'], png())->status)->toBe(201);
});
