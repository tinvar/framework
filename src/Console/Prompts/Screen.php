<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Draws a frame, and draws the next over it: back up the lines the last
 * one took, erase to the end of the screen, write the new one -- all in one
 * write, so a frame is never seen half drawn.
 *
 * Each line is cut to one column short of the terminal's width, so none
 * wraps: a wrapped line would take two lines while the count said one, and
 * the next frame would be drawn a line low.
 */
final class Screen
{
    public const string HIDE_CURSOR = "\e[?25l";

    public const string SHOW_CURSOR = "\e[?25h";

    private int $drawn = 0;

    public function __construct(private readonly Terminal $terminal) {}

    /** @param list<string> $lines */
    public function draw(array $lines, string $prefix = ''): void
    {
        $width = max(1, $this->terminal->columns() - 1);
        $out = $prefix . $this->erasing();
        foreach ($lines as $line) {
            $out .= Width::truncate($line, $width) . "\n";
        }
        $this->terminal->write($out);
        $this->drawn = \count($lines);
    }

    /** Erases the last frame, leaving the cursor where it began. */
    public function clear(): void
    {
        if ($this->drawn > 0) {
            $this->terminal->write($this->erasing());
            $this->drawn = 0;
        }
    }

    /** Leaves the last frame on screen: the next is drawn below it. */
    public function release(): void
    {
        $this->drawn = 0;
    }

    /** How many lines the last frame took. */
    public function drawn(): int
    {
        return $this->drawn;
    }

    private function erasing(): string
    {
        return $this->drawn === 0 ? '' : "\e[{$this->drawn}A\r\e[J";
    }
}
