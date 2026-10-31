<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Shift-tab, or ctrl-u with nothing to clear, at a form's prompt past its
 * first: the form asks the step before again. Caught by the form, never
 * thrown outside one.
 *
 * @internal
 */
final class GoBack extends \RuntimeException {}
