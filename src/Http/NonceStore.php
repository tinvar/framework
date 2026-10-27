<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The nonces deliveries have used, kept for as long as their timestamps
 * would still be accepted: after that the timestamp refuses a replay on
 * its own, so nothing older needs remembering.
 */
interface NonceStore
{
    /** Whether $nonce is new for $scope, recording it until $expiresAt if so. */
    public function claim(string $scope, string $nonce, int $expiresAt, int $now): bool;

    /** Forgets a claim, for a delivery whose handler failed: the sender's retry must not be taken for a replay. */
    public function release(string $scope, string $nonce): void;
}
