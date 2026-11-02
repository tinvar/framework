<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/**
 * What an inbox entry says: a title, a line or two of body, and where it
 * leads (ADR 0152).
 *
 * Text, never markup: the client draws it as text, so nothing an order's
 * name or a customer's note holds can become a tag. And a link only to a
 * path on this site -- `/orders/42` -- never an origin, a protocol-relative
 * `//host`, a `javascript:` or a backslash a browser reads as a slash: an
 * entry is shown inside the application, and a link out of it from there
 * would borrow its trust.
 */
final readonly class InboxMessage
{
    /** Characters, as a person reads them. */
    public const int MAX_TITLE = 200;

    public const int MAX_BODY = 2_000;

    /** Bytes: a path, not a document. */
    public const int MAX_URL = 2_000;

    public function __construct(
        public string $title,
        public string $body = '',
        public ?string $url = null,
    ) {
        if (trim($title) === '' || mb_strlen($title) > self::MAX_TITLE || !self::isText($title)) {
            throw new \InvalidArgumentException('An inbox entry has a title of 1 to ' . self::MAX_TITLE . ' characters of text, with no control character.');
        }
        if (mb_strlen($body) > self::MAX_BODY || !self::isText($body, lines: true)) {
            throw new \InvalidArgumentException('An inbox entry\'s body is at most ' . self::MAX_BODY . ' characters of text.');
        }
        if ($url !== null && !self::isPath($url)) {
            throw new \InvalidArgumentException(
                "An inbox entry links to a path on this site, as in /orders/42: '{$url}' is not one. Leave out the origin; "
                . 'an entry never links off the site.',
            );
        }
    }

    /** Whether $url is a path on this site: `/` and a path, never `//`, `/\`, a scheme or a control character. */
    public static function isPath(string $url): bool
    {
        return $url !== ''
            && \strlen($url) <= self::MAX_URL
            && $url[0] === '/'
            && !str_starts_with($url, '//')
            && !str_contains($url, '\\')
            && preg_match('#^/[A-Za-z0-9\-._~!$&\'()*+,;=:@/%?\#]*\z#', $url) === 1;
    }

    private static function isText(string $text, bool $lines = false): bool
    {
        if (preg_match('//u', $text) !== 1) {
            return false;
        }

        return preg_match($lines ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $text) !== 1;
    }
}
