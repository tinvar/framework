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
use Tinvar\Mail\Bounded;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\FailoverTransport;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\RoundRobinTransport;
use Tinvar\Mail\SendMail;
use Tinvar\Mail\SentMessage;
use Tinvar\Mail\SmtpTransport;

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

/**
 * A transport that can be held to a time, and spends $takes seconds of the
 * fake clock failing -- or what it was held to, when that is less.
 */
final class Slow implements Delivers, Bounded
{
    private ?float $within = null;

    /**
     * @param \Closure(float): void $spend moves the fake clock on
     * @param (\Closure(float): void)|null $record told each time it is held to a time
     */
    public function __construct(public string $name, private float $takes, private \Closure $spend, private ?\Closure $record = null) {}

    public function within(float $seconds): static
    {
        $copy = clone $this;
        $copy->within = $seconds;
        if ($this->record !== null) {
            ($this->record)($seconds);
        }

        return $copy;
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        ($this->spend)(min($this->takes, $this->within ?? $this->takes));

        throw MailTransportException::retryable("{$this->name} did not answer: timeout", null, $this->name);
    }
}

it('holds a failover to one deadline, inside SendMail\'s timeout: four slow providers do not run past it', function () {
    $now = 0.0;
    $given = [];
    $spend = static function (float $seconds) use (&$now): void {
        $now += $seconds;
    };
    $record = static function (float $seconds) use (&$given): void {
        $given[] = $seconds;
    };
    $slow = array_map(static fn (string $name): Slow => new Slow($name, 15.0, $spend, $record), ['ses', 'postmark', 'mailgun', 'resend']);
    $failover = new FailoverTransport($slow, monotonic: static function () use (&$now): float {
        return $now;
    });

    try {
        $failover->deliver(note());
        $thrown = null;
    } catch (MailTransportException $e) {
        $thrown = $e;
    }

    // 15 s each would be 60 s; each is held to what is left of the 50 instead.
    expect($given)->toBe([50.0, 35.0, 20.0, 5.0])
        ->and($now)->toBe(FailoverTransport::DEADLINE)
        ->and(FailoverTransport::DEADLINE)->toBeLessThan((float) SendMail::TIMEOUT)
        ->and($thrown?->retryable)->toBeTrue()
        ->and($thrown?->getMessage())->toContain('resend did not answer');
});

it('tries no transport once the deadline has passed, and says so', function () {
    $now = 0.0;
    $spend = static function (float $seconds) use (&$now): void {
        $now += $seconds;
    };
    $last = new Flaky('postmark');
    $failover = new FailoverTransport([new Slow('ses', 15.0, $spend), new Slow('mailgun', 15.0, $spend), $last], deadline: 30.0, monotonic: static function () use (&$now): float {
        return $now;
    });

    try {
        $failover->deliver(note());
        $thrown = null;
    } catch (MailTransportException $e) {
        $thrown = $e;
    }

    expect($last->tried)->toBe(0)
        ->and($thrown?->retryable)->toBeTrue()
        ->and($thrown?->getMessage())->toContain('30s')->toContain('1 not tried')->toContain('mailgun did not answer');
});

it('holds an SMTP send to what is left: with nothing left, it does not connect', function () {
    $smtp = new SmtpTransport('192.0.2.1')->within(0.0);

    expect(fn () => $smtp->send(note()))->toThrow(MailTransportException::class, 'no time was left');
});

it('is held to a deadline of more than nothing', function () {
    expect(fn () => new FailoverTransport([new ArrayTransport(), new ArrayTransport()], deadline: 0.0))->toThrow(\InvalidArgumentException::class);
});
