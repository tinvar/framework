<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * A CBOR byte string, kept apart from a text string: PHP has one string
 * type, and WebAuthn's structures mean different things by the two -- an
 * attestation's `authData` is bytes, its `fmt` is text -- so a decoder that
 * returned both as strings would let one stand in for the other.
 */
final readonly class CborBytes
{
    public function __construct(public string $bytes) {}
}
