<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Rows in a box, each column as wide as its widest cell -- measured as a
 * terminal draws it, so a column of CJK or emoji lines up -- and, on a
 * terminal too narrow for them all, the widest columns cut, with an
 * ellipsis, until it fits.
 */
final class Table
{
    /**
     * @param list<string> $headers
     * @param list<array<array-key, scalar|\Stringable|null>> $rows
     * @return list<string>
     */
    public static function lines(Style $style, array $headers, array $rows, int $columns): array
    {
        $cells = array_map(static fn (array $row): array => array_map(
            static fn (mixed $cell): string => str_replace(["\r\n", "\n", "\t"], ' ', match (true) {
                $cell === null => '',
                \is_bool($cell) => $cell ? 'true' : 'false',
                default => (string) $cell,
            }),
            array_values($row),
        ), $rows);
        $count = max(\count($headers), ...array_map(\count(...), $cells ?: [[]]));
        if ($count === 0) {
            return [];
        }

        $widths = array_fill(0, $count, 0);
        foreach ([$headers, ...$cells] as $row) {
            for ($i = 0; $i < $count; ++$i) {
                $widths[$i] = max($widths[$i], Width::of($row[$i] ?? ''));
            }
        }
        // ' ' + '│' + each ' cell │'
        $budget = $columns - 1 - 2 - 3 * $count;
        while (array_sum($widths) > $budget && max($widths) > 1) {
            $widest = array_search(max($widths), $widths, true);
            --$widths[$widest];
        }

        $rule = static fn (string $left, string $middle, string $right): string => ' ' . $style->gray(
            $left . implode($middle, array_map(static fn (int $width): string => str_repeat('─', $width + 2), $widths)) . $right,
        );
        $line = static function (array $row, bool $header) use ($widths, $style, $count): string {
            $out = [];
            for ($i = 0; $i < $count; ++$i) {
                $cell = Width::pad(Width::truncate($row[$i] ?? '', $widths[$i]), $widths[$i]);
                $out[] = ' ' . ($header ? $style->bold($cell) : $cell) . ' ';
            }

            return ' ' . $style->gray('│') . implode($style->gray('│'), $out) . $style->gray('│');
        };

        $lines = [$rule('┌', '┬', '┐')];
        if ($headers !== []) {
            $lines[] = $line($headers, true);
            $lines[] = $rule('├', '┼', '┤');
        }
        foreach ($cells as $row) {
            $lines[] = $line($row, false);
        }
        $lines[] = $rule('└', '┴', '┘');

        return $lines;
    }
}
