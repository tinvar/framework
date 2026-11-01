<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * A resumable session's claim found taken over while its keep ran -- the
 * keep outlived its lease (UploadStore::SESSION_CLAIM_LEASE) -- so the keep
 * stops and touches nothing of the session (ResumableUploads).
 *
 * @internal
 */
final class ClaimLost extends \RuntimeException {}
