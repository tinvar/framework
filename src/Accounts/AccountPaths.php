<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\Response;
use Tinvar\Identity\HandoffEndpoint;

/**
 * Where the application's account pages are: what the kit redirects to, and
 * builds emailed links on. The defaults are the skeleton's pages; an app
 * with pages elsewhere hands in its own.
 */
final readonly class AccountPaths
{
    public function __construct(
        public string $signIn = '/sign-in',
        public string $signUp = '/sign-up',
        // Where a sign-up's link lands: choose a password, and the account is made.
        public string $completeSignUp = '/sign-up/complete',
        public string $secondFactor = '/sign-in/two-factor',
        public string $forgotPassword = '/password/forgot',
        public string $resetPassword = '/password/reset',
        // Where a change of address's link lands.
        public string $confirmEmail = '/account/email/confirm',
        // Where a visitor goes once signed in, unless the sign-in page was told where.
        public string $home = '/account',
        // Where a visitor goes once signed out.
        public string $signedOut = '/',
        // The account page, where its passkeys are: what a security email points to.
        public string $account = '/account',
        // The account's API tokens page: what the email about a new token points to.
        public string $apiTokens = '/account/api-tokens',
    ) {
        foreach (get_object_vars($this) as $name => $path) {
            if (!\is_string($path) || preg_match('#^/(?!/)[A-Za-z0-9/_.-]*\z#', $path) !== 1) {
                throw new \InvalidArgumentException("The account path {$name} must be a root-relative path with no query, as /sign-in.");
            }
        }
    }

    /**
     * Where to send a visitor after they sign in: the `next` the sign-in page
     * was given, if it is a path on this site, and home otherwise.
     *
     * `next` arrives in the query string, so anyone can write a link that
     * sets it; a full URL or `//evil.example` would make the sign-in page an
     * open redirect. Response's own same-origin rules decide.
     *
     * One framework path is somewhere to go on to: a handoff's authorize
     * step, which a visitor from another of the app's hosts is sent to sign
     * in from and comes back to (ADR 0039). It sends them off this host only
     * to a host the app lists as its own.
     */
    public function next(mixed $next): string
    {
        if (!\is_string($next) || $next === '' || \strlen($next) > 2048) {
            return $this->home;
        }
        if (!Response::isSameOriginTarget($next) || str_contains($next, '#')) {
            return $this->home;
        }
        // Back to the sign-in pages would only ask again, and nothing under
        // /_ is a page.
        $path = explode('?', $next, 2)[0];
        if (\in_array($path, [$this->signIn, $this->secondFactor, $this->signUp], true) || ($path !== HandoffEndpoint::AUTHORIZE && str_starts_with($path, '/_'))) {
            return $this->home;
        }

        return $next;
    }

    /** The sign-in page's address, remembering where the visitor was going. */
    public function signInFor(string $path, ?string $query = null): string
    {
        $next = $query === null || $query === '' ? $path : "{$path}?{$query}";

        return $this->signIn . '?next=' . rawurlencode($this->next($next));
    }
}
