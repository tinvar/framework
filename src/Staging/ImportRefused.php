<?php

declare(strict_types=1);

namespace Tinvar\Staging;

/**
 * The staging import stopped, and wrote nothing: a table or column with no
 * rule, a rule for one there is none of, a key that is production's, a
 * value that would not open.
 */
final class ImportRefused extends \RuntimeException {}
