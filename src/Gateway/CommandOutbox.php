<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

/**
 * Where PHP leaves signed node commands for the gateway's command bus to
 * relay.
 *
 * The bus reads it and nothing else -- "outbox reader role only" -- so it
 * holds no credential that could write a command, and every command it
 * relays carries PHP's signature, which the agent checks. A bus that is
 * compromised can drop, delay or replay commands; it cannot make one, nor
 * point one at another node. Replay is the agent's to refuse, by the
 * command's id and expiry.
 */
interface CommandOutbox
{
    /** Leaves $sealed for the bus, beside the writes around it where the outbox shares their connection. */
    public function append(NodeCommand $command, string $sealed): void;

    /**
     * Commands appended at or after $since, oldest first: what a reader asks
     * for. Commits can land out of order, so a reader asks again from a
     * little before the last it read, and drops the ids it has relayed.
     *
     * @return list<OutboxEntry>
     */
    public function since(int $since, int $limit = 100): array;
}
