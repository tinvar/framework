<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * How a reader stops getting a kind of email, said in its headers: RFC
 * 2369's `List-Unsubscribe`, and RFC 8058's `List-Unsubscribe-Post`, which
 * lets a mail client unsubscribe its reader with one POST and no page.
 *
 * Meridian's MER-36.3 asks every email for an unsubscribe link in its body;
 * the headers are the same thing for the client, which shows its own
 * button for them and, for mail sent in bulk, expects them -- a sender that
 * leaves them out is one that large providers file as spam.
 *
 * The https URL is the one-click one: built by UnsubscribeLinks, carrying a
 * signed token naming the list, the reader and the tenant, and answered by
 * UnsubscribeEndpoint. A mailto address is the fallback for clients that
 * POST nothing. Both go into a header, so both are checked here: an https
 * URL with nothing a header or the field's `<...>, <...>` list could be
 * split on, and an Address, which refuses a line break.
 */
final readonly class Unsubscribe
{
    /** Longer than any URL UnsubscribeLinks builds; shorter than a header line may run. */
    public const int MAX_URL_BYTES = 900;

    public function __construct(
        public ?string $url = null,
        public ?Address $mailto = null,
        public bool $oneClick = true,
    ) {
        if ($url === null && $mailto === null) {
            throw new \InvalidArgumentException('An unsubscribe needs a URL, an address, or both.');
        }
        if ($url !== null) {
            self::assertUrl($url);
        }
        if ($oneClick && $url === null) {
            // RFC 8058: one-click is a POST to the https URL in the field.
            throw new \InvalidArgumentException('One-click unsubscribe needs the https URL to POST to.');
        }
    }

    /**
     * The headers, as MimeMessage writes them.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $targets = [];
        if ($this->url !== null) {
            $targets[] = "<{$this->url}>";
        }
        if ($this->mailto !== null) {
            $targets[] = "<mailto:{$this->mailto->email}>";
        }

        $headers = ['List-Unsubscribe' => implode(', ', $targets)];
        if ($this->oneClick) {
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return $headers;
    }

    /**
     * An absolute https URL of the characters a URI may hold and nothing
     * else: no space, no angle bracket or comma that would end the field's
     * entry early, no CR or LF that would start a header of its own.
     */
    private static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            \strlen($url) > self::MAX_URL_BYTES
            || preg_match('#^https://[A-Za-z0-9\-._~:/?\#\[\]@!$&\'()*+;=%]+\z#', $url) !== 1
            || str_contains($url, ',')
            || !\is_array($parts)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('An unsubscribe URL must be an absolute https URL with nothing a header could be split on.');
        }
    }
}
