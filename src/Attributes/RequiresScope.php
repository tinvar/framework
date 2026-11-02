<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Exposes an endpoint, an action or a query over HTTP to an API token
 * holding these scopes (Tinvar\ApiTokens, ADR 0159).
 *
 *     #[Query(name: 'orders.yours'), Authorize('viewAny', Order::class), RequiresScope(ApiScope::OrdersRead)]
 *     final class YourOrders { ... }
 *
 * A token must hold every scope named. A scope is a backed enum's value --
 * the application's own enum of them, so the set is closed and written
 * once -- or a string: lowercase words joined by `:`, `.`, `_` or `-`, as
 * `orders:read`.
 *
 *   - On an #[Action] method or a #[Query] class, the operation may be
 *     called at `POST /_api/{name}` with `Authorization: Bearer tvr_...`,
 *     as well as by the browser's session as before. Nothing about it
 *     changes: the call runs through its own model resolution, #[Authorize]
 *     and validation, as the token's user, in the token's tenant and panel.
 *   - On a #[Get], #[Post] or #[Endpoint] method, or its class, the
 *     endpoint takes a token and nothing else: it runs with no session, as
 *     #[WithoutSession] does, so no cookie signs anyone in and no CSRF token
 *     is asked for; the token signs its user in.
 *
 * Scopes narrow what a token may do; they grant nothing a policy would
 * refuse the user. The scopes a token may be issued with are the ones
 * declared here, found by discovery: there is no list to keep.
 *
 * A requirement adds something, so on an endpoint's class it covers each
 * endpoint in it, with the method's added (declaredOn()). An action holder
 * carrying it on its class is refused: name it on each action method.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresScope
{
    /** What a scope may be: lowercase words, joined by `:`, `.`, `_` or `-`, up to 64 characters. */
    public const string PATTERN = '/^[a-z][a-z0-9]*(?:[:._-][a-z0-9]+)*\z/';

    /** The longest scope taken. */
    public const int MAX_LENGTH = 64;

    /** @var list<string> each scope required, sorted, each once */
    public array $scopes;

    public function __construct(string|\BackedEnum ...$scopes)
    {
        if ($scopes === []) {
            throw new \InvalidArgumentException('#[RequiresScope] names the scopes a token must hold, as #[RequiresScope(\'orders:read\')].');
        }
        $this->scopes = self::normalize($scopes);
    }

    /**
     * Scopes as strings, each checked, sorted and once.
     *
     * @param array<array-key, string|\BackedEnum> $scopes
     * @return list<string>
     * @throws \InvalidArgumentException naming the first that is not a scope
     */
    public static function normalize(array $scopes): array
    {
        $names = [];
        foreach ($scopes as $scope) {
            $name = $scope instanceof \BackedEnum ? (string) $scope->value : $scope;
            if (!self::isScope($name)) {
                throw new \InvalidArgumentException(
                    "'{$name}' is not a scope: lowercase words joined by ':', '.', '_' or '-', up to " . self::MAX_LENGTH . " characters, as 'orders:read'.",
                );
            }
            $names[$name] = true;
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** Whether $name is a scope as one may be written. */
    public static function isScope(string $name): bool
    {
        return \strlen($name) <= self::MAX_LENGTH && preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * The scopes an entry point requires: its class's -- its own, a trait's
     * and an ancestor's (ClassAttributes) -- with its method's, each once
     * and sorted; none when it declares none. $method null reads the class
     * alone, as a query is declared.
     *
     * @return list<string>
     */
    public static function declaredOn(string $class, ?string $method = null): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $reflection = new \ReflectionClass($class);
        $attributes = ClassAttributes::of($reflection, self::class);
        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(self::class)];
        }

        $scopes = [];
        foreach ($attributes as $attribute) {
            array_push($scopes, ...$attribute->newInstance()->scopes);
        }

        return $scopes === [] ? [] : self::normalize($scopes);
    }
}
