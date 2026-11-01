<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * What someone typed into a search box, as the words search looks for
 * (ADR 0150).
 *
 * The one place user input enters search, and it leaves as words alone:
 * runs of letters and digits in any script, lowercased, at most MAX_TERMS
 * of them and MAX_LENGTH characters each. Quotes, operators, column
 * filters, wildcards and every other character of a backend's query
 * language are dropped, so no backend is ever handed syntax a visitor
 * wrote -- each builds its own query from these words, and binds it as a
 * value. The last word is matched as a prefix, as someone typing expects.
 */
final readonly class SearchTerms
{
    /** The most words searched: the rest of a longer query is dropped. */
    public const int MAX_TERMS = 12;

    /** The longest word searched, in characters. */
    public const int MAX_LENGTH = 64;

    /** The most of a query read, in bytes, before it is split. */
    public const int MAX_QUERY_BYTES = 1024;

    /** @param list<string> $words */
    private function __construct(public array $words) {}

    public static function parse(string $query): self
    {
        $query = substr($query, 0, self::MAX_QUERY_BYTES);
        // Text that is not UTF-8 -- or was cut inside a character -- is read
        // as nothing, rather than guessed at.
        if (preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches) === false) {
            return new self([]);
        }
        $words = [];
        foreach ($matches[0] as $word) {
            $word = mb_strtolower(mb_substr($word, 0, self::MAX_LENGTH, 'UTF-8'), 'UTF-8');
            if (!\in_array($word, $words, true)) {
                $words[] = $word;
            }
            if (\count($words) === self::MAX_TERMS) {
                break;
            }
        }

        return new self($words);
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }

    /** The words as one line, for a backend that takes text: Meilisearch's `q`. */
    public function text(): string
    {
        return implode(' ', $this->words);
    }

    /**
     * An FTS5 query: each word a quoted string, the last a prefix, all of
     * them required. Bound as a value; built from words that hold no quote.
     */
    public function fts5(): string
    {
        $last = \count($this->words) - 1;

        return implode(' ', array_map(
            static fn (string $word, int $i): string => '"' . str_replace('"', '""', $word) . '"' . ($i === $last ? '*' : ''),
            $this->words,
            array_keys($this->words),
        ));
    }

    /**
     * A Postgres to_tsquery(): the words joined by `&`, the last a prefix.
     * Bound as a value; built from letters and digits alone, which carry
     * none of its operators.
     */
    public function tsquery(): string
    {
        $last = \count($this->words) - 1;

        return implode(' & ', array_map(
            static fn (string $word, int $i): string => $word . ($i === $last ? ':*' : ''),
            $this->words,
            array_keys($this->words),
        ));
    }
}
