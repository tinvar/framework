<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Ctrl-C at a prompt, or a question a person said no to that the command
 * cannot go on without: the person stopped the command. The console that
 * ran it exits 130, as a shell does for an interrupt, and reports nothing
 * to the application's log -- stopping is not a failure.
 */
final class Cancelled extends \RuntimeException
{
    /** The exit status for a command cancelled at a prompt. */
    public const int STATUS = 130;
}
