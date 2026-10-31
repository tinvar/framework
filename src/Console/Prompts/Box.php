<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * The box every prompt is drawn in, as Laravel Prompts draws one:
 *
 *      ┌ What is the model called? ─────────────────── 2/5 ┐
 *      │ Rack                                              │
 *      └───────────────────────────────────────────────────┘
 *        A hint, or what is wrong with the answer
 *
 * At most 64 columns wide, and as narrow as the terminal: the title, info
 * and body are cut to fit, never wrapped out of the box.
 */
final class Box
{
    public const int MAX_WIDTH = 64;

    /** The box's outer width on a terminal $columns wide. */
    public static function width(int $columns): int
    {
        // One column of margin before it, and one the screen keeps free.
        return max(8, min(self::MAX_WIDTH, $columns - 2));
    }

    /** The columns inside the box for its body. */
    public static function inner(int $columns): int
    {
        return self::width($columns) - 4;
    }

    /**
     * @param list<string> $body each line, already no wider than inner()
     * @param list<string> $below lines under the box: a hint, an error
     * @return list<string>
     */
    public static function draw(Style $style, string $color, string $title, array $body, int $columns, string $info = '', string $footer = '', array $below = []): array
    {
        $width = self::width($columns);
        $inner = $width - 4;
        $paint = static fn (string $text): string => $style->color($color, $text);

        $info = $info === '' ? '' : Width::truncate($info, max(0, $inner - 4));
        $room = $inner - ($info === '' ? 0 : Width::of($info) + 2);
        $title = Width::truncate($title, max(1, $room));
        $rule = max(0, $width - 4 - Width::of($title) - ($info === '' ? 0 : Width::of($info) + 2));
        $lines = [' ' . $paint('┌') . ' ' . $style->bold($title) . ' ' . $paint(str_repeat('─', $rule) . ($info === '' ? '' : ' ')) . ($info === '' ? '' : $style->gray($info) . ' ') . $paint('┐')];

        foreach ($body === [] ? [''] : $body as $line) {
            $line = Width::truncate($line, $inner);
            $lines[] = ' ' . $paint('│') . ' ' . Width::pad($line, $inner) . ' ' . $paint('│');
        }

        $footer = $footer === '' ? '' : Width::truncate($footer, max(0, $inner - 2));
        $lines[] = $footer === ''
            ? ' ' . $paint('└' . str_repeat('─', $width - 2) . '┘')
            : ' ' . $paint('└') . ' ' . $style->gray($footer) . ' ' . $paint(str_repeat('─', max(0, $width - 4 - Width::of($footer))) . '┘');

        foreach ($below as $line) {
            $lines[] = '   ' . $line;
        }

        return $lines;
    }
}
