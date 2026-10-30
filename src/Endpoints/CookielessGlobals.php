<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

/**
 * PHP's own copies of the request's cookies, emptied while a
 * #[WithoutSession] endpoint runs (ADR 0125).
 *
 * The Kernel takes every cookie off the Request it hands such an endpoint,
 * so nothing reading the Request can sign anyone in from one. PHP keeps
 * copies the Request never sees: `$_COOKIE`, the `Cookie` header in
 * `$_SERVER['HTTP_COOKIE']`, `$_REQUEST` where `request_order` merges
 * cookies into it, and `$_SESSION` where something started a PHP session
 * from the cookie. A helper or a library reading any of them would find the
 * visitor's session cookie, and the endpoint takes no CSRF token -- which is
 * safe only because no cookie authenticates its caller. So each is emptied
 * for the endpoint's run -- `$_REQUEST` rebuilt from the query and the body
 * alone -- and put back as it was afterwards, for whatever runs after the
 * response in this request.
 *
 * What is out of reach: getallheaders() and apache_request_headers() read
 * the SAPI's headers, not a superglobal; an endpoint's code calling them
 * is its own to answer for, as raw PDO is a query's.
 */
final class CookielessGlobals
{
    /**
     * $work's result, run with PHP's cookie superglobals empty.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        $cookie = $_COOKIE;
        $request = $_REQUEST;
        $header = $_SERVER['HTTP_COOKIE'] ?? null;
        $hadSession = isset($_SESSION);
        $session = $_SESSION ?? null;

        $_COOKIE = [];
        $_REQUEST = array_merge($_GET, $_POST);
        unset($_SERVER['HTTP_COOKIE']);
        if ($hadSession) {
            $_SESSION = [];
        }

        try {
            return $work();
        } finally {
            $_COOKIE = $cookie;
            $_REQUEST = $request;
            if ($header !== null) {
                $_SERVER['HTTP_COOKIE'] = $header;
            } else {
                unset($_SERVER['HTTP_COOKIE']);
            }
            if ($hadSession) {
                $_SESSION = $session;
            }
        }
    }
}
