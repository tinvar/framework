<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** Emailed links' records in memory: for tests, and an application with one process. */
final class ArrayEmailLinkStore implements EmailLinkStore
{
    /** @var array<string, EmailLink> by secret hash */
    private array $links = [];

    public function add(EmailLink $link, string $secretHash): void
    {
        if (isset($this->links[$secretHash])) {
            throw new \LogicException('Two links share a secret.');
        }
        $this->links[$secretHash] = $link;
    }

    public function find(string $secretHash): ?EmailLink
    {
        return $this->links[$secretHash] ?? null;
    }

    public function consume(string $secretHash, string $purpose, string $tenant, int $now): bool
    {
        $link = $this->links[$secretHash] ?? null;
        if ($link === null || $link->purpose !== $purpose || $link->tenant !== $tenant || !$link->isOpen($now)) {
            return false;
        }
        $this->links[$secretHash] = self::with($link, usedAt: $now);

        return true;
    }

    public function revoke(string $id, string $purpose, string $tenant, int $now): bool
    {
        foreach ($this->links as $hash => $link) {
            if ($link->id === $id && $link->purpose === $purpose && $link->tenant === $tenant && $link->isOpen($now)) {
                $this->links[$hash] = self::with($link, revokedAt: $now);

                return true;
            }
        }

        return false;
    }

    public function byId(string $id, string $purpose, string $tenant): ?EmailLink
    {
        foreach ($this->links as $link) {
            if ($link->id === $id && $link->purpose === $purpose && $link->tenant === $tenant) {
                return $link;
            }
        }

        return null;
    }

    public function open(string $purpose, string $tenant, int $now, int $limit): array
    {
        $open = array_values(array_filter(
            $this->links,
            static fn (EmailLink $link): bool => $link->purpose === $purpose && $link->tenant === $tenant && $link->isOpen($now),
        ));
        usort($open, static fn (EmailLink $a, EmailLink $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return \array_slice($open, 0, $limit);
    }

    public function prune(int $before, int $limit): int
    {
        $pruned = 0;
        foreach ($this->links as $hash => $link) {
            if ($pruned >= $limit) {
                break;
            }
            if ($link->expiresAt < $before) {
                unset($this->links[$hash]);
                $pruned++;
            }
        }

        return $pruned;
    }

    private static function with(EmailLink $link, ?int $usedAt = null, ?int $revokedAt = null): EmailLink
    {
        return new EmailLink(
            $link->id,
            $link->purpose,
            $link->tenant,
            $link->origin,
            $link->user,
            $link->data,
            $link->createdAt,
            $link->expiresAt,
            $usedAt ?? $link->usedAt,
            $revokedAt ?? $link->revokedAt,
        );
    }
}
