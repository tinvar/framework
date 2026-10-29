<?php

declare(strict_types=1);

namespace Tinvar\Scenarios;

/**
 * What a scenario made: its users' ids, anything a test needs to find what
 * was made, and -- for a load test, which signs in for real -- each user's
 * sign-in, with a password made for this seeding and kept nowhere else.
 *
 * The browser test endpoint hands back `users` and `details`, never
 * `credentials`: a browser test signs in through the endpoint. Only
 * `tinvar scenario:seed --credentials=FILE` writes them, to a file of the
 * runner's own.
 */
final readonly class Seeded
{
    /**
     * @param list<string> $users
     * @param array<string, mixed> $details
     * @param list<array{login: string, password: string}> $credentials
     */
    public function __construct(
        public array $users,
        public array $details = [],
        #[\SensitiveParameter]
        public array $credentials = [],
    ) {
        if ($users === [] || \in_array('', $users, true)) {
            throw new \InvalidArgumentException('A scenario says which users it made: a list of ids, at least one.');
        }
        if (\array_key_exists('users', $details) || \array_key_exists('credentials', $details)) {
            throw new \InvalidArgumentException('A scenario\'s details cannot say `users` or `credentials`.');
        }
    }

    /** @return array<string, mixed> what the browser test endpoint hands back: no credential */
    public function forTest(): array
    {
        return ['users' => $this->users, ...$this->details];
    }

    public function __debugInfo(): array
    {
        return ['users' => $this->users, 'details' => $this->details, 'credentials' => \count($this->credentials) . ' withheld'];
    }
}
