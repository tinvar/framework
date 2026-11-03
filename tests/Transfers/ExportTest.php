<?php

declare(strict_types=1);

/**
 * Exports end to end, through the Kernel: started at /_export, written by
 * the worker as the user who started it, and fetched at /_download by a
 * link bound to that user, their auth epoch, the tenant, the panel and the
 * file -- and every way of using one where it was not bound.
 */

use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;
use Tinvar\Tests\Transfers\Fixtures\App;
use Tinvar\Transfers\DownloadToken;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\Transfers;
use Tinvar\Transfers\TransferStatus;

use function Tinvar\Tests\Transfers\Fixtures\json;

use const Tinvar\Tests\Transfers\Fixtures\KEY;

require_once __DIR__ . '/Fixtures/app.php';

// A test of modules wired together -- here through the Kernel, which
// mounts the transfers' endpoints and runs their checks -- runs in the
// whole suite only, never in the module's own run (bin/module-alone, ADR
// 0114).
pest()->group('wired');

/** Starts the export, works the queue, and answers the record it made. */
function exported(App $app, array $body = []): TransferRecord
{
    $response = $app->export($body);
    expect($response->status)->toBe(202)
        ->and(json($response)['status'])->toBe('queued');
    expect($app->work())->toBe([]);

    return $app->record();
}

describe('starting an export', function () {
    it('records it as the user\'s, in their tenant and panel, and queues it as them', function () {
        $app = new App();
        $response = $app->export();

        expect($response->status)->toBe(202)
            ->and($response->header('cache-control'))->toBe('private, no-store');
        $record = $app->record();
        expect($record->id)->toBe(json($response)['transfer'])
            ->and($record->status)->toBe(TransferRecord::QUEUED)
            ->and($record->tenant)->toBe('acme')
            ->and($record->panel)->toBe('staff')
            ->and($record->user)->toBe('staff-1')
            ->and($record->format)->toBe('csv')
            ->and($app->queue->size(Transfers::QUEUE))->toBe(1);
    });

    it('refuses a user its #[Authorize] denies, and queues nothing', function () {
        $app = new App();

        expect($app->export(user: 'customer-1')->status)->toBe(403)
            ->and($app->store->all())->toBe([])
            ->and($app->queue->size(Transfers::QUEUE))->toBe(0);
    });

    it('refuses nobody signed in, a missing CSRF token, a GET, and a name nobody declared', function () {
        $app = new App();

        expect($app->export(headers: ['x-test-user' => ''])->status)->toBeIn([401, 403])
            ->and($app->export(headers: ['x-xsrf-token' => 'wrong'])->status)->toBe(419)
            ->and($app->export(name: 'secrets')->status)->toBe(404)
            ->and($app->store->all())->toBe([]);
    });

    it('takes only the formats and arguments it declares', function () {
        $app = new App();

        expect($app->export(['format' => 'xlsx'])->status)->toBe(422)
            ->and($app->export(['args' => ['secret' => 'x']])->status)->toBe(422)
            ->and($app->export(['args' => ['minStock' => 'lots']])->status)->toBe(422)
            ->and($app->store->all())->toBe([]);
    });

    it('caps how many one user may have waiting', function () {
        $app = new App();
        for ($i = 0; $i < 5; $i++) {
            expect($app->export()->status)->toBe(202);
        }

        expect($app->export()->status)->toBe(429);
    });
});

describe('the file', function () {
    it('holds the row class\'s columns and nothing else, the tenant\'s rows and no other\'s', function () {
        $app = new App();
        $record = exported($app);

        expect($record->status)->toBe(TransferRecord::DONE)
            ->and($record->rows)->toBe(3)
            // Under the tenant it was written for, apart from every other's.
            ->and($record->key)->toMatch('#^exports/_acme/[a-f0-9]{32}\.csv$#');
        $csv = (string) $app->storage->contents($record->key);

        expect($csv)->toStartWith("sku,name,stock\r\n")
            ->and($csv)->toContain('A-1,Anvil,3')
            ->and($csv)->not->toContain('secret')
            ->and($csv)->not->toContain('Globex');
    });

    it('defends every text cell a spreadsheet would read as a formula, and leaves numbers be', function () {
        $app = new App();
        $csv = (string) $app->storage->contents(exported($app)->key);

        // =HYPERLINK(...) and -Locked are text, and written as text.
        expect($csv)->toContain('B-2,"\'=HYPERLINK(""http://evil.example"",""click"")",5')
            ->and($csv)->toContain("LOCKED,'-Locked,7")
            ->and($csv)->not->toMatch('/(^|,)=HYPERLINK/m');
    });

    it('writes JSON lines when asked, with the same columns', function () {
        $app = new App();
        $record = exported($app, ['format' => 'jsonl']);
        $lines = explode("\n", trim((string) $app->storage->contents($record->key)));

        expect($record->key)->toEndWith('.jsonl')
            ->and(json_decode($lines[0], true))->toBe(['sku' => 'A-1', 'name' => 'Anvil', 'stock' => 3])
            ->and(\count($lines))->toBe(3);
    });

    it('reads the rows a page at a time, every one once, and stops at the most it declares', function () {
        $app = new App();
        $insert = static function (App $app, int $from, int $to): void {
            for ($i = $from; $i < $to; $i++) {
                $app->connection->execute(
                    'INSERT INTO widgets (id, tenant_id, sku, name, stock, secret) VALUES (?, ?, ?, ?, ?, ?)',
                    [\sprintf('0192f3a1-0000-7000-9000-%012d', $i), 'acme', \sprintf('P-%04d', $i), "Part {$i}", $i, 's'],
                );
            }
        };
        $insert($app, 0, 700);
        $record = exported($app);
        $csv = (string) $app->storage->contents($record->key);

        expect($record->rows)->toBe(703)
            ->and(substr_count($csv, "\r\n"))->toBe(704)
            ->and(substr_count($csv, 'P-0699,'))->toBe(1)
            ->and($app->orm->identityMap()->count())->toBeLessThan(10);

        $insert($app, 700, 1_000);
        $app->store->forget($record->id);
        $over = exported($app);
        expect($over->status)->toBe(TransferRecord::FAILED)
            ->and($over->failure)->toContain('more than 1000 rows')
            ->and($app->storage->keys())->toBe([$record->key]);
    });

    it('reads its arguments, coerced, in the job', function () {
        $app = new App();

        expect(exported($app, ['args' => ['minStock' => '5']])->rows)->toBe(2);
    });

    it('is not written when the user\'s epoch moved before the worker ran it', function () {
        $app = new App();
        expect($app->export()->status)->toBe(202);

        $app->users->epochs['staff-1'] = 'e2';

        // Failed on its record the moment the worker refused it, not an
        // hour on (ADR 0107).
        expect($app->work())->toBe(['epoch_changed'])
            ->and($app->storage->keys())->toBe([])
            ->and($app->record()->status)->toBe(TransferRecord::FAILED)
            ->and($app->record()->failure)->toBe('You may no longer run this export.')
            ->and($app->record()->finishedAt)->toBe($app->now);

        $app->auth->login(new Tinvar\Tests\Transfers\Fixtures\User('staff-1'), 'e2');
        $status = $app->orm->actAs('acme', fn (): array => $app->kernel->panels?->within('staff', fn (): array => $app->kernel->transferStatus?->recent() ?? []) ?? []);
        expect($status[0]->status)->toBe('failed');
    });

    it('is written in the tenant it was started in, whatever the worker last ran as', function () {
        $app = new App();
        expect($app->export(headers: ['x-test-tenant' => 'globex'])->status)->toBe(202);
        $app->orm->switchTenant('acme');
        $app->work();

        $csv = (string) $app->storage->contents($app->record()->key);
        expect($csv)->toContain('G-1,"Globex gear",11')
            ->and($csv)->not->toContain('Anvil');
    });

    it('tells the application, with a link on the canonical origin and never the request\'s host', function () {
        $app = new App();
        $response = $app->export(headers: ['host' => 'evil.example']);
        expect($response->status)->toBe(202);
        $app->work();

        expect($app->notes->finished)->toHaveCount(1);
        $link = (string) $app->notes->finished[0]['link'];
        expect($link)->toStartWith('https://shop.example/_download/')
            ->and($link)->not->toContain('evil.example');

        // The emailed link opens, in another browser, for the same user.
        expect($app->download(substr($link, \strlen('https://shop.example')))->status)->toBe(200);
    });

    // An export's #[Domain] is honoured as a page's is (ADR 0149): started
    // and downloaded on that host, and on no other.
    it('is started and downloaded only on the host its #[Domain] names', function () {
        $app = new App(domains: new \Tinvar\Routing\Domains(['root' => 'shop.example']));

        expect($app->export(headers: ['host' => 'shop.example'], name: 'hosted.widgets')->status)->toBe(404)
            ->and($app->export(headers: ['host' => 'admin.shop.example'], name: 'hosted.widgets')->status)->toBe(202);
        $app->work();
        $link = $app->linkFor($app->record());

        expect($app->download($link, headers: ['host' => 'shop.example'])->status)->toBe(404)
            ->and($app->download($link, headers: ['host' => 'admin.shop.example'])->status)->toBe(200);
    });

    it('emails no link where the application gave no origin', function () {
        $app = new App(origin: null);
        exported($app);

        expect($app->notes->finished[0]['link'])->toBeNull();
    });
});

describe('/_download', function () {
    it('sends the file to its user, as an attachment nothing runs', function () {
        $app = new App();
        $record = exported($app);
        $response = $app->download($app->linkFor($record));

        expect($response->status)->toBe(200)
            ->and($response->header('content-type'))->toBe('text/csv; charset=utf-8')
            ->and($response->header('content-disposition'))->toStartWith('attachment; filename="widgets-')
            ->and($response->header('x-content-type-options'))->toBe('nosniff')
            ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->body())->toBe($app->storage->contents($record->key));
    });

    // As /_file does: a HEAD answers the headers and reads nothing of the
    // file -- on an object store, read() fetches the whole object first.
    it('answers a HEAD with the file\'s headers, and reads nothing of it', function () {
        $app = new App();
        $record = exported($app);
        $response = $app->download($app->linkFor($record), method: 'HEAD');

        expect($response->status)->toBe(200)
            ->and($response->header('content-length'))->toBe((string) \strlen((string) $app->storage->contents($record->key)))
            ->and($response->body())->toBe('')
            ->and($app->reads)->toBe(0);
    });

    it('is a 404 for another user, whatever their rights', function () {
        $app = new App();
        $link = $app->linkFor(exported($app));

        expect($app->download($link, user: 'staff-2')->status)->toBe(404)
            ->and($app->download($link, user: 'customer-1')->status)->toBe(404);
    });

    it('is a 404 once the user\'s auth epoch has moved', function () {
        $app = new App();
        $link = $app->linkFor(exported($app));

        expect($app->download($link, epoch: 'e2')->status)->toBe(404)
            ->and($app->download($link, epoch: 'e1')->status)->toBe(200);
    });

    it('is a 404 in another tenant', function () {
        $app = new App();
        $link = $app->linkFor(exported($app));

        expect($app->download($link, tenant: 'globex')->status)->toBe(404);
    });

    it('is a 404 once the link has expired, or the file has', function () {
        $app = new App();
        $record = exported($app);
        $link = $app->linkFor($record);

        $app->now += DownloadToken::DEFAULT_TTL;
        expect($app->download($link)->status)->toBe(404);

        // A fresh link, until the file itself is gone.
        expect($app->download($app->linkFor($record))->status)->toBe(200);
        $app->now = $record->expiresAt;
        expect(fn () => $app->linkFor($record))->toThrow(LogicException::class)
            ->and($app->transfers->prune())->toBe(1)
            ->and($app->storage->keys())->toBe([]);
    });

    it('is a 404 for a token with any byte changed, one signed for another purpose, or naming another file', function () {
        $app = new App();
        $record = exported($app);
        $link = $app->linkFor($record);
        $token = substr($link, \strlen('/_download/'));
        $claims = DownloadToken::decode($token, $app->transfers->signer, $app->now);

        // Another file's key, under the right key and purpose: refused by the record.
        $other = new DownloadToken(...[...get_object_vars($claims), 'key' => 'exports/' . str_repeat('ab', 16) . '.csv']);
        // Another panel, re-signed: refused by the context.
        $panel = new DownloadToken(...[...get_object_vars($claims), 'panel' => '']);
        // The right claims under a fragment's key.
        $fragment = SignedToken::encode(new Signer(KEY, Tinvar\Render\FragmentToken::PURPOSE), DownloadToken::PURPOSE, [
            't' => $claims->transfer, 'x' => $claims->name, 'k' => $claims->key, 'y' => $claims->tenant, 'n' => $claims->panel,
            'u' => $claims->authHash, 'e' => $claims->expiresAt, 'i' => $claims->issuedAt,
        ]);

        foreach ([
            '/_download/' . substr($token, 0, -2) . 'AA',
            '/_download/' . $other->encode($app->transfers->signer),
            '/_download/' . $panel->encode($app->transfers->signer),
            '/_download/' . $fragment,
            '/_download/v1.e30.AAAA',
            '/_download/' . str_repeat('a', 5000),
        ] as $bad) {
            expect($app->download($bad)->status)->toBe(404);
        }
    });

    it('lists the viewer\'s own transfers, with a link minted for them', function () {
        $app = new App();
        exported($app);
        $app->export(user: 'staff-2');

        $app->auth->login(new Tinvar\Tests\Transfers\Fixtures\User('staff-1'), 'e1');
        $status = $app->kernel->transferStatus;
        expect($status)->toBeInstanceOf(TransferStatus::class);
        $mine = $app->orm->actAs('acme', fn (): array => $app->kernel->panels?->within('staff', fn (): array => $status?->recent() ?? []) ?? []);

        expect($mine)->toHaveCount(1)
            ->and($mine[0]->status)->toBe('done')
            ->and($mine[0]->rows)->toBe(3)
            ->and($mine[0]->download)->toStartWith('/_download/')
            ->and($app->download((string) $mine[0]->download)->status)->toBe(200);

        // Outside the panel, or in another tenant, it is not there.
        expect($app->orm->actAs('globex', fn (): array => $app->kernel->panels?->within('staff', fn (): array => $status?->recent() ?? []) ?? []))->toBe([])
            ->and($app->orm->actAs('acme', fn (): array => $status?->recent() ?? []))->toBe([]);
    });
});

// Review: a job that could not be queued left its record queued -- shown
// as waiting for an hour, and counted against the user's waiting starts.
it('fails the record of a transfer whose job could not be queued', function (string $kind) {
    $app = new App();
    $app->dispatchFails = true;

    $response = $kind === 'export' ? $app->export() : $app->import("widget,stock\n");
    expect($response->status)->toBe(500)
        ->and($app->record()->status)->toBe(TransferRecord::FAILED)
        ->and($app->record()->finishedAt)->toBe($app->now)
        ->and($app->storage->keys())->toBe([]);
})->with(['export', 'import']);
