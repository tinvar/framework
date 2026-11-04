<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * What a provider's API said was wrong, made fit for an exception's
 * message: the one place billing's Stripe client and mail's providers cut
 * a reason down, so neither drifts from the other.
 *
 * Each secret the caller holds is cut out, then whatever $scrub cuts -- a
 * secret the caller holds in another form, a key's shape -- then the text
 * is put on one line, cut to $max bytes on a character's edge, and handed
 * through the Redactor's patterns. A provider that quotes the key it was
 * sent back in its error, or the reader's address, says neither in a log
 * line or a failed job.
 */
final class SafeReason
{
    /** The most of a reason quoted, unless the caller says otherwise. */
    public const int MAX_BYTES = 300;

    /**
     * @param list<string> $secrets each cut out wherever it appears
     * @param (\Closure(string): string)|null $scrub cuts what the caller holds and will not reveal
     */
    public static function of(string $text, #[\SensitiveParameter] array $secrets = [], ?\Closure $scrub = null, int $max = self::MAX_BYTES): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, Redactor::REDACTED, $text);
            }
        }
        if ($scrub !== null) {
            $text = $scrub($text);
        }
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        if (\strlen($text) > $max) {
            $text = mb_strcut($text, 0, $max, 'UTF-8') . '...';
        }

        return new Redactor(traces: false)->text($text);
    }
}
