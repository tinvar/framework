<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * API tokens in this process's memory: for a test, or an application
 * trying tokens out. Every worker has its own, so a token issued on one is
 * unknown on the next; production keeps them in DatabaseApiTokens.
 */
final class ArrayApiTokens implements ApiTokenStore
{
    /** @var array<string, array{ApiToken, string}> by id */
    public array $tokens = [];

    public function insert(ApiToken $token, string $digest): void
    {
        if (isset($this->tokens[$token->id])) {
            throw new \LogicException("An API token with the id {$token->id} is kept already.");
        }
        $this->tokens[$token->id] = [$token, $digest];
    }

    public function find(string $id): ?array
    {
        return $this->tokens[$id] ?? null;
    }

    public function forUser(string $tenant, string $panel, string $user): array
    {
        $mine = array_values(array_filter(
            array_map(static fn (array $entry): ApiToken => $entry[0], $this->tokens),
            static fn (ApiToken $t): bool => $t->tenant === $tenant && $t->panel === $panel && $t->user === $user,
        ));
        usort($mine, static fn (ApiToken $a, ApiToken $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $mine;
    }

    public function revoke(string $tenant, string $user, string $id, CarbonImmutable $at): bool
    {
        [$token, $digest] = $this->tokens[$id] ?? [null, ''];
        if ($token === null || $token->tenant !== $tenant || $token->user !== $user || $token->revokedAt !== null) {
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
}
