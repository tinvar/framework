<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * A signed token this server will not act on.
 *
 * The reason is for the log. Every cause -- bad signature, wrong session,
 * wrong tenant, wrong build, expired, malformed -- reaches the client as one
 * status with one code, because a caller able to tell them apart could use
 * the difference to probe: "expired" says a token was once valid, "wrong
 * session" says it belongs to someone.
 */
class TokenError extends \RuntimeException {}
