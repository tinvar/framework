<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Attributes\RequiresScope;
use Tinvar\Calls\Operation;

/**
 * The scopes an application declares, by panel: every #[RequiresScope] on
 * an operation or an endpoint discovery found, gathered once when the
 * Kernel is built. A token is issued with some of its panel's, and nothing
 * else -- so there is no list of scopes to keep beside the attributes, and
 * a scope a typo made up is refused when someone asks for it.
 */
final class ApiScopes
{
    /**
     * @param list<array{Operation, RequiresScope}> $operations ApiCalls::discover()'s
     * @param list<array{string, list<string>}> $endpoints each endpoint's panel ('' for none) and scopes
     * @return array<string, list<string>> panel ('' for none) => its scopes, sorted
     */
    public static function of(array $operations, array $endpoints): array
    {
        $byPanel = [];
        foreach ($operations as [$operation, $declaration]) {
            foreach ($declaration->scopes as $scope) {
                $byPanel[$operation->panel->name ?? ''][$scope] = true;
            }
        }
        foreach ($endpoints as [$panel, $scopes]) {
            foreach ($scopes as $scope) {
                $byPanel[$panel][$scope] = true;
            }
        }

        $catalog = [];
        foreach ($byPanel as $panel => $scopes) {
            $names = array_map(strval(...), array_keys($scopes));
            sort($names);
            $catalog[(string) $panel] = $names;
        }
        ksort($catalog);

        return $catalog;
    }
}
