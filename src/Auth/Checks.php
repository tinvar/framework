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

    /**
     * True when an entry point declares checks and not one of them applies
     * to this call -- which a query or action runner must refuse.
     *
     * The narrow rule above has a gap when EVERY check names an optional
     * input: leave them all out and nothing at all is asked, while the body
     * still runs and may read null as "no filter". A declared check is a
     * statement that this entry point needs a decision; a call that sidesteps
     * every one of them has not been decided, so it is denied rather than
     * served. Add an unconditional check -- Authorize('viewAny', Rack::class)
     * beside Authorize('view', 'rack'), on the class or method that declares
     * it (see undecided()) -- to let the input be left out.
     *
     * Decided here, at run time, from the same list the skip was decided
     * from, rather than as a build rule that re-derives "optional" for every
     * runner's shape: if the two ever disagreed, the build rule would pass a
     * call the runner then left unchecked. An entry point with no checks at
     * all declared #[NoAuthorization], and is not affected.
     *
     * Not used for page and layout loaders. Their inputs come from the route
     * template, so an absent one means a different route matched -- a layout
     * shared by /sites/{site} and /health -- whose own checks run, not a
     * caller leaving an argument out of the same entry point.
     *
     * @param list<Authorize>      $checks   every check the entry point declares
     * @param array<string, Model> $models   subjects resolved for this call
     * @param list<string>         $optional model inputs the entry point declares optional
     */
    public static function noneApply(array $checks, array $models, array $optional): bool
    {
        if ($checks === []) {
            return false;
        }

        foreach ($checks as $check) {
            if (self::applies($check, $models, $optional)) {
                return false;
            }
        }

        return true;
    }

    /**
     * noneApply(), judged separately for the checks a class declares and the
     * checks its method declares: true when either set is non-empty and
     * nothing in it applies.
     *
     * Judged over the merged list, an unconditional class-level check made a
     * method's own checks look decided. An action holder's
     * Authorize('admin', AdminArea::class) applies to every call, so a
     * method whose only check named an optional input the caller left out
     * passed on the class check alone -- the method's own authorization,
     * which this rule exists to make fail closed, was never asked anything.
     * Each place that declares checks has to be decided by one of its own.
     * Every applicable check, from either place, still runs and must pass.
     *
     * @param list<Authorize>      $checks     the class's checks first, then the method's
     * @param int                  $classLevel how many of $checks the class declared
     * @param array<string, Model> $models     subjects resolved for this call
     * @param list<string>         $optional   model inputs the entry point declares optional
     */
    public static function undecided(array $checks, int $classLevel, array $models, array $optional): bool
    {
        return self::noneApply(\array_slice($checks, 0, $classLevel), $models, $optional)
            || self::noneApply(\array_slice($checks, $classLevel), $models, $optional);
    }
}
