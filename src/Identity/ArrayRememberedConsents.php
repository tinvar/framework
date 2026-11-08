<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** Remembered consents in memory, for tests: DatabaseRememberedConsents' rules without its table. */
final class ArrayRememberedConsents implements RememberedConsents
{
    /** @var array<string, array<string, array{epoch: string, consent: RememberedConsent}>> user => origin => row */
    public array $rows = [];

    public function remembers(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): bool
    {
        $row = $this->rows[$user][$origin] ?? null;

        return $row !== null && hash_equals($row['epoch'], $epoch)
            && $row['consent']->tenant === $tenant && $row['consent']->panel === $panel
            && $row['consent']->expiresAt > $now;
    }

    public function remember(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): void
    {
        if ($user === '') {
            throw new \InvalidArgumentException('A consent is remembered for somebody.');
        }
        $this->rows[$user][$origin] = ['epoch' => $epoch, 'consent' => new RememberedConsent($origin, $tenant, $panel, $now, $now + self::MAX_AGE)];
    }

    public function forget(string $user, string $origin): void
    {
        unset($this->rows[$user][$origin]);
    }

    public function forgetUser(string $user): void
    {
        unset($this->rows[$user]);
    }

    public function forgetOrigin(string $origin): void
    {
        foreach (array_keys($this->rows) as $user) {
            unset($this->rows[$user][$origin]);
        }
    }

    public function forUser(string $user, string $epoch, int $now): array
    {
        $out = [];
        foreach ($this->rows[$user] ?? [] as $row) {
            if (hash_equals($row['epoch'], $epoch) && $row['consent']->expiresAt > $now) {
                $out[] = $row['consent'];
            }
        }

        return $out;
    }
}
