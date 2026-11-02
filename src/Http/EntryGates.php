<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The gates an entry point's middleware attributes put on it (ADR 0150),
 * held back until its #[Authorize] checks have passed.
 *
 * A gate such as #[RequiresPlan] is listed with the entry point's
 * middleware, and ran with it -- ahead of the checks, which run with the
 * loader, the action, the query or the endpoint. A caller the checks
 * refuse -- a visitor, a user of another tenant -- was answered 402 or not
 * by whether the tenant had the plan, where they should have learned
 * nothing. So a gate's place in the stack only defers it: defer() records
 * it on the request, and the runner calls enforce() once every check has
 * passed, and before anything the entry point does.
 *
 * Run there, a gate is a check: it lets the request on by calling $next,
 * and refuses by throwing -- an HttpException, as #[RequiresPlan]'s 402 --
 * or by answering without calling $next, when its answer is the request's.
 * The request it hands $next goes no further.
 *
 * It fails closed. A deferred gate that nothing enforced, on a request
 * answered with anything but a refusal (a status under 400), is a runner
 * that forgot to call enforce(): the request is refused with a 500, never
 * answered as if the gate had passed.
 *
 * One per request, carried as its attribute. A request derived from it
 * carries the same one, so a re-render after an action enforces the page's
 * gates beside the action's, each once.
 */
final class EntryGates
{
    /** @var list<array{Middleware, bool}> each gate, and whether it has passed */
    private array $gates = [];

    /** The gates $request carries; null for none. */
    public static function of(Request $request): ?self
    {
        $gates = $request->attribute(self::class);

        return $gates instanceof self ? $gates : null;
    }

    /**
     * The middleware that stands in a stack for $gate: it defers it, and
     * refuses a request answered without it having run.
     */
    public static function defer(Middleware $gate): Middleware
    {
        return new readonly class ($gate) implements Middleware {
            public function __construct(private Middleware $gate) {}

            public function handle(Request $request, \Closure $next): Response
            {
                $gates = EntryGates::of($request);
                if ($gates === null) {
                    $gates = new EntryGates();
                    $request = $request->withAttribute(EntryGates::class, $gates);
                }
                $index = $gates->add($this->gate);

                try {
                    $response = $next($request);
                } catch (GateAnswered $answered) {
                    if ($answered->gate !== $this->gate) {
                        throw $answered;
                    }

                    return $answered->response;
                }

                if (!$gates->passed($index) && $response->status < 400) {
                    throw new \LogicException(
                        'A gate (' . $this->gate::class . ') was deferred until its entry point\'s checks had run, and nothing ran it: '
                        . 'the request is refused, never answered as if it had passed. The runner must call EntryGates::enforce().',
                    );
                }

                return $response;
            }
        };
    }

    /**
     * Runs every gate $request carries that has not passed, in the order
     * the stack listed them. Called by each runner once the entry point's
     * checks have passed; nothing for a request with none.
     *
     * @throws HttpException as a gate refuses
     * @throws GateAnswered for a gate that answered without calling $next
     */
    public static function enforce(?Request $request): void
    {
        if ($request === null) {
            return;
        }
        $gates = self::of($request);
        if ($gates === null) {
            return;
        }

        foreach ($gates->gates as $index => [$gate, $passed]) {
            if ($passed) {
                continue;
            }
            $pass = Response::make(204);
            $answer = $gate->handle($request, static fn (Request $passed): Response => $pass);
            if ($answer !== $pass) {
                throw new GateAnswered($gate, $answer);
            }
            $gates->gates[$index][1] = true;
        }
    }

    /** @internal for defer()'s middleware */
    public function add(Middleware $gate): int
    {
        $this->gates[] = [$gate, false];

        return \count($this->gates) - 1;
    }

    /** @internal for defer()'s middleware */
    public function passed(int $index): bool
    {
        return $this->gates[$index][1];
    }
}
