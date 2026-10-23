<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Authorize;
use Tinvar\Orm\Model;

/**
 * When a declared check applies to a call.
 *
 * The build rules require an #[Authorize] naming every model-typed input of a
 * routable entry point -- including the ones declared optional, because
 * "optional" describes the path, not the trust. A layout shared across a route
 * family declares `?Site $site = null`; a page under /sites/{site} carries it,
 * a page under /health does not.
 *
 * That left the two rules mutually unsatisfiable at runtime: the registry
 * demanded a check naming the input, the binder correctly skipped the absent
 * model, and the gate was then handed a check whose subject was not there.
 * Every runner turned that into a 500 -- three different 500s.
 *
 * The rule, in one place so the three runners cannot drift again: a check
 * whose subject is a DECLARED-OPTIONAL model input that the call did not
 * supply does not apply. There is nothing to decide about a model that is not
 * there, and the body receives null, so it cannot act on it either.
 *
 * This is deliberately narrow. The subject must be declared optional by the
 * entry point itself -- never inferred from the argument simply being absent,
 * which would let a caller skip a check by omitting its subject.
 */
final class Checks
{
    /**
     * @param array<string, Model> $models   subjects resolved for this call
     * @param list<string>         $optional model inputs the entry point declares optional
     */
    public static function applies(Authorize $check, array $models, array $optional): bool
    {
        if ($check->argument === null) {
            return true;
        }

        if (isset($models[$check->argument])) {
            return true;
        }

        return !\in_array($check->argument, $optional, true);
    }
}
