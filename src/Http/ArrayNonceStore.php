<?php

declare(strict_types=1);

namespace Tinvar\Http;

/** Nonces in this process's memory: for tests, and nothing a second process can share. */
final class ArrayNonceStore implements NonceStore
{
    /** @var array<string, int> scope NUL nonce => expires at */
    private array $nonces = [];

    public function claim(string $scope, string $nonce, int $expiresAt, int $now): bool
    {
        $this->nonces = array_filter($this->nonces, static fn (int $expires): bool => $expires >= $now);
        $key = $scope . "\0" . $nonce;
        if (isset($this->nonces[$key])) {
            return false;
        }
        $this->nonces[$key] = $expiresAt;

        return true;
    }

    public function release(string $scope, string $nonce): void
    {
        unset($this->nonces[$scope . "\0" . $nonce]);
    }
}
