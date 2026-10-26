<?php

declare(strict_types=1);

/**
 * The testing layer's fakes and clock: mail kept and never sent, a queue a
 * test can read and a worker can run, the live hub read by tag, a clock a
 * test moves -- each in place of the real one, behind the same interface.
 */

namespace Tinvar\Tests\Testing\Fakes;

use PHPUnit\Framework\AssertionFailedError;
use Tinvar\Data\Data;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Live\Invalidator;
use Tinvar\Live\Topics;
use Tinvar\Mail\Address;
use Tinvar\Mail\Email;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\SendMail;
use Tinvar\Support\Signer;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Fakes\SentMail;
use Tinvar\Testing\Fakes\StorageFake;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;
use Tinvar\Testing\TestClock;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Orm\Fixtures\Script;

use function Tinvar\Testing\expectQueries;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

final readonly class NoticeData extends Data
{
    public function __construct(public string $script) {}
}

final readonly class Notice implements Mailable
{
    public function __construct(public string $to, public string $script) {}

    public function email(): Email
    {
        return new Email(new Address($this->to), "Script {$this->script} ran", 'notice', new NoticeData($this->script));
    }
}

it('keeps what the application sends, from which template and props, and sends nothing', function () {
    $mail = new MailFake();
    $mailer = new Mailer($mail, $mail, new Address('ops@shop.test'));

    $mailer->send(new Notice('ada@example.test', 'nightly'));

    $mail->assertSentCount(1)
        ->assertSent('notice', fn (SentMail $sent): bool => $sent->props === ['script' => 'nightly'] && $sent->message->subject === 'Script nightly ran')
        ->assertSentTo('ADA@example.test', 'notice')
        ->assertNotSent('receipt');

    expect(fn () => $mail->assertSentTo('bob@example.test'))->toThrow(AssertionFailedError::class, "Sent: 'notice' to ada@example.test")
        ->and(fn () => $mail->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('reads a pushed job as a worker would open it, rebuilds it, and lets a worker run it once the clock reaches it', function () {
    $h = new Harness();
    $clock = new TestClock(1_800_000_000);
    $queue = new QueueFake($h->signer, $h->orm);
    $dispatcher = new Dispatcher($queue, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry, $clock->seconds());
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));

    $h->orm->actAs('acme', fn () => $dispatcher->dispatch(new RecordContext($script, 'later'), delay: 60));

    $queue->assertPushedCount(1)
        ->assertPushed(RecordContext::class, fn (RecordContext $job, Envelope $envelope): bool => $job->script?->id === $script->id
            && $job->note === 'later' && $envelope->tenant === 'acme')
        ->assertNotPushed(SendMail::class);

    // Not before its delay; then the clock moves and it is there.
    expect($queue->reserve(['default'], $clock->now(), 60))->toBeNull();
    $clock->travel(1)->minutes();
    expect($queue->reserve(['default'], $clock->now(), 60)?->queue)->toBe('default');
});

it('refuses a payload sealed under another key, as a worker would', function () {
    $h = new Harness();
    $queue = new QueueFake(new Signer('another-key-of-thirty-two-bytes!!!', Envelope::PURPOSE), $h->orm);
    $dispatcher = new Dispatcher($queue, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry);

    expect(fn () => $h->orm->actAs('acme', fn () => $dispatcher->dispatch(new RecordContext(null, 'x'))))
        ->toThrow(\Tinvar\Jobs\JobRefused::class);
});

// The hub hears of a write once it commits, and never of one rolled back.
it('reads what went out to the hub by tag, and nothing for a write rolled back', function () {
    $h = new Harness();
    $hub = new HubFake();
    $invalidator = new Invalidator($hub, new Topics(new Signer('topics-key-of-thirty-two-bytes!!!!', Topics::PURPOSE)), $h->orm);

    $h->orm->actAs('acme', fn () => $h->orm->transaction(fn () => $invalidator->invalidate(['scripts', 'script:1'])));
    try {
        $h->orm->actAs('acme', fn () => $h->orm->transaction(function () use ($invalidator): void {
            $invalidator->invalidate(['never']);

            throw new \RuntimeException('rolled back');
        }));
    } catch (\RuntimeException) {
    }

    $hub->assertInvalidated('scripts', 'script:1')->assertNotInvalidated('never');
    expect($hub->tags())->toBe(['scripts', 'script:1'])
        ->and(fn () => $hub->assertNothingPublished())->toThrow(AssertionFailedError::class);
});

/** @return resource */
function stream(string $bytes): mixed
{
    $stream = fopen('php://memory', 'w+b');
    \assert($stream !== false);
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('keeps what is stored, and says what is at a key, how many, which bytes, under which prefix', function () {
    $storage = new StorageFake();
    $storage->assertNothingStored();
    $avatar = StorageKey::generate('avatars', 'png');
    $receipt = StorageKey::generate('receipts', 'jpg');

    $storage->put($avatar, stream('png bytes'));
    $storage->put($receipt, stream('jpg bytes'));
    $storage->delete($receipt);

    $storage->assertExists($avatar)
        ->assertMissing($receipt)
        ->assertCount(1)
        ->assertCount(1, 'avatars')
        ->assertCount(0, 'receipts')
        ->assertContents($avatar, 'png bytes')
        ->assertContents($avatar, fn (string $bytes): bool => str_starts_with($bytes, 'png'))
        ->assertStoredUnder('avatars')
        ->assertStoredUnder('avatars', $avatar);

    expect($storage->keys('avatars'))->toBe([$avatar])
        ->and(stream_get_contents($storage->read($avatar) ?? throw new \LogicException('nothing read')))->toBe('png bytes')
        ->and($storage->size($avatar))->toBe(9)
        ->and(fn () => $storage->assertExists($receipt))->toThrow(AssertionFailedError::class, "Stored: {$avatar}")
        ->and(fn () => $storage->assertMissing($avatar))->toThrow(AssertionFailedError::class)
        ->and(fn () => $storage->assertCount(2))->toThrow(AssertionFailedError::class, '1 files are stored, not 2')
        ->and(fn () => $storage->assertContents($avatar, 'other bytes'))->toThrow(AssertionFailedError::class, 'holds other bytes')
        ->and(fn () => $storage->assertStoredUnder('receipts'))->toThrow(AssertionFailedError::class, "No file is stored under 'receipts'")
        ->and(fn () => $storage->assertStoredUnder('avatar', $avatar))->toThrow(AssertionFailedError::class, "not stored under 'avatar'")
        ->and(fn () => $storage->assertNothingStored())->toThrow(AssertionFailedError::class);
});

// Held to the keys the real stores take, so a test cannot pass with a path
// LocalStorage would refuse.
it('refuses a key the real stores refuse', function () {
    $storage = new StorageFake();

    expect(fn () => $storage->put('../escape.png', stream('x')))->toThrow(StorageException::class)
        ->and(fn () => $storage->assertExists('/etc/passwd'))->toThrow(StorageException::class)
        ->and(fn () => $storage->assertStoredUnder('../avatars'))->toThrow(\InvalidArgumentException::class);
});

it('moves every part built on the clock together, frozen until the test says', function () {
    $clock = new TestClock(new \DateTimeImmutable('2027-01-15 08:00:00 UTC'));
    $store = new ArrayThrottleStore($clock->seconds());

    expect($clock->now())->toBe(1_800_000_000)
        ->and($clock->date()->format('Y-m-d H:i'))->toBe('2027-01-15 08:00')
        ->and(($clock->floats())())->toBe(1_800_000_000.0);

    $store->increment('k', 60);
    $store->increment('k', 60);
    expect($store->increment('k', 60)[0])->toBe(3);

    $clock->travel(61)->seconds();
    expect($store->increment('k', 60)[0])->toBe(1, 'a new window');

    $clock->travel(2)->days();
    $clock->travel(-1)->hours();
    expect(($clock->dates())()->format('Y-m-d H:i'))->toBe('2027-01-17 07:01');

    $clock->travelTo(1_900_000_000);
    expect(($clock->seconds())())->toBe(1_900_000_000);
});

it('counts the statements a piece of work issues, and names them when the count is wrong', function () {
    $h = new Harness();
    $connection = $h->orm->connection();

    expectQueries(1, fn () => $h->orm->actAs('acme', fn () => $h->orm->all(Script::class)), $connection);
    expect(fn () => expectQueries(0, fn () => $h->orm->actAs('acme', fn () => $h->orm->all(Script::class)), $connection))
        ->toThrow(AssertionFailedError::class, 'SELECT');
});
