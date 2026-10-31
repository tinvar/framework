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
     * Commands appended at or after $since, oldest first by when and then by
     * id: what a reader asks for. Commits can land out of order, so a reader
     * asks again from a little before the last it read, and drops the ids it
     * has relayed.
     *
     * Given $afterId, the page goes on after the entry ($since, $afterId):
     * only those later, or as late with a greater id. So a second holding
     * more commands than a page is read through, page by page, rather than
     * its first page read again for ever.
     *
     * @return list<OutboxEntry>
     */
    public function since(int $since, int $limit = 100, string $afterId = ''): array;

    /**
     * Forgets every command appended before $before; returns how many. A
     * command lives an hour at most (NodeCommands::MAX_TTL), and an agent
     * refuses it after, so one well past that is relayed and answered for
     * no one (PruneCommandOutbox).
     */
    public function prune(int $before): int;

    /** The command appended as $id, if any: what a result coming back answers (CommandResults). */
    public function find(string $id): ?OutboxEntry;
}
