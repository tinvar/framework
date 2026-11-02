<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Tinvar\Attributes\Config;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;

/**
 * How long the audit log keeps its entries (ADR 0155), as
 * TINVAR_AUDIT_RETENTION_DAYS: whole days, 1 to 36,500, a year when unset.
 * PruneAuditLog takes away what is older each night.
 *
 * Read as the framework's other variables are (ADR 0085): once per worker,
 * a value out of range refused at boot naming the variable.
 */
#[Config(prefix: 'TINVAR_AUDIT_')]
final readonly class AuditConfig
{
    /** @param int|null $retentionDays how long an entry is kept, in days; a year unless given */
    public function __construct(
        public ?int $retentionDays = null,
    ) {
        if ($retentionDays !== null && ($retentionDays < 1 || $retentionDays > 36_500)) {
            throw new ConfigException(['TINVAR_AUDIT_RETENTION_DAYS must be whole days, from 1 to 36500.']);
        }
    }

    /**
     * As the environment has it now.
     *
     * @throws ConfigException for a value out of range
     */
    public static function load(): self
    {
        return Configs::load(self::class);
    }

    public function retentionDays(): int
    {
        return $this->retentionDays ?? AuditLog::DEFAULT_RETENTION_DAYS;
    }
}
