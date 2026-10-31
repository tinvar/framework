<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What an application's document policy must say, read by `tinvar check`
 * off the policy its Kernel sends with a page.
 *
 * `frame-ancestors`, at least: a policy that says nothing about who may
 * frame a page lets any origin put it in an iframe and take a click on it
 * under a disguise. Which ancestors is the application's to say -- 'none',
 * 'self', or the origins that embed it -- but it must say. A policy in a
 * meta element cannot (a browser ignores the directive there), so this is
 * the header's policy.
 */
final class ContentSecurityPolicyRules
{
    /**
     * Every rule $policy breaks, one line each; [] for none.
     *
     * @return list<string>
     */
    public static function check(string $policy): array
    {
        foreach (explode(';', $policy) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (strtolower($parts[0] ?? '') === 'frame-ancestors') {
                return \count($parts) > 1
                    ? []
                    : ['The Content-Security-Policy names frame-ancestors with no source: say \'none\', \'self\' or the origins that may frame a page.'];
            }
        }

        return ["The Content-Security-Policy has no frame-ancestors, so any origin may frame a page: add frame-ancestors 'none' (or 'self', where the application frames its own pages)."];
    }
}
