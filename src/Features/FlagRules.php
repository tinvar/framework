<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * Flag values written in code: for everyone, a panel, a tenant or a user.
 *
 *   FlagRules::new()
 *       ->forPanel('shipping.eta', 'admin', true)
 *       ->forUser('shipping.eta', $betaTester, true)
 *
 * The most specific wins, as FlagValues says: a user's value over their
 * tenant's, a tenant's over the panel's, the panel's over everyone's.
 * Immutable, so a set of rules handed to a long-lived worker is the set it
 * keeps.
 */
final class FlagRules implements FlagValues
{
    /** @var array<string, array{user: array<string, bool>, tenant: array<string, bool>, panel: array<string, bool>, everyone: bool|null}> */
    private array $rules = [];

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    public function forEveryone(string $flag, bool $active): self
    {
        $rules = clone $this;
        $rules->rules[$flag] ??= self::none();
        $rules->rules[$flag]['everyone'] = $active;

        return $rules;
    }

    public function forPanel(string $flag, string $panel, bool $active): self
    {
        return $this->with($flag, 'panel', $panel, $active);
    }

    public function forTenant(string $flag, string $tenant, bool $active): self
    {
        return $this->with($flag, 'tenant', $tenant, $active);
    }

    public function forUser(string $flag, string $user, bool $active): self
    {
        return $this->with($flag, 'user', $user, $active);
    }

    public function valueFor(string $flag, FlagScope $scope): ?bool
    {
        $rules = $this->rules[$flag] ?? null;
        if ($rules === null) {
            return null;
        }

        return ($scope->user === null ? null : $rules['user'][$scope->user] ?? null)
            ?? ($scope->tenant === '' ? null : $rules['tenant'][$scope->tenant] ?? null)
            ?? ($scope->panel === null ? null : $rules['panel'][$scope->panel] ?? null)
            ?? $rules['everyone'];
    }

    /** @param 'user'|'tenant'|'panel' $part */
    private function with(string $flag, string $part, string $subject, bool $active): self
    {
        // A value for '' would be read for every request with no tenant, or
        // for a user whose id happens to be empty: never what was meant.
        if ($subject === '') {
            throw new \InvalidArgumentException("Flag '{$flag}' was given a {$part} value with no {$part} to be for.");
        }

        $rules = clone $this;
        $rules->rules[$flag] ??= self::none();
        $rules->rules[$flag][$part][$subject] = $active;

        return $rules;
    }

    /** @return array{user: array<string, bool>, tenant: array<string, bool>, panel: array<string, bool>, everyone: bool|null} */
    private static function none(): array
    {
        return ['user' => [], 'tenant' => [], 'panel' => [], 'everyone' => null];
    }
}
