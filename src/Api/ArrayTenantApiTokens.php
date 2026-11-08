<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * Tenant API tokens in this process's memory: for a test, or an
 * application trying them out. Every worker has its own; production keeps
 * them in DatabaseTenantApiTokens.
 */
final class ArrayTenantApiTokens implements TenantApiTokenStore
{
    /** @var array<string, array{TenantApiToken, string}> by id */
    public array $tokens = [];

    /** @var array<string, int> by tenant */
    public array $epochs = [];

    /** @var list<string> the tenants exclusively() is running for now, innermost last */
    public array $held = [];

    /** Called each time an epoch is read: what a test watches the order of reads by. */
    public ?\Closure $onEpoch = null;

    public function insert(TenantApiToken $token, string $digest): void
    {
        if (isset($this->tokens[$token->id])) {
            throw new \LogicException("A tenant API token with the id {$token->id} is kept already.");
        }
        $this->tokens[$token->id] = [$token, $digest];
    }

    public function find(string $id): ?array
    {
        return $this->tokens[$id] ?? null;
    }

    public function forTenant(string $tenant, string $panel): array
    {
        $held = array_values(array_filter(
            array_map(static fn (array $entry): TenantApiToken => $entry[0], $this->tokens),
            static fn (TenantApiToken $t): bool => $t->tenant === $tenant && $t->panel === $panel,
        ));
        usort($held, static fn (TenantApiToken $a, TenantApiToken $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $held;
    }

    public function revoke(string $tenant, string $panel, string $id, CarbonImmutable $at): bool
    {
        [$token, $digest] = $this->tokens[$id] ?? [null, ''];
        if ($token === null || $token->tenant !== $tenant || $token->panel !== $panel || $token->revokedAt !== null) {
            return false;
        }
        $this->tokens[$id] = [$token->withRevokedAt($at), $digest];

        return true;
    }

    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void
    {
        [$token, $digest] = $this->tokens[$id] ?? [null, ''];
        if ($token === null || ($token->lastUsedAt !== null && $token->lastUsedAt->greaterThan($since))) {
            return;
        }
        $this->tokens[$id] = [$token->withLastUsedAt($at), $digest];
    }

    public function exclusively(string $tenant, \Closure $work): mixed
    {
        $this->held[] = $tenant;
        try {
            return $work();
        } finally {
            array_pop($this->held);
        }
    }

    public function epoch(string $tenant): string
    {
        if ($this->onEpoch !== null) {
            ($this->onEpoch)($tenant);
        }

        return (string) ($this->epochs[$tenant] ?? 0);
    }

    public function advance(string $tenant): void
    {
        $this->epochs[$tenant] = ($this->epochs[$tenant] ?? 0) + 1;
    }
}
