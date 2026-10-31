<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A terminal for tests: keys scripted ahead, each read() handing over the
 * next as a terminal would -- a string of keys at once, as typed or pasted
 * -- and everything written played through a small screen, which knows the
 * cursor movement and erasing prompts redraw with, so a test sees what a
 * person would: each frame as drawn, and the screen as it is left.
 *
 *     $terminal = Prompts::fakeTerminal(['Ada', Key::ENTER]);
 *     Prompts::text('Name?');
 *     expect($terminal->screen())->toContain('Ada');
 *
 * Reading past the last key fails the test, rather than hanging it.
 */
final class FakeTerminal implements Terminal
{
    /** @var list<string> */
    private array $keys;

    private string $written = '';

    /** @var list<string> the screen after each write, plain */
    private array $frames = [];

    /** @var list<list<string>> each line's cells: a character, or '' behind a wide one */
    private array $cells = [[]];

    private int $row = 0;

    private int $column = 0;

    private bool $raw = false;

    /** How many times raw mode was entered, and how many left. */
    public int $rawEntered = 0;

    public int $restored = 0;

    /**
     * @param list<string> $keys what each read() returns, in order: text as typed, or Key constants
     */
    public function __construct(
        array $keys = [],
        private int $columns = 80,
        private readonly int $lines = 24,
        private readonly bool $colors = false,
    ) {
        $this->keys = $keys;
    }

    /** More keys, read after those already given. */
    public function press(string ...$keys): self
    {
        array_push($this->keys, ...array_values($keys));

        return $this;
    }

    /** A narrower or wider terminal from now on. */
    public function resize(int $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    public function interactive(): bool
    {
        return true;
    }

    public function colors(): bool
    {
        return $this->colors;
    }

    public function animates(): bool
    {
        return false;
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function lines(): int
    {
        return $this->lines;
    }

    public function read(): string
    {
        if ($this->keys === []) {
            throw new \LogicException("The fake terminal has no keys left to read; its screen:\n" . $this->screen());
        }

        return array_shift($this->keys);
    }

    public function write(string $text): void
    {
        $this->written .= $text;
        $this->play($text);
        // A write that changed nothing on screen -- showing the cursor --
        // is no frame.
        $screen = $this->screen();
        if ($this->frames === [] || end($this->frames) !== $screen) {
            $this->frames[] = $screen;
        }
    }

    public function raw(): void
    {
        $this->raw = true;
        ++$this->rawEntered;
    }

    public function restore(): void
    {
        if ($this->raw) {
            $this->raw = false;
            ++$this->restored;
        }
    }

    /** Whether it is in raw mode now. */
    public function isRaw(): bool
    {
        return $this->raw;
    }

    /** Keys given and not yet read. */
    public function unread(): int
    {
        return \count($this->keys);
    }

    /** Everything written, escape codes and all. */
    public function output(): string
    {
        return $this->written;
    }

    /** The last frame drawn: the screen as it is now. */
    public function lastFrame(): string
    {
        return $this->frames === [] ? '' : $this->frames[\count($this->frames) - 1];
    }

    /**
     * The screen after each write that changed it, plain: each frame a
     * prompt drew.
     *
     * @return list<string>
     */
    public function frames(): array
    {
        return $this->frames;
    }

    /** The screen as it is now: each line's text, trailing spaces and empty last lines dropped. */
    public function screen(): string
    {
        $lines = array_map(static fn (array $cells): string => rtrim(implode('', $cells)), $this->cells);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    private function play(string $text): void
    {
        $parts = preg_split('/(\e\[[0-9;?]*[@-~]|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            match (true) {
                $part === "\n" => $this->newline(),
                $part === "\r" => $this->column = 0,
                $part[0] === "\e" => $this->control($part),
                default => $this->put($part),
            };
        }
    }

    private function newline(): void
    {
        ++$this->row;
        $this->column = 0;
        $this->cells[$this->row] ??= [];
    }

    private function control(string $sequence): void
    {
        if (preg_match('/^\e\[(\d*)([A-DGJK])\z/', $sequence, $m) !== 1) {
            // Colour, and showing or hiding the cursor: nothing on the screen.
            return;
        }
        $n = $m[1] === '' ? 1 : (int) $m[1];
        match ($m[2]) {
            'A' => $this->row = max(0, $this->row - $n),
            'B' => $this->row += $n,
            'C' => $this->column += $n,
            'D' => $this->column = max(0, $this->column - $n),
            'G' => $this->column = max(0, $n - 1),
            'J' => $this->eraseDown(),
            default => $this->cells[$this->row] = \array_slice($this->cells[$this->row] ?? [], 0, $this->column),
        };
        $this->cells[$this->row] ??= [];
    }

    private function eraseDown(): void
    {
        $this->cells[$this->row] = \array_slice($this->cells[$this->row] ?? [], 0, $this->column);
        $this->cells = \array_slice($this->cells, 0, $this->row + 1);
    }

    private function put(string $text): void
    {
        foreach (Width::chars($text) as $char) {
            $width = Width::ofChar($char);
            if ($width === 0) {
                continue;
            }
            if ($this->column + $width > $this->columns) {
                $this->newline();
            }
            $line = $this->cells[$this->row] ?? [];
            while (\count($line) < $this->column) {
                $line[] = ' ';
            }
            // Half a wide character overwritten takes the other half with it.
            foreach ($width === 2 ? [$this->column, $this->column + 1] : [$this->column] as $cell) {
                if (($line[$cell] ?? null) === '' && $cell > 0) {
                    $line[$cell - 1] = ' ';
                }
                if (isset($line[$cell + 1]) && $line[$cell + 1] === '' && $cell === $this->column + $width - 1) {
                    $line[$cell + 1] = ' ';
                }
            }
            $line[$this->column] = $char;
            if ($width === 2) {
                $line[$this->column + 1] = '';
            }
            ksort($line);
            $this->cells[$this->row] = array_values($line);
            $this->column += $width;
        }
    }
}
