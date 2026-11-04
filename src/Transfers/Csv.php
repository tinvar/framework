<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * CSV as transfers write and read it: RFC 4180, UTF-8, comma-separated,
 * `"`-quoted with no backslash escape, and CRLF between records.
 *
 * Written, a cell is held against CSV injection: a spreadsheet reads a cell
 * beginning `=`, `+`, `-` or `@` -- or their full-width forms, or a tab or
 * carriage return ahead of one -- as a formula, and a formula can fetch URLs and run commands on the
 * machine that opens the file. Every such text cell is written with a
 * leading `'`, which the spreadsheet shows as text, as OWASP recommends. A
 * number is written as PHP formats it and is never prefixed: `-5` from an
 * int is a number, and no int or float formats as a formula.
 *
 * Read, nothing is evaluated: a cell is the text it holds. The one thing
 * undone is that prefix -- a `'` ahead of one of those characters is
 * dropped -- so a file this application exported reads back as the values
 * it was written from: text that itself begins with `'` and one of them is
 * written with a prefix of its own. What a cell holds is still only ever text, which
 * the row's form request coerces and validates.
 */
final class Csv
{
    /**
     * Text a spreadsheet may read as a formula -- beginning `=`, `+`, `-`,
     * `@`, a tab or a carriage return, or the full-width `＝`, `＋`, `－` or
     * `＠` a spreadsheet in a CJK locale reads as them -- or that begins with
     * `'`s ahead of one of them. Matched on the bytes, not as UTF-8, so text
     * that is not valid UTF-8 is held to it too.
     */
    private const string FORMULA = "/^'*(?:[=+\\-@\t\r]|\xEF\xBC[\x9D\x8B\x8D\xA0])/";

    /** The byte-order mark a spreadsheet may begin a UTF-8 file with. */
    private const string BOM = "\xEF\xBB\xBF";

    /** The longest cell read: a field is a value, not a document. */
    public const int MAX_CELL_BYTES = 65_536;

    /** One cell, as written. */
    public static function cell(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            \is_float($value) => is_finite($value)
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                : throw new \InvalidArgumentException('A CSV cell cannot hold a number that is not finite.'),
            \is_string($value) => self::text($value),
            default => throw new \InvalidArgumentException('A CSV cell holds a scalar, never ' . get_debug_type($value) . '.'),
        };
    }

    /**
     * Text, prefixed with `'` where a spreadsheet would read it as a
     * formula -- and where it begins with `'`s ahead of a formula
     * character, so that value() takes off the prefix alone and never one
     * of the text's own.
     */
    private static function text(string $value): string
    {
        return preg_match(self::FORMULA, $value) === 1 ? "'" . $value : $value;
    }

    /**
     * Writes one record.
     *
     * @param resource $stream
     * @param list<mixed> $values
     */
    public static function write(mixed $stream, array $values): void
    {
        if (fputcsv($stream, array_map(self::cell(...), $values), ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('A CSV record could not be written.');
        }
    }

    /**
     * A cell as read, with the injection prefix this class writes taken
     * off again.
     */
    public static function value(string $cell): string
    {
        return $cell !== '' && $cell[0] === "'" && preg_match(self::FORMULA, substr($cell, 1)) === 1 ? substr($cell, 1) : $cell;
    }

    /**
     * Every record of a CSV stream, numbered as a spreadsheet numbers its
     * rows -- the header is row 1 -- with its cells checked, as read, to be
     * UTF-8 text with no NUL and no longer than a cell may be. A blank line
     * is no record, but is counted, as a spreadsheet shows it as a row of
     * its own. A byte-order mark at the start is skipped.
     *
     * @param resource $stream
     * @return \Generator<int, list<string>> row => cells
     * @throws CsvRefused for a file that is not CSV text
     */
    public static function read(mixed $stream): \Generator
    {
        $head = fread($stream, 3);
        if ($head !== self::BOM) {
            rewind($stream);
        }

        $row = 0;
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            // Counted before it is skipped: a spreadsheet shows a blank line
            // as a row, and the rows after it are numbered past it.
            $row++;
            if ($record === [null]) {
                continue;
            }

            $cells = [];
            foreach ($record as $cell) {
                $cell = (string) $cell;
                if (\strlen($cell) > self::MAX_CELL_BYTES) {
                    throw new CsvRefused('csv_cell', "Row {$row} has a cell longer than " . self::MAX_CELL_BYTES . ' bytes.');
                }
                if (str_contains($cell, "\0") || !mb_check_encoding($cell, 'UTF-8')) {
                    throw new CsvRefused('csv_encoding', "Row {$row} is not UTF-8 text.");
                }
                $cells[] = $cell;
            }

            yield $row => $cells;
        }
    }
}
