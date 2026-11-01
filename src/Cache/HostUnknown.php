<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * A cache key that must name a host, asked for where none is known: the
 * call is not cached, never keyed as no host's (ADR 0149).
 */
final class HostUnknown extends \RuntimeException {}
