<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;

/**
 * The retry hook on the PHP-to-renderer client (tinvar-spec.md, "Renderer
 * lifecycle": "a timeout/retry hook on the PHP→renderer client").
 *
 * A render is repeated only where repeating it changes nothing (ADR 0093):
 *
 * - **The caller says the render may be repeated** -- RenderChannel::render()'s
 *   `repeatable`, which permits() answers for a request: a GET or HEAD for a
 *   page or a fragment, whose loaders and queries ran read-only, as a
 *   prefetch's do. Never an action's request, nor the re-render of a page an
 *   action's response carries: that is the answer to a write, and a renderer
 *   failing under it is the action's to report, not to hide by asking twice.
 * - **Nothing has reached the browser**, or the caller can take back what
 *   did: a caller that buffers the response hands render() a `rewind`, and
 *   one that streams does not, so its render is repeated only before its
 *   first byte.
 * - **The failure is the renderer's, and passing.** By default a renderer
 *   that could not be reached -- every socket of a pool refused, as during a
 *   restart -- or that hung up before its done frame, as one that crashed
 *   does. A component that threw, a deadline, a budget spent or a renderer
 *   that broke the protocol fails the same way the second time, or should
 *   not be given one: those are not retried unless the application's own
 *   hook says so, and the last two never are. A code the renderer chose for
 *   itself, in an error frame, is not believed by default: a renderer could
 *   name a passing failure to be asked again.
 *
 * At most MAX_RETRIES more tries, each after a short pause that grows with
 * the try, so a worker is held for a bounded time; every try has its own
 * deadline and its own query budget, as a fresh render does.
 */
final class RenderRetry
{
    /** The most tries after the first: three renders in all, at worst. */
    public const int MAX_RETRIES = 2;

    /** The longest pause before a try, in milliseconds. */
    public const int MAX_BACKOFF_MS = 1000;

    /** What is retried when the application gives no hook of its own. */
    public const array TRANSIENT = ['renderer_unreachable', 'render_incomplete'];

    /**
     * What no hook may retry: a renderer that is not the one renders may go
     * to, a render that asked for more work than one render may, and a
     * renderer that broke the protocol. The first must not be asked again,
     * the second must not be paid for twice, and the third is never asked
     * again whether this side found the violation or the renderer named it
     * in an error frame of its own.
     */
    public const array NEVER = ['renderer_untrusted', 'render_query_budget', 'protocol_violation'];

    /**
     * @param int $retries how many more tries a render gets, up to MAX_RETRIES
     * @param int $backoffMs the pause before the first of them, doubled for each after
     * @param (\Closure(RendererException, int): bool)|null $when the application's
     *        hook: told the failure and which try the next would be (1 for the
     *        first retry), it says whether to make it. It replaces TRANSIENT,
     *        never NEVER, and is asked only about a render that may be repeated.
     */
    public function __construct(
        public readonly int $retries = 1,
        public readonly int $backoffMs = 50,
        private readonly ?\Closure $when = null,
    ) {
        if ($retries < 0 || $retries > self::MAX_RETRIES) {
            throw new \InvalidArgumentException('A render is retried between 0 and ' . self::MAX_RETRIES . ' times.');
        }
        if ($backoffMs < 0 || $backoffMs > self::MAX_BACKOFF_MS) {
            throw new \InvalidArgumentException('A retry waits between 0 and ' . self::MAX_BACKOFF_MS . ' ms.');
        }
    }

    /**
     * Whether a request's render may be repeated: a GET or a HEAD, which is
     * a read, and not the re-render of a page inside an action's response.
     */
    public static function permits(Request $request): bool
    {
        return ($request->method === 'GET' || $request->method === 'HEAD')
            && $request->attribute(RequestAttributes::ACTION_RERENDER) !== true;
    }

    /**
     * Whether a render that failed with $e is tried again, as try $retry.
     */
    public function retries(\Throwable $e, int $retry): bool
    {
        // A protocol violation, or anything else not the renderer's
        // classified failure, is never asked again.
        if ($retry < 1 || $retry > $this->retries || !$e instanceof RendererException
            || \in_array($e->code_, self::NEVER, true)) {
            return false;
        }

        return $this->when === null
            ? !$e->reported && \in_array($e->code_, self::TRANSIENT, true)
            : ($this->when)($e, $retry) === true;
    }

    /** The pause before try $retry, in milliseconds. */
    public function backoff(int $retry): int
    {
        return min(self::MAX_BACKOFF_MS, $this->backoffMs * (2 ** max(0, $retry - 1)));
    }
}
