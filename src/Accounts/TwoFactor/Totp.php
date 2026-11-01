<?php

declare(strict_types=1);

namespace Tinvar\Accounts\TwoFactor;

/**
 * Time-based one-time passwords (RFC 6238): the six digits an
 * authenticator app shows, from a shared secret and the clock.
 *
 * HMAC-SHA1, 30-second steps and six digits, because that is what every
 * authenticator app reads from an otpauth:// URI without asking; the
 * secret is 160 random bits, RFC 4226's recommendation.
 *
 * A code is taken one step either side of now, for a phone's clock a
 * little off and a code typed as it turned over -- and only for a step
 * later than the last one used: verify() returns the step it matched,
 * which the caller stores, so a code seen over a shoulder or replayed
 * from a log is refused once it has signed anyone in (RFC 6238, 5.2).
 */
final class Totp
{
    public const int DIGITS = 6;
    public const int PERIOD = 30;

    /** Steps either side of now a code is taken for. */
    public const int WINDOW = 1;

    public const int SECRET_BYTES = 20;

    /** A new secret: SECRET_BYTES random bytes. */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /** The code for $secret at the step $time falls in. */
    public static function at(#[\SensitiveParameter] string $secret, int $time, int $digits = self::DIGITS): string
    {
        return self::forStep($secret, intdiv($time, self::PERIOD), $digits);
    }

    /**
     * The step $code matches, within WINDOW of $time and after
     * $lastUsedStep, or null. Compared in constant time, every step in the
     * window checked whichever matched.
     */
    public static function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $time, ?int $lastUsedStep = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}\z/', $code) !== 1) {
            return null;
        }
        $now = intdiv($time, self::PERIOD);
        $matched = null;
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (hash_equals(self::forStep($secret, $step, self::DIGITS), $code) && $matched === null) {
                $matched = $step;
            }
        }
        if ($matched === null || ($lastUsedStep !== null && $matched <= $lastUsedStep)) {
            return null;
        }

        return $matched;
    }

    /**
     * The otpauth:// URI an authenticator app reads from a QR code, labelled
     * with the issuer and the account so a list of codes says which is which.
     */
    public static function uri(#[\SensitiveParameter] string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . Base32::encode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    private static function forStep(#[\SensitiveParameter] string $secret, int $step, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = \ord($hash[19]) & 0x0F;
        $binary = ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
