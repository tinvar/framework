<?php

declare(strict_types=1);

/**
 * Transports composed of others: a failover that tries the next on a
 * retryable failure and stops at a refusal of the message, and a round
 * robin that starts each message at the next transport and fails over from
 * there.
 */

namespace Tinvar\Tests\Mail\Failover;

use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\FailoverTransport;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\RoundRobinTransport;
use Tinvar\Mail\SentMessage;

/** A transport that fails as it is told, a set number of times, and then sends. */
final class Flaky implements Delivers
{
    public int $tried = 0;

    public function __construct(public string $name, public ?MailTransportException $fails = null, public int $times = PHP_INT_MAX) {}

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $this->tried++;
        if ($this->fails !== null && $this->tried <= $this->times) {
            throw $this->fails;
        }

        return new SentMessage($message, "{$this->name}-{$this->tried}", $this->name);
    }
}

function note(): Message
{
    return new Message(new Address('shop@shop.test'), [new Address('ada@example.test')], 'A note', '<p>Hi</p>', 'Hi');
}

it('sends through the first transport that takes the message', function () {
    $ses = new Flaky('ses', MailTransportException::retryable('SES answered 503', 503, 'ses'));
    $postmark = new Flaky('postmark');
    $sent = new FailoverTransport([$ses, $postmark])->deliver(note());

    expect($sent->transport)->toBe('postmark')->and($sent->messageId)->toBe('postmark-1')->and($ses->tried)->toBe(1);
});

it('stops at a refusal of the message: the next provider would refuse it too', function () {
    $refused = MailTransportException::permanent('SES answered 400: MessageRejected', 400, 'ses');
    $postmark = new Flaky('postmark');

    expect(fn () => new FailoverTransport([new Flaky('ses', $refused), $postmark])->deliver(note()))->toThrow($refused)
        ->and($postmark->tried)->toBe(0);
});

it('throws the last failure, retryable, when every transport fails', function () {
    $failover = new FailoverTransport([
        new Flaky('ses', MailTransportException::retryable('SES did not answer: timeout', null, 'ses')),
        new Flaky('postmark', MailTransportException::retryable('Postmark answered 500', 500, 'postmark')),
    ]);

    try {
        $failover->deliver(note());
        $thrown = null;
    } catch (MailTransportException $e) {
        $thrown = $e;
    }

    expect($thrown?->retryable)->toBeTrue()
        ->and($thrown?->status)->toBe(500)
        ->and($thrown?->getMessage())->toContain('Every transport failed')->toContain('Postmark answered 500');
});

it('passes over a transport that failed within the minute, and tries it again after', function () {
    $now = 1_000;
    $ses = new Flaky('ses', MailTransportException::retryable('SES answered 503', 503, 'ses'), times: 1);
    $postmark = new Flaky('postmark');
    $failover = new FailoverTransport([$ses, $postmark], 60, static function () use (&$now): int {
        return $now;
    });

    $failover->deliver(note());
    $second = $failover->deliver(note());
    expect($second->transport)->toBe('postmark')->and($ses->tried)->toBe(1);

    $now += 61;
    expect($failover->deliver(note())->transport)->toBe('ses')->and($ses->tried)->toBe(2);
});

it('sends through a transport that says nothing back, as one that does', function () {
    $array = new ArrayTransport();
    $sent = new FailoverTransport([new Flaky('ses', MailTransportException::retryable('down')), $array])->deliver(note());

    expect($sent->transport)->toBe('array')->and($array->sent)->toHaveCount(1);
});

it('starts each message at the next transport, round robin, and fails over from there', function () {
    $a = new Flaky('a');
    $b = new Flaky('b', MailTransportException::retryable('B answered 429', 429, 'b'), times: 1);
    $c = new Flaky('c');
    $robin = new RoundRobinTransport([$a, $b, $c], start: 0);

    $sent = array_map(static fn (): string => $robin->deliver(note())->transport, range(1, 4));

    // a; b fails, so c; c; then a again.
    expect($sent)->toBe(['a', 'c', 'c', 'a']);
});

it('is two transports or more', function () {
    expect(fn () => new FailoverTransport([new ArrayTransport()]))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new RoundRobinTransport([new ArrayTransport()]))->toThrow(\InvalidArgumentException::class);
});
