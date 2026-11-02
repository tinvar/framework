<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

/** How a doctor's finding stands (ADR 0157). */
enum Severity: string
{
    /** Checked, and as it should be. */
    case Passed = 'ok';

    /** Works, but likely not as meant: said with what to do, and the exit stays 0. */
    case Warning = 'warning';

    /** What stops the app booting, serving or being safe: said with the fix, and the exit is 1. */
    case Problem = 'problem';

    /** Not checked here, and why: nothing to check, or another check failed first. */
    case Skipped = 'skipped';
}
