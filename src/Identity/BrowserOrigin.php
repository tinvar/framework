<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Http\HostName;

/**
 * An origin in the one spelling a browser serializes, read the one way
 * for a realm (Realm::hostOf()) and an emailed link (CanonicalOrigins).
 *
 * The two were checked apart and had drifted: an address literal a realm
 * refused was an origin a link could be built on. The stricter of the two
 * is the rule for both -- a lowercase host name with no trailing dot, no
 * address literal, no path, no user, and no port when it is the scheme's
 * default -- so an origin compared as a string has no second string that
 * means it. https, because what travels in these URLs -- a handoff code, a
 * link's token -- would be handed to the network on a plain-http hop; http
 * only where a caller allows it, and then only for `localhost` and its
 * subdomains, which resolve to this machine.
 */
final class BrowserOrigin
{
    /**
     * The host $origin names, or null when it is not one.
     *
     * @param bool $localHttp whether http is taken for localhost and its subdomains
     */
    public static function hostOf(string $origin, bool $localHttp = false): ?string
    {
        if (preg_match('#^(https?)://([^/:?\#@\[\]\s]+)(?::([1-9][0-9]{0,4}))?\z#', $origin, $m) !== 1) {
            return null;
        }
        [, $scheme, $host] = $m;
        $port = $m[3] ?? '';

        if (HostName::normalize($host) !== $host || ($port !== '' && (int) $port > 65535)) {
            return null;
        }
        // A name, never an address: an origin on an address literal is one
        // no certificate names, and a second spelling of whichever host it is.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) {
            return null;
        }
        if ($scheme === 'http' && (!$localHttp || ($host !== 'localhost' && !str_ends_with($host, '.localhost')))) {
            return null;
        }

        return $host;
    }
}
