<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * @internal AuditLog's: where a tenant's chain breaks under prune(), so the
 * batch's transaction rolls back with nothing taken and no anchor signed
 * (ADR 0209). Its message is the break, by place, never what an entry holds.
 */
final class ChainBroken extends \RuntimeException {}
