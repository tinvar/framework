<?php

declare(strict_types=1);

namespace Tinvar\Features;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;

/**
 * The application's feature flags, read for the tenant, user and panel in
 * force: `$features->active('shipping.eta')`.
 *
 * tinvar-spec.md, "Query cache": "The default cache key is the query name,
 * its arguments, the tenant, the user, the panel and its effective scope,
 * the locale, and the feature flags the query read." So every read is
 * reported (FlagReads), and a query body that read a flag is cached under
 * what it read -- or, where which work read it cannot be told, not cached
 * at all.
 *
 * A flag is declared (Flag), with its default; its value for a scope is the
 * application's FlagValues', the user's over the tenant's over the panel's
 * over everyone's. Each value is read once per request for a scope and kept
 * until the reset contract clears it, so one request sees one value of a
 * flag, however many components ask -- a flag switched mid-request is seen
 * by the next.
 */
final class Features
{
    /** @var array<string, Flag> */
    private array $flags = [];

    /** @var array<string, bool> scope key and flag => value, this request */
    private array $read = [];

    /**
     * @param list<Flag> $flags every flag the application reads
     */
    public function __construct(
        array $flags,
        private readonly FlagValues $values,
        private readonly AuthContext $auth,
        private readonly Orm $orm,
        private readonly ?PanelContext $panels = null,
    ) {
        foreach ($flags as $flag) {
            if (isset($this->flags[$flag->name])) {
                throw new \InvalidArgumentException("Feature flag '{$flag->name}' is declared twice.");
            }
            $this->flags[$flag->name] = $flag;
        }
    }

    /**
     * Whether $flag is on for the tenant, user and panel in force. An enum
     * case names its flag by its value.
     */
    public function active(string|\BackedEnum $flag): bool
    {
        $declared = $this->flag($flag);
        $active = $this->valueIn($declared, $this->current());
        FlagReads::record($declared->name, $active);

        return $active;
    }

    /**
     * Whether $flag is on for $scope -- someone's other than the caller's,
     * for an operator's screen or a job acting for them.
     *
     * Not a read any answer can be kept under: what it read is not the
     * caller's flag, so no value of the caller's can say the answer is
     * theirs. Work recording its reads -- a cached query, a static page, a
     * once value -- that calls this is kept by no one (ADR 0094).
     */
    public function in(string|\BackedEnum $flag, FlagScope $scope): bool
    {
        FlagReads::unattributable();

        return $this->valueIn($this->flag($flag), $scope);
    }

    /**
     * The tenant, user and panel a read in hand is made for. Saying who the
     * user is, it is answered as in() is: nothing that asked is kept.
     */
    public function scope(): FlagScope
    {
        FlagReads::unattributable();

        return $this->current();
    }

    /** The narrow reader a query body or page is handed: active(), and nothing else. */
    public function reader(): FlagReader
    {
        return new FlagReader($this);
    }

    private function current(): FlagScope
    {
        return new FlagScope($this->orm->tenant(), $this->auth->id(), $this->panels?->currentOrNull());
    }

    private function valueIn(Flag $flag, FlagScope $scope): bool
    {
        return $this->read[$scope->key() . "\0" . $flag->name] ??= $this->values->valueFor($flag->name, $scope) ?? $flag->default;
    }

    /** Whether the application declares $name. */
    public function declares(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    /** @return list<Flag> every flag declared */
    public function flags(): array
    {
        return array_values($this->flags);
    }

    /**
     * The reset contract's part: values read for this request are dropped,
     * and with them any recorder a fiber left open.
     */
    public function reset(): void
    {
        $this->read = [];
        FlagReads::closeAll();
    }

    private function flag(string|\BackedEnum $flag): Flag
    {
        $name = $flag instanceof \BackedEnum ? (string) $flag->value : $flag;

        return $this->flags[$name] ?? throw new \LogicException(
            "Feature flag '{$name}' is not declared. Declare it with the application's Features, so a misspelt name fails rather than reading as off.",
        );
    }
}
