<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * A fragment token this server will not act on.
 *
 * The reason is for the log. Every cause -- bad signature, wrong session,
 * wrong build, expired, malformed -- reaches the client as one status with
 * one code, because a caller able to tell them apart could use the difference
 * to probe: "expired" says a token was once valid, "wrong session" says it
 * belongs to someone, "wrong build" says the shape is right.
 */
final class FragmentTokenError extends \RuntimeException {}
