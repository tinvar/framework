<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Attributes\Schedule;

/**
 * Every hour, forgets the node commands appended more than a day ago. A
 * command lives an hour at most (NodeCommands::MAX_TTL) and an agent
 * refuses it after, so past a day it is relayed, run and answered for no
 * one, and only grows the table the bus reads. A task an application lists
 * among its scheduled tasks, beside the CommandOutbox it registers in its
 * container.
 */
#[Schedule('53 * * * *')]
final class PruneCommandOutbox
{
    /** A day: well past the longest a command lives, and a result coming back for it. */
    public const int KEEP = 86_400;

    public function __invoke(CommandOutbox $outbox): void
    {
        self::prune($outbox, time());
    }

    /** @return int how many commands were forgotten */
    public static function prune(CommandOutbox $outbox, int $now): int
    {
        return $outbox->prune($now - self::KEEP);
    }
}
