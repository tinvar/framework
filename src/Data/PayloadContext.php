<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * What a request says about the page the browser holds, as far as it
 * changes how fields are encoded: the #[Once] keys whose rendered content
 * it keeps (`X-Have-Once`), and the merged fields it wants replaced rather
 * than added to (`X-Reset`). ADR 0020. And which page it asked for -- the
 * route, its parameters and its query -- which a once field's default key
 * is scoped to.
 *
 * Neither can make a response carry anything the same request without the
 * header would not: a held key sends less, and a reset sends the same items
 * marked to replace. So both are read on the client's word -- except that a
 * held key is only taken from a client whose held content was rendered for
 * this session, user, tenant and panel, which the Kernel checks before it gets
 * here.
 */
final readonly class PayloadContext
{
    /** More keys or paths than any page has fields is a client bug or a probe. */
    public const int MAX_ENTRIES = 64;

    public const int MAX_HEADER_BYTES = 4096;

    /** A once key: a field's, or one an attribute names. */
    public const string KEY = '/^[A-Za-z0-9_.:\/-]{1,128}\z/';

    /** A dot path to a field: a segment, then field names. As PartialReload reads them. */
    public const string PATH = '/^[A-Za-z0-9_\/-]+(\.[A-Za-z0-9_]+)*\z/';

    /**
     * How many characters of a once key name its subject: the page it was
     * rendered for, or the identity a key names in braces. 132 bits of a
     * SHA-256, base64url, so no two subjects meet by accident.
     */
    public const int DIGEST_LENGTH = 22;

    /**
     * @param list<string> $heldOnce once keys whose field is sent as held, unresolved
     * @param list<string> $resets   dot paths of merged fields sent to replace what is shown
     * @param string       $route    the page the request asked for -- its route, parameters
     *        and query, opaque -- or '' for anything but a page render
     */
    public function __construct(
        public array $heldOnce = [],
        public array $resets = [],
        public string $route = '',
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function holds(string $key): bool
    {
        return \in_array($key, $this->heldOnce, true);
    }

    public function resets(string $path): bool
    {
        return \in_array($path, $this->resets, true);
    }

    /** @param list<string> $keys */
    public function withHeldOnce(array $keys): self
    {
        return new self($keys, $this->resets, $this->route);
    }

    /** @param list<string> $paths */
    public function withResets(array $paths): self
    {
        return new self($this->heldOnce, $paths, $this->route);
    }

    /**
     * The page a route was asked for as: which page class, with which path
     * parameters and which query. Everything a loader reads from the URL,
     * so two requests that could load different things never name the same
     * page. Serialized rather than JSON-encoded: a query is bytes, and two
     * different ones must not be made alike on the way.
     *
     * @param array<string, string> $parameters
     * @param array<array-key, mixed> $query
     */
    public static function routeOf(string $page, array $parameters, array $query): string
    {
        return serialize([$page, $parameters, $query]);
    }

    /** A subject, as the part of a once key that names it: always DIGEST_LENGTH characters a header can carry. */
    public static function digest(string $subject): string
    {
        return substr(rtrim(strtr(base64_encode(hash('sha256', $subject, true)), '+/', '-_'), '='), 0, self::DIGEST_LENGTH);
    }

    /**
     * A comma-separated header's entries, each matching $shape: empty when
     * it is absent or cannot be read whole. Nothing is taken from a header
     * that is partly malformed -- half a list is not what the client meant.
     *
     * @return list<string>
     */
    public static function listFrom(?string $header, string $shape): array
    {
        if ($header === null || \strlen($header) > self::MAX_HEADER_BYTES) {
            return [];
        }

        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $entry): bool => $entry !== '',
        ));

        // Counted as sent, before repeats are dropped: no client of ours
        // repeats one, so a list this long is not one of ours.
        if (\count($entries) > self::MAX_ENTRIES) {
            return [];
        }
        $entries = array_values(array_unique($entries));
        foreach ($entries as $entry) {
            if (preg_match($shape, $entry) !== 1) {
                return [];
            }
        }

        return $entries;
    }
}
