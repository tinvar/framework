<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A line of text being typed, and the keys that edit it as readline does:
 * left and right, home and end (ctrl-a, ctrl-e), backspace and delete,
 * ctrl-u to clear what is before the cursor and ctrl-w the word before it.
 * The cursor counts characters, not bytes.
 */
trait EditsText
{
    protected string $value = '';

    protected int $cursor = 0;

    /** Whether $key edited the text: the caller handles any other. */
    protected function edit(string $key): bool
    {
        $chars = Width::chars($this->value);
        $count = \count($chars);
        switch ($key) {
            case Key::LEFT:
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case Key::RIGHT:
                $this->cursor = min($count, $this->cursor + 1);

                return true;
            case Key::HOME:
            case Key::CTRL_A:
                $this->cursor = 0;

                return true;
            case Key::END:
            case Key::CTRL_E:
                $this->cursor = $count;

                return true;
            case Key::BACKSPACE:
                if ($this->cursor > 0) {
                    array_splice($chars, $this->cursor - 1, 1);
                    --$this->cursor;
                    $this->value = implode('', $chars);
                }

                return true;
            case Key::DELETE:
                if ($this->cursor < $count) {
                    array_splice($chars, $this->cursor, 1);
                    $this->value = implode('', $chars);
                }

                return true;
            case Key::CTRL_U:
                $this->value = implode('', \array_slice($chars, $this->cursor));
                $this->cursor = 0;

                return true;
            case Key::CTRL_W:
                $kept = \array_slice($chars, 0, $this->cursor);
                while ($kept !== [] && end($kept) === ' ') {
                    array_pop($kept);
                }
                while ($kept !== [] && end($kept) !== ' ') {
                    array_pop($kept);
                }
                $this->value = implode('', $kept) . implode('', \array_slice($chars, $this->cursor));
                $this->cursor = \count($kept);

                return true;
        }
        if (Key::printable($key)) {
            array_splice($chars, $this->cursor, 0, [$key]);
            ++$this->cursor;
            $this->value = implode('', $chars);

            return true;
        }

        return false;
    }

    /** Puts $text in, the cursor at its end. */
    protected function replace(string $text): void
    {
        $this->value = $text;
        $this->cursor = \count(Width::chars($text));
    }

    /**
     * The text as a line $width wide, with the cursor drawn on it, scrolled
     * so the cursor is always in view; the placeholder, dim, where nothing
     * is typed.
     */
    protected function field(Style $style, int $width, ?string $shown = null, string $placeholder = ''): string
    {
        $text = $shown ?? $this->value;
        if ($text === '' && $placeholder !== '') {
            $chars = Width::chars($placeholder);

            return $style->cursor($chars[0] ?? ' ') . $style->gray(Width::truncate(implode('', \array_slice($chars, 1)), max(0, $width - 1)));
        }

        return self::window($style, $text, $this->cursor, $width);
    }

    /** $text in $width columns, scrolled to keep the cursor at $cursor in view, drawn on it. */
    protected static function window(Style $style, string $text, int $cursor, int $width): string
    {
        $chars = Width::chars($text);
        $before = \array_slice($chars, 0, $cursor);
        $at = $chars[$cursor] ?? ' ';
        $after = implode('', \array_slice($chars, $cursor + 1));

        $cut = false;
        while ($before !== [] && Width::of(implode('', $before)) + Width::ofChar($at) + ($cut ? 1 : 0) > $width) {
            array_shift($before);
            $cut = true;
        }
        $left = ($cut ? '…' : '') . implode('', $before);
        $room = max(0, $width - Width::of($left) - Width::ofChar($at));

        return $left . $style->cursor($at) . Width::truncate($after, $room);
    }
}
