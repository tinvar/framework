<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * What a request says about the page the browser holds, as far as it
 * changes how fields are encoded: the #[Once] keys whose rendered content
 * it keeps (`X-Have-Once`), and the merged fields it wants replaced rather
 * than added to (`X-Reset`). ADR 0020.
 *
 * Neither can make a response carry anything the same request without the
 * header would not: a held key sends less, and a reset sends the same items
 * marked to replace. So both are read on the client's word -- except that a
 * held key is only taken from a client whose held content was rendered for
 * this session, tenant and panel, which the Kernel checks before it gets
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
     * @param list<string> $heldOnce once keys whose field is sent as held, unresolved
     * @param list<string> $resets   dot paths of merged fields sent to replace what is shown
     */
    public function __construct(
        public array $heldOnce = [],
        public array $resets = [],
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
        return new self($keys, $this->resets);
    }

    /** @param list<string> $paths */
    public function withResets(array $paths): self
    {
        return new self($this->heldOnce, $paths);
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
