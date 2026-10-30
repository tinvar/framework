<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What one email may link to and load (ADR 0086): the canonical origin its
 * links are built on, and any further origins its images may come from.
 *
 * A link may be any https URL, a URL on the message's own origin, or a
 * mailto: one address -- never `javascript:`, `data:`, a relative path or
 * plain http elsewhere. An image is loaded by the mail client when the
 * message is opened, so it comes only from the message's own origin or one
 * the application named, and carries no query: an image from anywhere else,
 * or one whose URL says who is reading, is a tracking pixel whatever its
 * size.
 *
 * The origin is the one CanonicalOrigins answers for the reader -- https,
 * the tenant's own domain or the platform's -- so one tenant's message is
 * checked against its own origin and no other's. Plain http is an origin
 * only on this machine, for an application `tinvar serve` serves in dev.
 *
 * Two kinds of image are allowed only by name (ADR 0116). An image the
 * message carries inline is `cid:` one of the content ids its attachments
 * declare, and no other. And a tracking image -- one whose URL does say
 * who is reading -- loads only from an origin named in $tracking: there,
 * and only there, an image may carry a query. Allowing one is a choice the
 * application makes in its Email, where a review sees it, never something
 * an image origin allows by being named for the logo.
 *
 * The renderer's kit holds its components to the same rules as it draws
 * (@tinvar/mail); SafeMailHtml holds the HTML that comes back to them again,
 * since the renderer is not trusted to have. The attachments' names, types
 * and sizes go to the kit with the policy, so a template can list what the
 * message carries; their bytes never do.
 */
final readonly class LinkPolicy
{
    private const string HTTPS_ORIGIN = '#^https://(?:[^/:?\#@\[\]\s]+|\[[0-9a-f:.]+\])(?::[1-9][0-9]{0,4})?\z#i';

    private const string LOOPBACK_ORIGIN = '#^http://(?:localhost|127(?:\.[0-9]{1,3}){3}|\[::1\])(?::[1-9][0-9]{0,4})?\z#';

    /** Whitespace and control characters, which no URL in an email holds: a browser drops some inside a scheme. */
    private const string UNSAFE = '/[\s\x00-\x1f\x7f]|\xc2[\x80-\x9f]|\xe2\x80[\xa8\xa9]/';

    /**
     * @param string|null $origin the canonical origin the message's links are built on
     * @param list<string> $images further https origins images may be loaded from
     * @param list<string> $tracking https origins a tracking image may load from, its query and all
     * @param list<string> $inline the content ids of the images the message carries inline
     * @param list<array{name: string, type: string, bytes: int}> $attachments what the
     *        message carries to be opened, as Attachment::describe() says it
     */
    public function __construct(
        public ?string $origin = null,
        public array $images = [],
        public array $tracking = [],
        public array $inline = [],
        public array $attachments = [],
    ) {
        if ($origin !== null && preg_match(self::HTTPS_ORIGIN, $origin) !== 1 && preg_match(self::LOOPBACK_ORIGIN, $origin) !== 1) {
            throw new \InvalidArgumentException("'{$origin}' is not an origin an email's links are on: https://host[:port], or http on loopback.");
        }
        foreach ($this->images as $image) {
            if (preg_match(self::HTTPS_ORIGIN, $image) !== 1) {
                throw new \InvalidArgumentException("'{$image}' is not an origin images may come from: https://host[:port].");
            }
        }
        foreach ($this->tracking as $tracker) {
            if (preg_match(self::HTTPS_ORIGIN, $tracker) !== 1) {
                throw new \InvalidArgumentException("'{$tracker}' is not an origin a tracking image may come from: https://host[:port].");
            }
        }
        foreach ($this->inline as $id) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $id) !== 1) {
                throw new \InvalidArgumentException("'{$id}' is not a content id.");
            }
        }
    }

    /** Whether a reader may follow $href. */
    public function allowsLink(string $href): bool
    {
        if ($href === '' || preg_match(self::UNSAFE, $href) === 1) {
            return false;
        }
        if (preg_match('/^mailto:/i', $href) === 1) {
            return preg_match('/^mailto:[^@\s<>"(),;:\\\\\[\]]+@[^@\s<>"(),;:\\\\\[\]?]+(?:\?[^\s<>"]*)?\z/i', $href) === 1;
        }
        if (preg_match('#^https://[^/?\#\s]#i', $href) === 1 && self::hasNoUser($href)) {
            return true;
        }

        return $this->origin !== null && self::isOn($href, $this->origin) && self::hasNoUser($href);
    }

    /** Whether a mail client may load an image from $src. */
    public function allowsImage(string $src): bool
    {
        if ($src === '' || preg_match(self::UNSAFE, $src) === 1) {
            return false;
        }
        // One the message carries, by the content id it declared: compared
        // whole, so `cid:logo` names `logo` and never `logo.evil`.
        if (preg_match('/^cid:/i', $src) === 1) {
            return \in_array(substr($src, 4), $this->inline, true);
        }
        // A tracker, from an origin named for it alone: the query is what it
        // is for. Never a fragment, which no image needs.
        if (!str_contains($src, '#')) {
            foreach ($this->tracking as $origin) {
                if (\strlen($src) > \strlen($origin) && self::isOn($src, $origin) && self::hasNoUser($src)) {
                    return true;
                }
            }
        }
        if (strpbrk($src, '?#') !== false) {
            return false;
        }
        foreach ([...($this->origin === null ? [] : [$this->origin]), ...$this->images] as $origin) {
            if (\strlen($src) > \strlen($origin) && self::isOn($src, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * As the mail frame carries it to the renderer, whose kit holds its
     * components to it as they draw.
     *
     * @return array{origin: string|null, images: list<string>, tracking: list<string>, inline: list<string>, attachments: list<array{name: string, type: string, bytes: int}>}
     */
    public function toFrame(): array
    {
        return [
            'origin' => $this->origin,
            'images' => $this->images,
            'tracking' => $this->tracking,
            'inline' => $this->inline,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * $url is $origin itself, or a path, query or fragment on it -- never
     * `https://shop.test.evil.example`, which merely starts with it.
     */
    private static function isOn(string $url, string $origin): bool
    {
        if (strncasecmp($url, $origin, \strlen($origin)) !== 0) {
            return false;
        }
        $next = substr($url, \strlen($origin), 1);

        return $next === '' || $next === '/' || $next === '?' || $next === '#';
    }

    /** No user or password before the host, which would make the host something else. */
    private static function hasNoUser(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }
}
