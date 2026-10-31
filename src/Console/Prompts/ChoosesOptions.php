<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A list to choose from: filtered by what is typed, a highlighted option
 * moved by the arrows, home and end, page up and down, and a window of
 * $scroll options that scrolls to keep it in view, with a bar beside them
 * when there are more than fit.
 *
 * Options given as a list answer with the option itself; options given
 * keyed answer with the key, as Laravel Prompts' do.
 */
trait ChoosesOptions
{
    /** @var list<array{key: int|string, label: string}> */
    protected array $options = [];

    protected bool $keyed = false;

    protected string $query = '';

    /** @var list<int> the options that match the query, by index */
    protected array $matches = [];

    /** The highlighted match, by its place in $matches; null for none. */
    protected ?int $highlighted = 0;

    /** The first match in view. */
    protected int $first = 0;

    /** @param array<int|string, mixed> $options */
    protected function load(array $options): void
    {
        $this->keyed = !array_is_list($options);
        $this->options = [];
        foreach ($options as $key => $label) {
            if (!\is_scalar($label) && !$label instanceof \Stringable) {
                throw new \InvalidArgumentException("An option's label is text; option '{$key}' is " . get_debug_type($label) . '.');
            }
            $this->options[] = ['key' => $key, 'label' => (string) $label];
        }
        $this->filter();
    }

    /** The options matching the query again, the highlight kept on screen. */
    protected function filter(): void
    {
        $pattern = '/' . preg_quote($this->query, '/') . '/iu';
        $this->matches = [];
        foreach ($this->options as $index => $option) {
            if ($this->query === '' || preg_match($pattern, $option['label']) === 1) {
                $this->matches[] = $index;
            }
        }
        if ($this->highlighted !== null) {
            $this->highlighted = $this->matches === [] ? 0 : min($this->highlighted, \count($this->matches) - 1);
        }
        $this->keepInView();
    }

    /** Whether $key moved the highlight. */
    protected function navigate(string $key): bool
    {
        $count = \count($this->matches);
        if ($count === 0) {
            return \in_array($key, [Key::UP, Key::DOWN, Key::HOME, Key::END, Key::PAGE_UP, Key::PAGE_DOWN], true);
        }
        $at = $this->highlighted;
        $page = $this->visible();
        $next = match ($key) {
            Key::UP => $at === null ? $count - 1 : ($at - 1 + $count) % $count,
            Key::DOWN => $at === null ? 0 : ($at + 1) % $count,
            Key::HOME => 0,
            Key::END => $count - 1,
            Key::PAGE_UP => max(0, ($at ?? 0) - $page),
            Key::PAGE_DOWN => min($count - 1, ($at ?? -1) + $page),
            default => false,
        };
        if ($next === false) {
            return false;
        }
        $this->highlighted = $next;
        $this->keepInView();

        return true;
    }

    /** Whether $key edited the filter: a character typed, backspace, ctrl-u. */
    protected function typeFilter(string $key, bool $spaceFilters = true): bool
    {
        if ($key === Key::BACKSPACE || $key === Key::CTRL_U) {
            if ($this->query === '') {
                return false;
            }
            $chars = Width::chars($this->query);
            $this->query = $key === Key::CTRL_U ? '' : implode('', \array_slice($chars, 0, -1));
        } elseif (Key::printable($key) && ($spaceFilters || $key !== Key::SPACE)) {
            $this->query .= $key;
        } else {
            return false;
        }
        $this->highlighted = $this->highlighted === null ? null : 0;
        $this->first = 0;
        $this->filter();

        return true;
    }

    /** How many options are in view at once, on this terminal. */
    protected function visible(): int
    {
        return max(1, min($this->scroll, $this->lines - 8));
    }

    /**
     * The options in view, each after what $marker draws before it.
     *
     * @param \Closure(array{key: int|string, label: string}, bool): string $marker the option and whether it is highlighted
     * @return list<string>
     */
    protected function rows(Style $style, int $width, \Closure $marker, string $none = 'No match.'): array
    {
        if ($this->matches === []) {
            return [$style->gray($none)];
        }
        $this->keepInView();
        $visible = $this->visible();
        $count = \count($this->matches);
        $bar = $count > $visible;
        $thumb = $bar ? (int) round($this->first / max(1, $count - $visible) * ($visible - 1)) : -1;

        $rows = [];
        foreach (\array_slice($this->matches, $this->first, $visible) as $offset => $index) {
            $place = $this->first + $offset;
            $option = $this->options[$index];
            $prefix = $marker($option, $place === $this->highlighted);
            $room = max(1, $width - Width::of($prefix) - ($bar ? 2 : 0));
            $label = Width::truncate($option['label'], $room);
            $label = $place === $this->highlighted ? $label : $style->dim($label);
            $line = $prefix . $label;
            if ($bar) {
                $line = Width::pad($line, $width - 1) . ($offset === $thumb ? $style->cyan('┃') : $style->gray('│'));
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /** @return array{key: int|string, label: string}|null */
    protected function current(): ?array
    {
        if ($this->highlighted === null) {
            return null;
        }
        $index = $this->matches[$this->highlighted] ?? null;

        return $index === null ? null : $this->options[$index];
    }

    /** @param array{key: int|string, label: string} $option */
    protected function answerOf(array $option): int|string
    {
        return $this->keyed ? $option['key'] : $option['label'];
    }

    /** The option an answer names -- its key where keyed, itself otherwise -- by index. */
    protected function indexOf(mixed $answer): ?int
    {
        foreach ($this->options as $index => $option) {
            // A key PHP made an integer is still named by its digits.
            $matches = $this->keyed
                ? $option['key'] === $answer || (\is_int($option['key']) && \is_string($answer) && (string) $option['key'] === $answer)
                : $option['label'] === $answer;
            if ($matches) {
                return $index;
            }
        }

        return null;
    }

    /** What may be answered, for a message saying an answer is not one of them. */
    protected function choices(): string
    {
        $choices = array_map(fn (array $option): string => var_export($this->answerOf($option), true), \array_slice($this->options, 0, 10));

        return implode(', ', $choices) . (\count($this->options) > 10 ? ', …' : '');
    }

    /** Highlights the option at $index, clearing the filter if it hides it. */
    protected function highlightIndex(int $index): void
    {
        $place = array_search($index, $this->matches, true);
        if ($place === false) {
            $this->query = '';
            $this->filter();
            $place = array_search($index, $this->matches, true);
        }
        $this->highlighted = $place === false ? 0 : $place;
        $this->keepInView();
    }

    private function keepInView(): void
    {
        $visible = $this->visible();
        $at = $this->highlighted ?? 0;
        if ($at < $this->first) {
            $this->first = $at;
        } elseif ($at >= $this->first + $visible) {
            $this->first = $at - $visible + 1;
        }
        $this->first = max(0, min($this->first, \count($this->matches) - $visible));
    }
}
