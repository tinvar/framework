<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * A resumable upload found completed, abandoned or run out under the lock
 * on its bytes, so the chunk in hand is not written (ResumableUploads).
 *
 * @internal
 */
final class SessionClosed extends \RuntimeException {}
