<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Spreads messages across its transports, each message starting at the
 * next -- two providers' quotas, say, or two SES regions -- and failing
 * over from there as FailoverTransport does: on a retryable failure to the
 * transport after it, on a refusal of the message not at all (ADR 0147).
 *
 * Each worker starts at a random transport, so a fleet of workers just
 * started does not send its first messages all through the first.
 */
final class RoundRobinTransport implements Delivers
{
    private readonly FailoverTransport $failover;

    private int $next;

    /**
     * @param list<Transport> $transports
     * @param int $retryAfter seconds a failed transport is passed over
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param int|null $start the first message's transport, for tests; random unless given
     * @param float $deadline seconds one message's failover is given (FailoverTransport::DEADLINE)
     */
    public function __construct(array $transports, int $retryAfter = 60, ?\Closure $clock = null, ?int $start = null, float $deadline = FailoverTransport::DEADLINE)
    {
        if (\count($transports) < 2) {
            throw new \InvalidArgumentException('A round robin is two transports or more.');
        }
        $this->failover = new FailoverTransport($transports, $retryAfter, $clock, $deadline);
        $this->next = $start ?? random_int(0, \count($transports) - 1);
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $start = $this->next;
        $this->next = ($this->next + 1) % $this->failover->count();

        return $this->failover->deliverFrom($message, $start);
    }
}
