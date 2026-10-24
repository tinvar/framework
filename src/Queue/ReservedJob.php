<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * A job a worker holds: its payload, which attempt this is (1 for the
 * first), and the token that says the reservation is this worker's.
 *
 * The token matters once a reservation has lapsed: a worker that ran past
 * it and another that has taken the job since must not both delete it, so
 * the queue acts only for the holder of the current token.
 */
final readonly class ReservedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public string $token,
    ) {}
}
