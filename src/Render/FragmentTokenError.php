<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * A fragment token this server will not act on.
 *
 * Extends TokenError so the two kinds of signed token share one failure
 * family: a caller that handles one handles both, and a check written for
 * one cannot quietly stop applying to the other.
 */
final class FragmentTokenError extends TokenError {}
