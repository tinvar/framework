<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * A five-field cron expression -- minute, hour, day of month, month, day of
 * week -- and whether a minute matches it.
 *
 * Numbers, `*`, ranges (`1-5`), lists (`1,15`), steps (`*\/15`, `0-30/10`),
 * and `0` or `7` for Sunday. Day of month and day of week combine as cron
 * combines them: when both are restricted, either one matching is enough.
 * No names, no `L`, `W` or `#`: a schedule that needs them is rare enough to
 * write as a check inside the task.
 *
 * Read in UTC, always. A schedule read in the server's zone runs twice, or
 * not at all, across a daylight-saving change.
 */
final readonly class Cron
{
    private const array FIELDS = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day of month' => [1, 31],
        'month' => [1, 12],
        'day of week' => [0, 7],
    ];

    /**
     * @param array<string, list<int>> $allowed each field's matching values
     * @param array<string, bool> $restricted whether each field was anything but `*`
     */
    private function __construct(
        public string $expression,
        private array $allowed,
        private array $restricted,
    ) {}

    public static function parse(string $expression): self
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (\count($parts) !== 5) {
            throw new \InvalidArgumentException("'{$expression}' is not a cron expression: it needs five fields, minute hour day month weekday.");
        }

        $allowed = [];
        $restricted = [];
        foreach (array_keys(self::FIELDS) as $index => $field) {
            [$min, $max] = self::FIELDS[$field];
            $allowed[$field] = self::field($parts[$index], $min, $max, $field, $expression);
            $restricted[$field] = !str_starts_with($parts[$index], '*');
        }

        // Sunday is both 0 and 7.
        if (\in_array(7, $allowed['day of week'], true) && !\in_array(0, $allowed['day of week'], true)) {
            $allowed['day of week'][] = 0;
        }

        return new self($expression, $allowed, $restricted);
    }

    /** Whether the minute $at falls in, read in UTC, is one this expression runs at. */
    public function matches(\DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));

        if (!\in_array((int) $at->format('i'), $this->allowed['minute'], true)
            || !\in_array((int) $at->format('G'), $this->allowed['hour'], true)
            || !\in_array((int) $at->format('n'), $this->allowed['month'], true)) {
            return false;
        }

        $day = \in_array((int) $at->format('j'), $this->allowed['day of month'], true);
        $weekday = \in_array((int) $at->format('w'), $this->allowed['day of week'], true);

        return $this->restricted['day of month'] && $this->restricted['day of week'] ? $day || $weekday : $day && $weekday;
    }

    /** @return list<int> */
    private static function field(string $text, int $min, int $max, string $field, string $expression): array
    {
        $values = [];
        foreach (explode(',', $text) as $item) {
            if (preg_match('#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?\z#', $item, $m) !== 1) {
                throw new \InvalidArgumentException("'{$expression}': '{$item}' is not a {$field}.");
            }

            $start = $m[2] ?? '';
            $end = $m[3] ?? '';
            [$from, $to] = $m[1] === '*' ? [$min, $max] : [(int) $start, $end === '' ? (int) $start : (int) $end];
            // A bare `5/15` steps from 5 to the end, as cron reads it.
            if ($m[1] !== '*' && $end === '' && ($m[4] ?? '') !== '') {
                $to = $max;
            }
            $step = ($m[4] ?? '') === '' ? 1 : (int) $m[4];

            if ($from < $min || $to > $max || $from > $to || $step < 1) {
                throw new \InvalidArgumentException("'{$expression}': '{$item}' is outside a {$field}'s {$min}-{$max}.");
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
