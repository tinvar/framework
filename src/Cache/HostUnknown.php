<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * A query cache key that must name a host, asked for where none is known
 * (QueryCache::keyHostsBy()): the call is not cached, never keyed as no
 * host's.
 */
final class HostUnknown extends \RuntimeException {}
