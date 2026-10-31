<?php

declare(strict_types=1);

/**
 * What became of a send, and who it was not sent to: a transport's
 * message id carried back through the Mailer, the suppression list asked
 * before every send by the message's category, and a refusal of the
 * message itself failing its queued job at once rather than retrying it.
 */

namespace Tinvar\Tests\Mail\Suppression;

use Tinvar\Data\Data;
use Tinvar\Jobs\JobRefused;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArraySuppressions;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\DatabaseSuppressions;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\MailCategory;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\SendMail;
use Tinvar\Mail\SentMessage;
use Tinvar\Mail\Suppression;
use Tinvar\Mail\SuppressionReason;
use Tinvar\Mail\Suppressions;
use Tinvar\Mail\Transport;
use Tinvar\Mail\Unsubscribe;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;

final readonly class NoteData extends Data
{
    public function __construct(public string $line) {}
}

/** A note to whoever it is given, of whichever category. */
final readonly class Note implements Mailable
{
    /** @param list<Address> $to */
    public function __construct(public array $to, public ?MailCategory $category = null, public bool $marketing = false, public array $cc = []) {}

    public function email(): Email
    {
        return new Email(
            $this->to,
            'A note',
            'note',
            new NoteData('Hello'),
            unsubscribe: $this->marketing ? new Unsubscribe('https://shop.test/u/1') : null,
            cc: $this->cc,
            category: $this->category,
            tags: ['note'],
            metadata: ['campaign' => 'autumn-2026'],
        );
    }
}

final class PlainRenderer implements MailRenderer
{
    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        return new RenderedMail('<p>Hello</p>', 'Hello');
    }
}

/** A transport written before Delivers: it says nothing back. */
final class Quiet implements Transport
{
    public int $sent = 0;

    public function send(Message $message): void
    {
        $this->sent++;
    }
}

function mailer(Transport $transport, ?Suppressions $suppressions = null): Mailer
{
    return new Mailer($transport, new PlainRenderer(), new Address('shop@shop.test'), suppressions: $suppressions);
}

function suppressed(SuppressionReason ...$reasons): ArraySuppressions
{
    $list = new ArraySuppressions();
    $emails = ['bounced@example.test', 'complained@example.test', 'manual@example.test'];
    foreach ($reasons as $i => $reason) {
        $list->suppress(new Suppression($emails[$i], $reason, 'resend', 1_800_000_000));
    }

    return $list;
}

it('carries a transport\'s message id back through the mailer, and sends through a transport that says nothing as before', function () {
    $array = new ArrayTransport();
    $sent = mailer($array)->deliver(new Note([new Address('ada@example.test')]));

    expect($sent->messageId)->toBe('array-1')
        ->and($sent->transport)->toBe('array')
        ->and($sent->skipped)->toBeFalse()
        ->and($sent->message->tags)->toBe(['note'])
        ->and($sent->message->metadata)->toBe(['campaign' => 'autumn-2026'])
        ->and(mailer($array)->send(new Note([new Address('ada@example.test')])))->toBeInstanceOf(Message::class);

    $quiet = new Quiet();
    $through = mailer($quiet)->deliver(new Note([new Address('ada@example.test')]));
    expect($quiet->sent)->toBe(1)->and($through->messageId)->toBeNull()->and($through->transport)->toBe('quiet');
});

it('says mail with an unsubscribe is marketing and the rest transactional, unless it says otherwise', function () {
    expect(new Note([new Address('a@example.test')], marketing: true)->email()->category)->toBe(MailCategory::Marketing)
        ->and(new Note([new Address('a@example.test')])->email()->category)->toBe(MailCategory::Transactional)
        ->and(new Note([new Address('a@example.test')], MailCategory::Essential, marketing: true)->email()->category)->toBe(MailCategory::Essential);
});

it('sends marketing mail to nobody on the list, transactional mail to nobody who bounced, and essential mail to everyone', function () {
    $list = suppressed(SuppressionReason::Bounce, SuppressionReason::Complaint, SuppressionReason::Manual);
    $readers = [new Address('Bounced@Example.test'), new Address('complained@example.test'), new Address('manual@example.test'), new Address('ada@example.test')];

    $array = new ArrayTransport();
    $marketing = mailer($array, $list)->deliver(new Note($readers, marketing: true));
    expect(array_map(static fn (Address $a): string => $a->email, $array->sent[0]->to))->toBe(['ada@example.test'])
        ->and($marketing->suppressed)->toBe(['Bounced@Example.test', 'complained@example.test', 'manual@example.test']);

    $transactional = mailer($array, $list)->deliver(new Note($readers));
    expect(array_map(static fn (Address $a): string => $a->email, $array->sent[1]->to))->toBe(['complained@example.test', 'ada@example.test'])
        ->and($transactional->suppressed)->toBe(['Bounced@Example.test', 'manual@example.test']);

    $essential = mailer($array, $list)->deliver(new Note($readers, MailCategory::Essential));
    expect($array->sent[2]->to)->toHaveCount(4)->and($essential->suppressed)->toBe([]);
});

it('leaves suppressed copies off too, and sends nothing when no To reader is left', function () {
    $list = suppressed(SuppressionReason::Bounce);
    $array = new ArrayTransport();

    mailer($array, $list)->deliver(new Note([new Address('ada@example.test')], cc: [new Address('bounced@example.test')]));
    expect($array->sent[0]->cc)->toBe([]);

    $skipped = mailer($array, $list)->deliver(new Note([new Address('bounced@example.test')], cc: [new Address('ada@example.test')]));
    expect($skipped->skipped)->toBeTrue()
        ->and($skipped->messageId)->toBeNull()
        ->and($skipped->transport)->toBe('none')
        ->and($skipped->suppressed)->toBe(['bounced@example.test'])
        ->and($array->sent)->toHaveCount(1);
});

it('keeps the reason that stops more: a complaint after a bounce leaves the bounce, a bounce after a complaint replaces it', function (Suppressions $list) {
    $list->suppress(new Suppression('ADA@example.test', SuppressionReason::Bounce, 'ses', 100, "5.1.1 user unknown\r\nX-Injected: yes"));
    $list->suppress(new Suppression('ada@example.test', SuppressionReason::Complaint, 'ses', 200));
    $held = $list->find(['Ada@Example.test'])['ada@example.test'];
    expect($held->reason)->toBe(SuppressionReason::Bounce)
        ->and($held->at)->toBe(100)
        ->and($held->detail)->toBe('5.1.1 user unknown X-Injected: yes');

    $list->suppress(new Suppression('grace@example.test', SuppressionReason::Complaint, 'postmark', 300));
    $list->suppress(new Suppression('grace@example.test', SuppressionReason::Bounce, 'postmark', 400));
    expect($list->find(['grace@example.test'])['grace@example.test']->reason)->toBe(SuppressionReason::Bounce);

    $list->lift('Ada@Example.test');
    expect($list->find(['ada@example.test', 'grace@example.test']))->toHaveKeys(['grace@example.test'])->toHaveCount(1);
})->with([
    'in memory' => fn (): Suppressions => new ArraySuppressions(),
    'in the database' => function (): Suppressions {
        $connection = PdoConnection::sqlite(':memory:', 'test');
        DatabaseSuppressions::define(new Schema($connection, Dialect::for($connection)));

        return new DatabaseSuppressions($connection);
    },
]);

it('refuses a suppression of something that is not an address, or from a source no list names', function () {
    expect(fn () => new Suppression('not an address', SuppressionReason::Bounce, 'ses', 1))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Suppression('a@example.test', SuppressionReason::Bounce, 'SES Webhook', 1))->toThrow(\InvalidArgumentException::class);
});

it('holds tags and metadata to what every provider takes', function () {
    $message = static fn (array $tags, array $metadata = []): Message => new Message(new Address('a@shop.test'), [new Address('b@example.test')], 'S', '<p>x</p>', 'x', tags: $tags, metadata: $metadata);

    expect($message(['order', 'receipt-2'], ['order_id' => '1042'])->tags)->toBe(['order', 'receipt-2'])
        ->and(fn () => $message(['has space']))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $message(['x' => 'keyed']))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $message([], ['k' => "line\r\nbreak"]))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $message(array_fill(0, 11, 't')))->toThrow(\InvalidArgumentException::class);
});

it('fails a queued send at once for a refusal of the message, and leaves a retryable one to the backoff', function () {
    $permanent = MailTransportException::permanent('Resend answered 422: the to field is invalid', 422, 'resend');
    $retryable = MailTransportException::retryable('Resend answered 503', 503, 'resend');

    try {
        SendMail::attempt(static fn () => throw $permanent);
        $refused = null;
    } catch (JobRefused $e) {
        $refused = $e;
    }
    expect($refused?->reason)->toBe(SendMail::REFUSED)
        ->and($refused?->getPrevious())->toBe($permanent)
        ->and(fn () => SendMail::attempt(static fn () => throw $retryable))->toThrow($retryable)
        ->and(SendMail::attempt(static fn (): string => 'sent'))->toBe('sent');
});

it('reads a status as retryable when the transport is the trouble, and permanent when the message is', function () {
    foreach ([401, 403, 408, 429, 500, 502, 503] as $status) {
        expect(MailTransportException::retryableStatus($status))->toBeTrue();
    }
    foreach ([400, 404, 413, 422] as $status) {
        expect(MailTransportException::retryableStatus($status))->toBeFalse();
    }
    expect(new MailTransportException('old style')->retryable)->toBeTrue();
});

it('records each id a provider hands back against the readers, and fails no send for a log it cannot write', function () {
    $log = new \Tinvar\Mail\ArraySentMailLog();
    $mailer = new Mailer(new ArrayTransport(), new PlainRenderer(), new Address('shop@shop.test'), sentLog: $log);
    $sent = $mailer->deliver(new Note([new Address('Ada@example.test')]));

    expect($log->wasSent('array', (string) $sent->messageId, 'ada@example.test', time()))->toBeTrue();

    $broken = new class implements \Tinvar\Mail\SentMailLog {
        public function record(SentMessage $sent, int $now): void
        {
            throw new \RuntimeException('database down');
        }

        public function wasSent(string $provider, string $messageId, string $recipient, int $now): bool
        {
            return false;
        }
    };
    $array = new ArrayTransport();
    expect(new Mailer($array, new PlainRenderer(), new Address('shop@shop.test'), sentLog: $broken)->deliver(new Note([new Address('a@example.test')]))->messageId)->toBe('array-1')
        ->and($array->sent)->toHaveCount(1);
});

it('has SentMessage carry who was left off', function () {
    $message = new Message(new Address('a@shop.test'), [new Address('b@example.test')], 'S', '<p>x</p>', 'x');
    $sent = new SentMessage($message, 'id-1', 'ses')->withSuppressed(['c@example.test']);

    expect($sent->messageId)->toBe('id-1')->and($sent->suppressed)->toBe(['c@example.test']);
});
