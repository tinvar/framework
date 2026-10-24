<?php

declare(strict_types=1);

namespace Tinvar\Testing;

/**
 * Reads the RSC payload a rendered page carries, for assertions.
 *
 * Flight rows are React's internal format, and the framework neither writes
 * nor parses them (tinvar-spec.md, "Rendered output"). A test may: it reads
 * them to say which client components a page references, and fails loudly
 * when a React upgrade moves the format, which is what a test is for.
 */
final class RscPayload
{
    /**
     * The payload in $body: the body itself for a payload response, or a
     * document's `self.__rsc.push(...)` arguments joined -- each a JSON
     * string, or `{"$b64": ...}` for bytes that are not UTF-8.
     */
    public static function of(string $body, ?string $contentType = null): string
    {
        if ($contentType !== null && str_starts_with($contentType, 'text/x-component')) {
            return $body;
        }

        preg_match_all('#<script(?:\s+nonce="[^"]*")?>self\.__rsc\.push\((.*?)\)</script>#s', $body, $matches);

        $payload = '';
        foreach ($matches[1] as $argument) {
            $row = json_decode($argument, true);
            if (\is_string($row)) {
                $payload .= $row;
            } elseif (\is_array($row) && \is_string($row['$b64'] ?? null)) {
                $payload .= (string) base64_decode($row['$b64'], true);
            }
        }

        return $payload;
    }

    /**
     * The export names of the client components a payload references, from
     * its `I[...]` rows: `["<module id>", [chunks], "<export>", ...]`, the
     * export given in place or as a `$<id>` reference to a string row.
     *
     * @return list<string>
     */
    public static function clientComponents(string $payload): array
    {
        $strings = [];
        $imports = [];
        foreach (preg_split('/\n/', $payload) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]+):I(\[.*\])$/s', $line, $m) === 1) {
                $row = json_decode($m[2], true);
                if (\is_array($row) && \is_string($row[2] ?? null)) {
                    $imports[] = $row[2];
                }
            } elseif (preg_match('/^([0-9a-f]+):(".*")$/s', $line, $m) === 1) {
                $value = json_decode($m[2], true);
                if (\is_string($value)) {
                    $strings['$' . $m[1]] = $value;
                }
            }
        }

        $names = array_map(static fn (string $name): string => $strings[$name] ?? $name, $imports);

        return array_values(array_unique($names));
    }
}
