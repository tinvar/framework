<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * A syntactically valid email address.
 *
 * Syntax only: that an address parses says nothing about whether it exists or
 * belongs to the person who typed it. Confirmation is a separate step.
 *
 * Two things FILTER_VALIDATE_EMAIL allows that an application rarely wants:
 *
 *  - domain literals, `a@[127.0.0.1]` and `a@[IPv6:::1]`. An application that
 *    later connects to, or builds a URL from, a validated address can be
 *    pointed at loopback by one. Refused here.
 *  - no length bound. RFC 5321 caps the address at 254 and the local part at
 *    64; both are enforced rather than assumed.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Email implements Rule
{
    public const int MAX_LENGTH = 254;
    public const int MAX_LOCAL_LENGTH = 64;

    public function appliesTo(): array
    {
        return ['string'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $invalid = "{$field} must be a valid email address.";

        if (\strlen($value) > self::MAX_LENGTH) {
            return $invalid;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $invalid;
        }

        // FILTER_VALIDATE_EMAIL has already required an @ and capped the
        // local part at MAX_LOCAL_LENGTH (pinned by the tests), so only the
        // domain is left to look at.
        $domain = substr($value, (int) strrpos($value, '@') + 1);

        if (str_starts_with($domain, '[')) {
            return "{$field} must use a domain name, not an IP address.";
        }

        return null;
    }
}
