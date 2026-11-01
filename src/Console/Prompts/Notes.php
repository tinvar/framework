<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A message between prompts, wrapped to the terminal: a note plain, info
 * green, a warning yellow, an error red, an alert in a red band that is
 * hard to miss.
 */
final class Notes
{
    public const array TYPES = ['note', 'info', 'warning', 'error', 'alert'];

    /** @return list<string> */
    public static function lines(Style $style, string $type, string $message, int $columns): array
    {
        $width = max(10, min(Box::MAX_WIDTH, $columns - 2) - 4);
        $wrapped = Width::wrap(Width::clean($message, "\n"), $width);

        if ($type === 'alert') {
            $band = static fn (string $line): string => ' ' . $style->color('alert', ' ' . Width::pad($line, $width) . ' ');
            $blank = $band('');

            return [$blank, ...array_map($band, $wrapped), $blank];
        }

        [$mark, $color] = match ($type) {
            'info' => ['●', 'green'],
            'warning' => ['▲', 'yellow'],
            'error' => ['✖', 'red'],
            'note' => ['', ''],
            default => throw new \InvalidArgumentException("No kind of note '{$type}': " . implode(', ', self::TYPES) . '.'),
        };

        $lines = [];
        foreach ($wrapped as $i => $line) {
            $lead = $mark === '' ? '' : ($i === 0 ? $mark : ' ') . ' ';
            $lines[] = ' ' . ($color === '' ? $lead . $line : $style->color($color, $lead . $line));
        }

        return $lines;
    }
}
