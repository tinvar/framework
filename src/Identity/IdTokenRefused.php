<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * An ID token that is not believed: malformed, signed with an algorithm
 * or a key not allowed, a signature that does not verify, or a claim --
 * issuer, audience, expiry, nonce -- that does not hold.
 *
 * `reason` names which, for the log; the message never quotes the token or
 * its claims, which name a person.
 */
final class IdTokenRefused extends \RuntimeException
{
    public const string MALFORMED = 'malformed';
    public const string ALGORITHM = 'algorithm';
    public const string KEY = 'key';
    public const string SIGNATURE = 'signature';
    public const string ISSUER = 'issuer';
    public const string AUDIENCE = 'audience';
    public const string EXPIRED = 'expired';
    public const string NOT_YET = 'not_yet_valid';
    public const string NONCE = 'nonce';
    public const string SUBJECT = 'subject';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
