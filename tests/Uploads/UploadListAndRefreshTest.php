<?php

declare(strict_types=1);

/**
 * Several files for one parameter (an UploadList), each held to its
 * ticket's binding as one upload is, and a ticket issued again for a page
 * that has outlived it -- for its own binding, a day from its first issue
 * at most (ADR 0103, ADR 0037).
 */

use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Tests\Uploads\Fixtures\ProfileActions;
use Tinvar\Uploads\UploadTicket;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\P3;

require_once __DIR__ . '/Fixtures/app.php';

/** @return list<string> a reference for each of $count PNGs uploaded under $ticket */
function uploaded(App $app, string $ticket, int $count): array
{
    $references = [];
    for ($i = 0; $i < $count; $i++) {
        $references[] = (string) json($app->upload($ticket, png(2 + $i)))['upload'];
    }

    return $references;
}

describe('an UploadList', function () {
    it('is handed every file its references name, in their order, each claimed', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1), action: 'profiles.photos', field: 'photos');
        $references = uploaded($app, $ticket, 3);

        $response = $app->call('profiles.photos', ['profile' => P1, 'photos' => $references]);

        expect($response->status)->toBe(200, $response->body())
            ->and(array_map(fn ($one) => $one->id, ProfileActions::$photos?->files ?? []))->toBe($references)
            ->and(array_map(fn (string $id) => $app->store->find($id)?->claimedAt !== null, $references))->toBe([true, true, true]);
    });

    it('is refused more files than declared, a reference twice, or a list holding one it may not have -- and claims none', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1), action: 'profiles.photos', field: 'photos');
        [$a, $b, $c] = uploaded($app, $ticket, 3);
        $avatar = (string) json($app->upload($app->ticket($app->profile(P1)), png()))['upload'];
        $other = (string) json($app->upload($app->ticket($app->profile(P3), user: 'u-2'), png(), user: 'u-2'))['upload'];

        foreach ([[$a, $b, $c, $c], [$a, $a], [$a, $avatar], [$a, $other], [$a, 7], 'not-a-list', ['x' => $a]] as $photos) {
            $response = $app->call('profiles.photos', ['profile' => P1, 'photos' => $photos]);
            expect($response->status)->toBe(422, json_encode($photos));
        }
        expect(array_map(fn (string $id) => $app->store->find($id)?->claimedAt, [$a, $b, $c, $avatar]))->toBe([null, null, null, null]);
    });

    it('is required unless optional, as one upload is', function () {
        $app = new App(new MemoryStorage());

        $response = $app->call('profiles.photos', ['profile' => P1, 'photos' => []]);

        expect($response->status)->toBe(422)
            ->and(json($response)['errors']['photos'][0] ?? null)->toBe('photos is required.');
    });
});

describe('/_upload/refresh', function () {
    it('issues the ticket again for another hour, keeping its first issue, and the new one uploads', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));
        $first = UploadTicket::decode($ticket, $app->uploads->ticketSigner, $app->now);

        // The page has been open past its ticket's hour.
        $app->now += UploadTicket::DEFAULT_TTL + 60;
        $response = $app->refresh($ticket);
        $renewed = (string) json($response)['ticket'];
        $claims = UploadTicket::decode($renewed, $app->uploads->ticketSigner, $app->now);

        expect($response->status)->toBe(200, $response->body())
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($app->upload($ticket, png())->status)->toBe(403)
            ->and($app->upload($renewed, png())->status)->toBe(201)
            ->and($claims->issuedAt)->toBe($first->issuedAt)
            ->and($claims->expiresAt)->toBe($app->now + UploadTicket::DEFAULT_TTL)
            ->and([$claims->action, $claims->field, $claims->targetId, $claims->sessionHash, $claims->authHash])
            ->toBe([$first->action, $first->field, $first->targetId, $first->sessionHash, $first->authHash]);
    });

    it('never carries a ticket past a day from its first issue', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));
        $issued = $app->now;

        $app->now = $issued + UploadTicket::MAX_TTL - 600;
        $renewed = (string) json($app->refresh($ticket))['ticket'];
        expect(UploadTicket::decode($renewed, $app->uploads->ticketSigner, $app->now)->expiresAt)->toBe($issued + UploadTicket::MAX_TTL);

        $app->now = $issued + UploadTicket::MAX_TTL;
        expect($app->refresh($renewed)->status)->toBe(403)
            ->and($app->refresh($ticket)->status)->toBe(403);
    });

    it('refuses another session, another user, a moved epoch, a forged ticket, and a record that is gone', function () {
        $app = new App(new MemoryStorage());
        $ticket = $app->ticket($app->profile(P1));

        expect($app->refresh($ticket, session: 'session-b')->status)->toBe(403)
            ->and($app->refresh($ticket, user: 'u-2')->status)->toBe(403)
            ->and($app->refresh($ticket, epoch: 'e2')->status)->toBe(403)
            ->and($app->refresh($ticket . 'x')->status)->toBe(403)
            ->and($app->refresh('')->status)->toBe(400);

        $app->orm->delete($app->profile(P1));
        expect($app->refresh($ticket)->status)->toBe(403);
    });
});
