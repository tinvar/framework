<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Sends through the first of its transports that takes the message: SES,
 * say, and Postmark when SES will not (ADR 0147).
 *
 * The next is tried only on a retryable failure -- the transport's trouble:
 * down, slow, throttled, its key refused. A refusal of the message itself
 * is thrown at once, since the next provider would refuse it too, or worse,
 * take it where the first was right not to. When every transport fails,
 * the last failure is thrown, retryable, for a queued send's backoff.
 *
 * A transport that failed is passed over for $retryAfter seconds, so a
 * provider that is down costs one timeout a minute rather than one a
 * message; when all of them have failed recently, all are tried again
 * anyway -- there is nothing else to send through.
 */
final class FailoverTransport implements Delivers
{
    /** @var list<Transport> */
    private readonly array $transports;

    /** @var array<int, int> transport's index => when it last failed */
    private array $failed = [];

    /**
     * @param list<Transport> $transports in the order they are tried
     * @param int $retryAfter seconds a failed transport is passed over
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        array $transports,
        private readonly int $retryAfter = 60,
        private readonly ?\Closure $clock = null,
    ) {
        if (\count($transports) < 2) {
            throw new \InvalidArgumentException('A failover is two transports or more.');
        }
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('A failed transport is passed over for 0 seconds or more.');
        }
        $this->transports = $transports;
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        return $this->through($message, $this->order(0));
    }

    /**
     * Tries $order's transports in turn.
     *
     * @param list<int> $order
     * @throws MailTransportException
     */
    private function through(Message $message, array $order): SentMessage
    {
        $last = null;
        foreach ($order as $i) {
            try {
                $sent = SentMessage::through($this->transports[$i], $message);
                unset($this->failed[$i]);

                return $sent;
            } catch (MailTransportException $e) {
                if (!$e->retryable) {
                    throw $e;
                }
                $this->failed[$i] = $this->now();
                $last = $e;
            }
        }

        \assert($last !== null);

        throw MailTransportException::retryable('Every transport failed; the last said: ' . $last->getMessage(), $last->status, $last->transport, $last);
    }

    /**
     * The transports from $start on, wrapping round, those that failed
     * within $retryAfter put last.
     *
     * @return list<int>
     */
    private function order(int $start): array
    {
        $count = \count($this->transports);
        $now = $this->now();
        $fresh = [];
        $stale = [];
        for ($n = 0; $n < $count; $n++) {
            $i = ($start + $n) % $count;
            if (isset($this->failed[$i]) && $now - $this->failed[$i] < $this->retryAfter) {
                $stale[] = $i;
            } else {
                $fresh[] = $i;
            }
        }

        return [...$fresh, ...$stale];
    }

    /** For RoundRobinTransport: the same failover, from another start. */
    public function deliverFrom(Message $message, int $start): SentMessage
    {
        return $this->through($message, $this->order($start));
    }

    public function count(): int
    {
        return \count($this->transports);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
