<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A progress bar, advanced by hand or by Prompts::progress() over what it
 * is given:
 *
 *     $progress = Prompts::progress('Importing', count($rows));
 *     foreach ($rows as $row) {
 *         import($row);
 *         $progress->advance();
 *     }
 *     $progress->finish();
 *
 * Redrawn when its percentage, label or hint changes, or a tenth of a
 * second after it was last drawn -- never once for each of twenty thousand
 * steps. Where nobody is watching it draws nothing: a log gets the
 * command's own lines, not a bar's frames. Without a total -- a generator
 * -- it counts instead.
 *
 * Lines printed while it is drawn go through above(), which takes the bar
 * off, prints them, and draws it again under them.
 */
final class Progress
{
    private int $done = 0;

    private bool $started = false;

    private bool $finished = false;

    private float $drawnAt = 0.0;

    private ?string $drawnKey = null;

    private readonly ?Screen $screen;

    /** Whether some bar left the cursor hidden: shown again at shutdown, whatever stopped it. */
    private static bool $hiding = false;

    /**
     * @param Terminal|null $terminal where it is drawn; null where nobody watches
     * @param Fake|null $fake a test's, told the label and count once finished
     */
    public function __construct(
        private string $label,
        public readonly ?int $total = null,
        private string $hint = '',
        private readonly ?Terminal $terminal = null,
        private readonly ?Fake $fake = null,
    ) {
        if ($total !== null && $total < 0) {
            throw new \InvalidArgumentException('A progress bar counts to 0 or more.');
        }
        $this->screen = $terminal === null ? null : new Screen($terminal);
    }

    public function start(): self
    {
        if (!$this->started) {
            $this->started = true;
            $this->draw(true);
        }

        return $this;
    }

    public function advance(int $by = 1): self
    {
        $this->start();
        $this->done = $this->total === null ? $this->done + $by : min($this->total, $this->done + $by);
        $this->draw(false);

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        $this->draw(false);

        return $this;
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;
        $this->draw(false);

        return $this;
    }

    /** How many steps are done. */
    public function done(): int
    {
        return $this->done;
    }

    /** Draws it done, left on screen. */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->start();
        if ($this->total !== null) {
            $this->done = $this->total;
        }
        $this->finished = true;
        $this->draw(true);
        $this->release();
        $this->fake?->said($this->label . ': ' . $this->done . ($this->total === null ? '' : "/{$this->total}"));
    }

    /** Leaves the bar where it is, the cursor shown: for work that stopped part way. */
    public function release(): void
    {
        if ($this->screen !== null && $this->screen->drawn() > 0) {
            $this->terminal?->write(Screen::SHOW_CURSOR);
            $this->screen->release();
        }
    }

    /** Takes the bar off while $print prints, then draws it again under what it printed. */
    public function above(\Closure $print): void
    {
        if ($this->screen === null || !$this->started || $this->finished) {
            $print();

            return;
        }
        $this->screen->clear();
        $print();
        $this->draw(true);
    }

    /** @return list<string> */
    public function frame(Style $style, int $columns): array
    {
        $inner = Box::inner($columns);
        if ($this->total === null) {
            $body = [$this->done . ' done'];
            $info = '';
        } else {
            $filled = $this->total === 0 ? $inner : (int) floor($this->done / $this->total * $inner);
            $body = [$style->cyan(str_repeat('█', $filled)) . $style->gray(str_repeat('░', $inner - $filled))];
            $info = "{$this->done}/{$this->total}";
        }

        return Box::draw($style, $this->finished ? 'gray' : 'cyan', $this->label, $body, $columns, $info, below: $this->hint === '' ? [] : [$style->gray($this->hint)]);
    }

    private function draw(bool $force): void
    {
        if ($this->screen === null || $this->terminal === null || !$this->started) {
            return;
        }
        $percent = $this->total === null || $this->total === 0 ? $this->done : intdiv($this->done * 100, $this->total);
        $key = "{$percent}\0{$this->label}\0{$this->hint}";
        $now = hrtime(true) / 1e9;
        if (!$force && $key === $this->drawnKey && $now - $this->drawnAt < 0.1) {
            return;
        }
        $first = $this->screen->drawn() === 0;
        if ($first && !self::$hiding) {
            self::$hiding = true;
            $terminal = $this->terminal;
            register_shutdown_function(static fn () => $terminal->write(Screen::SHOW_CURSOR));
        }
        $this->screen->draw($this->frame(new Style($this->terminal->colors()), $this->terminal->columns()), $first ? Screen::HIDE_CURSOR : '');
        $this->drawnKey = $key;
        $this->drawnAt = $now;
    }
}
