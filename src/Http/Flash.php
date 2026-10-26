<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Support\Signer;

/**
 * What a flash is made of, and the cookie that says one is waiting.
 *
 * A flash is written by a browser's own form post and read by the page it is
 * sent back to (see FlashStore). Every page render would otherwise have to
 * ask the store whether its session had one -- a database read on every
 * request, for something almost none of them have. So the redirect that
 * writes a flash also sets COOKIE, and only a request carrying it asks. The
 * cookie carries nothing but its presence: forged, it costs one empty read,
 * and a store must keep it to that (FlashStore::pull()).
 *
 * But for one flash, which the cookie carries itself: the "page expired" a
 * post that failed CSRF is sent back with (expired()). That post may be
 * anyone's -- with no cookie, to any action's name, from another site --
 * and kept in the store, each was a write anybody could ask for as fast
 * as the flood guard let them.
 */
final class Flash
{
    /** Set beside a flash, and cleared by the page that reads it. */
    public const string COOKIE = '__Host-tinvar-flash';

    /**
     * Seconds a flash waits for its page. The redirect is followed at once;
     * a flash older than this was never collected and means nothing now.
     */
    public const int LIFETIME = 600;

    /**
     * The most re-filled input one flash keeps, encoded. The errors always
     * go; input past this does not, and the person types it again rather
     * than a 1 MB body being kept for them in the session.
     */
    public const int MAX_OLD_BYTES = 16_384;

    /**
     * Field names whose values are never kept. A password typed into a form
     * that failed validation is not written to the session store, nor sent
     * back in the page: Laravel's `dontFlash`, by pattern.
     */
    private const string SECRET = '/password|secret|token/i';

    /** What COOKIE holds, before the action's name, for a post that failed CSRF. */
    private const string EXPIRED = 'expired.';

    /** The longest action name the cookie carries. */
    private const int MAX_ACTION_BYTES = 255;

    /**
     * The input to put back into a form that failed: what was posted, less
     * the CSRF field, the route tokens, anything named like a secret, and
     * all of it if it is too large to keep.
     *
     * @param array<mixed> $input
     * @return array<mixed>
     */
    public static function old(array $input): array
    {
        unset($input['_routes']);
        $kept = self::withoutSecrets($input);
        $encoded = json_encode($kept, JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false || \strlen($encoded) > self::MAX_OLD_BYTES ? [] : $kept;
    }

    /**
     * Where "back" is: the path and query of the page the form was on.
     *
     * From Referer, which a browser sends with a form post to its own
     * origin. Only its path and query are kept, so back is somewhere on this
     * site whatever the header named -- a form on another site that posted
     * here is sent back to that path HERE -- and anything redirect() would
     * refuse is not used.
     *
     * Without a Referer to use -- a `no-referrer` policy, or a browser or
     * extension that strips it -- the session's previous URL stands in: the
     * page it last loaded, which for a form posted without script is the page
     * the form was on. Only then the root.
     */
    public static function back(Request $request): string
    {
        return self::fromReferer($request) ?? $request->previousUrl() ?? '/';
    }

    /** The path and query Referer names, or null when it names none redirect() would take. */
    private static function fromReferer(Request $request): ?string
    {
        $referer = $request->header('referer');
        if ($referer === null || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $target = isset($parts['query']) ? "{$path}?{$parts['query']}" : $path;

        return Response::isSameOriginTarget($target) ? $target : null;
    }

    /**
     * COOKIE's value for a form posted to $action that failed CSRF: the
     * flash it is sent back with, in the cookie rather than the store.
     */
    public static function expired(string $action): string
    {
        return self::EXPIRED . Signer::encode($action);
    }

    /**
     * The action COOKIE's value says a form was posted to and failed CSRF
     * on, or null when it says nothing of the kind. Whatever it names is
     * the visitor's own to have set; the Kernel shows it only for an action
     * there is.
     */
    public static function expiredAction(string $cookie): ?string
    {
        if (!str_starts_with($cookie, self::EXPIRED)) {
            return null;
        }

        $action = Signer::decode(substr($cookie, \strlen(self::EXPIRED)));

        return $action === null || $action === '' || \strlen($action) > self::MAX_ACTION_BYTES ? null : $action;
    }

    /**
     * The flash a form that failed CSRF is shown with: which form, that its
     * page expired, and none of what was posted -- whoever posted it may
     * not be the person it is shown to.
     *
     * @return array{form: array{action: string, errors: array<never>, old: array<never>, expired: true}}
     */
    public static function expiredForm(string $action): array
    {
        return ['form' => ['action' => $action, 'errors' => [], 'old' => [], 'expired' => true]];
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private static function withoutSecrets(array $input): array
    {
        $kept = [];
        foreach ($input as $key => $value) {
            if (\is_string($key) && preg_match(self::SECRET, $key) === 1) {
                continue;
            }
            $kept[$key] = \is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $kept;
    }
}
