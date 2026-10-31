<?php

declare(strict_types=1);

namespace Tinvar\Live;

/**
 * An update that did not reach the hub: it could not be reached, it failed,
 * or it was skipped -- the hub taken for down, or the request's time for
 * publishing spent (HubLink). Logged by whoever published it, never a
 * failed write: the write it tells of is committed.
 */
final class HubUnavailable extends \RuntimeException {}
