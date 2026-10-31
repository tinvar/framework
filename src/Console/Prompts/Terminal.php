<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Where prompts read keys from and draw to: the process's own terminal
 * (StreamTerminal), or a test's (FakeTerminal).
 */
interface Terminal
{
    /** Whether a person is there to answer: keys can be read and a frame redrawn. */
    public function interactive(): bool;

    /** Whether what is drawn may be coloured. */
    public function colors(): bool;

    /** Whether a spinner may animate from a process of its own while work runs in this one. */
    public function animates(): bool;

    /** How many columns wide it is. */
    public function columns(): int;

    /** How many lines tall it is. */
    public function lines(): int;

    /**
     * The next keys pressed, as the terminal sent them; waits for one.
     * Empty when input has ended.
     */
    public function read(): string;

    public function write(string $text): void;

    /** Puts the terminal in raw mode: each key read as it is pressed, not echoed, ctrl-c a key. */
    public function raw(): void;

    /** Puts it back as raw() found it; nothing if it is not raw. */
    public function restore(): void;
}
