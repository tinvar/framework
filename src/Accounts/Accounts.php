<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\Passkeys\Base64Url;
use Tinvar\Accounts\Passkeys\Passkey;
use Tinvar\Accounts\Passkeys\PasskeyChallenges;
use Tinvar\Accounts\Passkeys\PasskeyRefused;
use Tinvar\Accounts\Passkeys\PasskeyStore;
use Tinvar\Accounts\Passkeys\RelyingParty;
use Tinvar\Accounts\Passkeys\WebAuthn;
use Tinvar\Accounts\TwoFactor\Base32;
use Tinvar\Accounts\TwoFactor\Totp;
use Tinvar\Accounts\TwoFactor\TwoFactorStore;
use Tinvar\Api\ApiTokenChoice;
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\IssuedApiToken;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\SecurityEvent;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\Request;
use Tinvar\Identity\EmailLinks;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\SendMail;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Validation\ValidationException;

/**
 * The accounts kit's flows (ADR 0153): signing up by a link to the address,
 * signing in and out -- by password, by passkey, or by a link emailed to the
 * address (ADR 0162) -- resetting and changing a password, changing the
 * address by a link to the new one, passkeys, two-factor codes and "sign
 * out everywhere". An application's actions call these and say what the
 * browser does next; everything that decides who is signed in is here.
 *
 * The rules every flow keeps:
 *
 * - **Nothing says which addresses have accounts.** Asking for a sign-up, a
 *   reset or a change of address answers the same, after the same work --
 *   counting the request and queueing one job -- and the job finds out on
 *   the worker (deliver()). A wrong password and an unknown address are one
 *   message, the unknown one checked against a decoy as costly as a real
 *   hash (Passwords).
 * - **Every guess is counted** -- passwords, two-factor codes, current
 *   passwords asked before a change -- in a store a flood cannot clear
 *   (SignInThrottle, ADR 0023), and every request for mail per address and
 *   per recipient (LinkRequestThrottle).
 * - **An emailed link is a single-use record**, bound to its purpose, the
 *   app's origin and, where it names an account, the account's auth epoch
 *   (EmailLinks, ADR 0076). The page it lands on only looks; a POST uses it.
 * - **Every change of who a session is rotates its id**, and every change
 *   to how an account is reached -- a password, an address, a reset, a
 *   passkey removed, a sign-out everywhere -- moves the account's auth epoch, which ends every
 *   token, grant, queued job and emailed link minted for it before, and its
 *   credential epoch, which ends its API tokens (CredentialEpochs): the
 *   one moved at every sign-in too, the other on these alone.
 * - **Every session is held to what its sign-in was checked against** --
 *   the account's credential epoch, read before the check, and its password
 *   hash (Credentials, ADR 0165) -- so a revocation that commits while a
 *   sign-in is under way ends the session the sign-in makes, however late
 *   its row comes. A change a session asks for, and an API token it is
 *   issued, is held to the credential epoch its request opened it at (ADR
 *   0165, 0167): a revocation landing while the request runs refuses it.
 *   A change-of-address link the worker makes later is held to the auth
 *   epoch the session asking was opened at, and none is made once a
 *   revocation has moved it (ADR 0170).
 * - **A passkey ceremony** signs a challenge bound to the session that
 *   asked and good once (PasskeyChallenges), for this host as the relying
 *   party, checked in full (WebAuthn).
 * - **A session alone changes nothing about how its account signs in.**
 *   Adding or removing a passkey, turning two-factor on and issuing an API
 *   token need the owner to have
 *   proved it is them on this session in the last five minutes -- the
 *   password typed again, one of the account's passkeys or a code from its
 *   authenticator app -- and every other such change asks for the password
 *   itself. So a stolen session cookie cannot add a way back in that
 *   outlives it, or take away the owner's, and a passkey added or removed,
 *   or an API token made, is told to the owner by email -- where the app
 *   has an https origin; with none, no account email is sent (ADR 0162).
 * - **What happened is in the audit log**, where the app keeps one (ADR
 *   0156, 0162): every sign-in, failed and not, every sign-out, and every
 *   change to how an account signs in, about that account.
 */
final class Accounts
{
    /** One message for a wrong password and an unknown email, on the field it belongs to. */
    public const string NO_MATCH = "That email and password don't match an account. Check both and try again.";

    /** One message for every refused link: which way it failed is nobody's business. */
    public const string LINK_REFUSED = 'This link has expired, has already been used, or was replaced by a newer one. Ask for a new one.';

    public const string CODE_REFUSED = "That code didn't work. Type the code your authenticator app shows now, or one of your recovery codes.";

    public const string WRONG_PASSWORD = "That isn't your current password. Type it again.";

    public const string SECOND_FACTOR_EXPIRED = 'Your sign-in waited too long for its code. Sign in again.';

    public const string ALREADY_REGISTERED = 'That address already has an account. Sign in, or reset its password.';

    public const string ADDRESS_TAKEN = 'That address belongs to another account now, so this one keeps its address. Ask for the change again with another.';

    public const string SAME_ADDRESS = 'That is already your address. Type the new one.';

    public const string TOO_MANY_PASSKEYS = 'This account keeps ' . PasskeyStore::MAX_PER_ACCOUNT . ' passkeys, the most it can. Remove one you no longer use, then add this one.';

    public const string WRONG_HOST = 'Passkeys work on this app\'s own address. Open it there, then try again.';

    /**
     * On the field of a change a signed-in session asked for: a revocation
     * -- a sign-out everywhere, a reset -- ended the session while the
     * change was under way, and nothing changed.
     */
    public const string SESSION_ENDED = 'You were signed out while this was being saved, so nothing changed. Sign in again, then try once more.';

    /** On the `confirm` field: the session has not proved it is its owner's in the last five minutes. */
    public const string CONFIRM_FIRST = "Confirm it's you first: type your password, or use a passkey or a code from your authenticator app.";

    /**
     * On the `confirm` field: the account has a second factor -- a passkey,
     * an authenticator app -- and a change to its second factors needs this
     * session to have proved one too (ADR 0184).
     */
    public const string CONFIRM_SECOND_FACTOR = "Confirm it's you with one of your passkeys or a code from your authenticator app first.";

    /** How long a confirmation lets the session change how the account signs in, in seconds. */
    public const int CONFIRMATION_LIFETIME = Sessions::CONFIRMATION_LIFETIME;

    /** The issuer an authenticator app files the account's codes under. */
    private readonly string $issuer;

    public function __construct(
        private readonly Connection $connection,
        private readonly AccountUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly AuthEpochs $epochs,
        private readonly CredentialEpochs $credentials,
        private readonly AccountLinks $links,
        private readonly Dispatcher $jobs,
        private readonly Mailer $mailer,
        private readonly AccountMails $mails,
        private readonly SignInThrottle $signInThrottle,
        private readonly SignInThrottle $secondFactorThrottle,
        private readonly LinkRequestThrottle $linkThrottle,
        private readonly TwoFactorStore $twoFactor,
        private readonly PasskeyStore $passkeys,
        private readonly PasskeyChallenges $challenges,
        // Null where the app has no https origin: then no ceremony starts.
        private readonly ?RelyingParty $relyingParty,
        #[\SensitiveParameter]
        private readonly string $key,
        string $issuer,
        // Where sign-ins and changes to how an account signs in are recorded,
        // and who is signed in, to record a sign-in as its account; neither
        // unless the app keeps an audit log.
        private readonly ?AuditLog $audit = null,
        private readonly ?AuthContext $auth = null,
    ) {
        $this->issuer = $issuer;
    }

    public function paths(): AccountPaths
    {
        return $this->links->paths;
    }

    public function users(): AccountUsers
    {
        return $this->users;
    }

    // ----------------------------------------------------------- signing up

    /**
     * Asks for a new account at $email: counted, and a job queued that sends
     * the link -- or, to an address that has an account, a note saying so.
     * The same whatever the address.
     */
    public function requestSignUp(Request $http, string $email, string $name): void
    {
        $email = Emails::normalize($email);
        if ($this->linkThrottle->hit($http, $email)) {
            $this->jobs->dispatch(new SendAccountMail(AccountMailKind::SignUp, $email, trim($name)));
        }
    }

    /**
     * What a sign-up link would make, for the page it lands on: the address
     * and name; null for every reason it would not open.
     *
     * @return array{email: string, name: string}|null
     */
    public function signUpOffer(string $token): ?array
    {
        $link = $this->links->peek($token, EmailLinks::SIGN_UP);

        return $link === null ? null : ['email' => $link->data['email'] ?? '', 'name' => $link->data['name'] ?? ''];
    }

    /**
     * Makes the account a sign-up link is for, with the password chosen on
     * the page it landed on, and signs this browser in as it. The link is
     * used and the account made in one durable transaction: an address that
     * gained an account since gives the link back unused, and says so -- to
     * whoever holds the link, which reached that inbox.
     */
    public function completeSignUp(Request $http, string $token, #[\SensitiveParameter] string $password): AccountUser
    {
        $hash = $this->passwords->hash($password);
        [$user, $checked] = $this->connection->durableTransaction(function () use ($token, $hash): array {
            $link = $this->links->consume($token, EmailLinks::SIGN_UP);
            $email = $link?->data['email'] ?? '';
            if ($link === null || $email === '') {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }
            try {
                $user = $this->users->create(Emails::normalize($email), $link->data['name'] ?? '', $hash);
            } catch (EmailTaken) {
                throw new ValidationException(['token' => [self::ALREADY_REGISTERED]]);
            }

            // What the session is held to, read where the account was made:
            // its first epoch, and the password just chosen.
            return [$user, $this->sessions->credentials($user, $hash)];
        });

        // The link proved the inbox; the password was only just chosen.
        $this->sessions->signIn(SessionState::of($http), $user, $checked, SessionFactors::of(SignInFactor::EmailLink));
        $this->signedIn($user);

        return $user;
    }

    // ----------------------------------------------- signing in by a link

    /**
     * Asks for a link that signs the account at $email in (ADR 0162), for an
     * app whose mails say one (SignInLinkMails): counted, and a job queued
     * that sends it -- or nothing, to an address with no account. The same
     * whatever the address.
     */
    public function requestSignInLink(Request $http, string $email): void
    {
        if (!$this->mails instanceof SignInLinkMails) {
            throw new \LogicException('Signing in by an emailed link needs the app\'s AccountMails to implement SignInLinkMails, which says what the email says.');
        }
        $email = Emails::normalize($email);
        if ($this->linkThrottle->hit($http, $email)) {
            $this->jobs->dispatch(new SendAccountMail(AccountMailKind::SignInLink, $email));
        }
    }

    /** The account a sign-in link is for, for the page it lands on; null for every reason it would not open. */
    public function signInLinkOffer(string $token): ?AccountUser
    {
        $link = $this->links->peek($token, EmailLinks::SIGN_IN);

        return $link === null ? null : $this->users->find($link->user);
    }

    /**
     * Signs the browser in as the account a sign-in link is for, using the
     * link -- or, with two-factor on, moves it to the second-factor stage:
     * a link proves the inbox, as a reset does, not the second factor. The
     * link is bound to the account's epoch, which every sign-in moves, so
     * signing in anywhere, this way or another, ends every link sent before.
     */
    public function signInWithLink(Request $http, string $token): SignInResult
    {
        // What the session will be held to, read before the link is used:
        // its check of the account's epoch is the last a revocation fails,
        // so one committed after it ends the session made from it. A link
        // that would not open is refused here, and stays unused.
        $offered = $this->signInLinkOffer($token);
        if ($offered === null) {
            throw new ValidationException(['token' => [self::LINK_REFUSED]]);
        }
        $checked = $this->sessions->credentials($offered);
        $user = $this->connection->durableTransaction(function () use ($token): AccountUser {
            $link = $this->links->consume($token, EmailLinks::SIGN_IN);
            $user = $link === null ? null : $this->users->find($link->user);
            if ($user === null) {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }

            return $user;
        });

        return $this->enter($http, $user, $checked, SignInFactor::EmailLink);
    }

    // ------------------------------------------------- signing in and out

    /**
     * Checks an email and password and signs the browser in -- or, for an
     * account with two-factor sign-in on, moves it to the second-factor
     * stage, signed in as nobody until a code is typed. A hash the policy
     * has moved on from is replaced on the way (rehash()).
     */
    public function signIn(Request $http, string $email, #[\SensitiveParameter] string $password): SignInResult
    {
        $email = Emails::normalize($email);
        $this->signInThrottle->hit($http, $email);

        $user = $this->users->findByEmail($email);
        // Checked either way, against a decoy as costly as the accounts, so
        // an unknown email takes what a known one does -- and, once right,
        // against a hash stored since, where a rehash finds one (rehash()).
        $hash = $this->passwords->verify($password, $user?->accountPasswordHash()) && $user !== null
            ? $this->rehash($user, $password)
            : null;
        if ($user === null || $hash === null) {
            // In the account's log, where there is one, by the address that
            // was tried: written though the sign-in fails, since it runs in
            // no transaction -- and written for an unknown address too, so
            // the work is the same either way.
            $this->record(SecurityEvent::SignInFailed, $user, ['email' => $email]);

            throw new ValidationException(['password' => [self::NO_MATCH]]);
        }
        $this->signInThrottle->succeeded($http, $email);

        // Held to the hash the password was checked against, the session
        // ends at a new password, however the two requests met. The epoch is
        // read once the password is right -- so a wrong one costs what an
        // unknown address does -- and a revocation before that read changed
        // nothing a password signs in with: the same password would sign in
        // just after it.
        return $this->enter($http, $user, $this->sessions->credentials($user, $hash), SignInFactor::Password);
    }

    /**
     * The second factor of a sign-in waiting on one: a code from the
     * account's authenticator app, or a recovery code, each good once.
     * Counted per account and per address, as passwords are.
     */
    public function completeSecondFactor(Request $http, #[\SensitiveParameter] string $code): AccountUser
    {
        $state = SessionState::of($http);
        $user = $state->pending();
        if ($user === null) {
            throw new ValidationException(['code' => [self::SECOND_FACTOR_EXPIRED]]);
        }

        $this->secondFactorThrottle->hit($http, $user->authIdentifier());
        $factor = $this->twoFactor->spend($user->authIdentifier(), $code);
        if ($factor === null) {
            throw new ValidationException(['code' => [self::CODE_REFUSED]]);
        }
        $this->secondFactorThrottle->succeeded($http, $user->authIdentifier());

        // Held to what the password, or the link, was checked against: a
        // sign-out everywhere or a new password since has ended the sign-in.
        // Signed in with what the first proved, and which code this was.
        if (!$this->sessions->passSecondFactor($state, $factor)) {
            throw new ValidationException(['code' => [self::SECOND_FACTOR_EXPIRED]]);
        }
        $this->signedIn($user);

        return $user;
    }

    public function signOut(Request $http): void
    {
        $state = SessionState::of($http);
        // In the log of the account signing out, while it is still signed in.
        if ($state->user() !== null) {
            $this->record(SecurityEvent::SignedOut, $state->user());
        }
        $this->sessions->signOut($state);
    }

    public function signOutEverywhere(Request $http): void
    {
        $state = SessionState::of($http);
        if ($state->user() !== null) {
            $this->record(SecurityEvent::SignedOutEverywhere, $state->user());
        }
        $this->sessions->signOutEverywhere($state);
    }

    // ------------------------------------------------------------ passwords

    /** Asks for a reset link for $email: counted and queued, the same whatever the address. */
    public function requestPasswordReset(Request $http, string $email): void
    {
        $email = Emails::normalize($email);
        if ($this->linkThrottle->hit($http, $email)) {
            $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordReset, $email));
        }
    }

    /** The account a reset link is for, for the page it lands on; null for every reason it would not open. */
    public function resetOffer(string $token): ?AccountUser
    {
        $link = $this->links->peek($token, EmailLinks::PASSWORD_RESET);

        return $link === null ? null : $this->users->find($link->user);
    }

    /**
     * Sets the new password, in one durable transaction with using the link,
     * ending every session the account has and moving its epoch: every other
     * device is signed out, and every token and link minted before stops
     * counting. Then this browser is signed in -- or, with two-factor on,
     * asked for its code: a reset proves the inbox, not the second factor --
     * and the owner is told by email.
     */
    public function resetPassword(Request $http, string $token, #[\SensitiveParameter] string $password): SignInResult
    {
        $hash = $this->passwords->hash($password);
        [$user, $checked] = $this->connection->durableTransaction(function () use ($token, $hash): array {
            $link = $this->links->consume($token, EmailLinks::PASSWORD_RESET);
            $user = $link === null ? null : $this->users->find($link->user);
            if ($user === null) {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }
            $this->users->changePassword($user, $hash);
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
            // In the transaction that sets it: by the link, so as a visitor.
            $this->record(SecurityEvent::PasswordChanged, $user);

            // What this browser's session is held to: the account as the
            // reset leaves it, so a revocation after the reset ends it too.
            return [$user, $this->sessions->credentials($user, $hash)];
        });

        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordChanged, userId: $user->authIdentifier()));

        // The link proved the inbox, as a sign-in link does.
        return $this->enter($http, $user, $checked, SignInFactor::EmailLink);
    }

    /**
     * Changes a signed-in account's password, its current one asked first
     * and counted as a sign-in's guess is. Every other session ends and the
     * epoch moves, in one durable transaction; this browser stays signed in,
     * under a new id.
     */
    public function changePassword(Request $http, AccountUser $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): void
    {
        $this->confirmPassword($http, $user, $current, 'currentPassword');
        $hash = $this->passwords->hash($new);
        $checked = $this->connection->durableTransaction(function () use ($http, $user, $hash): Credentials {
            $this->users->changePassword($user, $hash);
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
            $this->assertSessionStands($http, $user, 'currentPassword');
            $this->record(SecurityEvent::PasswordChanged, $user);

            return $this->sessions->credentials($user, $hash);
        });

        // Signed in again as it was: a change asked for proves nothing new.
        $state = SessionState::of($http);
        $this->sessions->signIn($state, $user, $checked, $state->factors());
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordChanged, userId: $user->authIdentifier()));
    }

    // ------------------------------------------------------------ addresses

    /**
     * Asks to move a signed-in account to $email, its password asked first:
     * the link goes to the new address, which must prove it is read before
     * anything changes. Queued the same whether or not the address is
     * another account's, which the job finds out.
     *
     * The job carries the auth epoch this session was opened at, and the
     * link the worker makes is held to it (ADR 0170): a reset or a sign-out
     * everywhere committing before the worker runs it -- or between its
     * retries -- leaves no link to the address this session chose. One that
     * committed once this request began ended the session, and the request
     * is refused (SESSION_ENDED on `currentPassword`), as any change a
     * session asks for is (ADR 0165).
     */
    public function requestEmailChange(Request $http, AccountUser $user, string $email, #[\SensitiveParameter] string $password): void
    {
        $email = Emails::normalize($email);
        if ($email === $user->accountEmail()) {
            throw new ValidationException(['email' => [self::SAME_ADDRESS]]);
        }
        $this->confirmPassword($http, $user, $password, 'currentPassword');
        // The credential epoch is read after the auth epoch was, at open():
        // where it has not moved since the session was opened, no
        // revocation had moved the auth epoch either, as both move in one
        // transaction -- so the epoch the job carries is the session's.
        $state = SessionState::of($http);
        $held = $state->user()?->authIdentifier() === $user->authIdentifier() ? $state->credentialEpoch() : null;
        if ($held === null || $state->epoch() === '' || $this->credentials->of($user->authIdentifier()) !== $held) {
            throw new ValidationException(['currentPassword' => [self::SESSION_ENDED]]);
        }
        if ($this->linkThrottle->hit($http, $email)) {
            $this->jobs->dispatch(new SendAccountMail(AccountMailKind::EmailChange, $email, userId: $user->authIdentifier(), epoch: $state->epoch()));
        }
    }

    /**
     * What a change-of-address link would do, for the page it lands on.
     *
     * @return array{user: AccountUser, email: string}|null
     */
    public function emailChangeOffer(string $token): ?array
    {
        $link = $this->links->peek($token, EmailLinks::CHANGE_EMAIL);
        $user = $link === null ? null : $this->users->find($link->user);
        $email = $link?->data['email'] ?? '';

        return $user === null || $email === '' ? null : ['user' => $user, 'email' => $email];
    }

    /**
     * Moves the account to the address its link reached, in one durable
     * transaction with using the link that ends every session and moves its
     * epochs, so every session, link and token minted for the old address
     * stops counting, as a change of password does (ADR 0153). The old
     * address is told. A browser signed in as the account stays signed in,
     * under a new id.
     */
    public function confirmEmailChange(Request $http, string $token): AccountUser
    {
        [$user, $old, $checked] = $this->connection->durableTransaction(function () use ($token): array {
            $link = $this->links->consume($token, EmailLinks::CHANGE_EMAIL);
            $user = $link === null ? null : $this->users->find($link->user);
            $email = $link?->data['email'] ?? '';
            if ($user === null || $email === '') {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }
            $old = $user->accountEmail();
            try {
                $this->users->changeEmail($user, Emails::normalize($email));
            } catch (EmailTaken) {
                throw new ValidationException(['token' => [self::ADDRESS_TAKEN]]);
            }
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
            $this->record(SecurityEvent::EmailChanged, $user);
            $user = $this->users->find($user->authIdentifier()) ?? $user;

            return [$user, $old, $this->sessions->credentials($user)];
        });

        $state = SessionState::of($http);
        if ($state->user()?->authIdentifier() === $user->authIdentifier()) {
            $this->sessions->signIn($state, $user, $checked, $state->factors());
        }
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::EmailChanged, $old, userId: $user->authIdentifier()));

        return $user;
    }

    // --------------------------------------------------------- confirming

    /** Whether this session's owner proved it is them in the last five minutes. */
    public function recentlyConfirmed(Request $http): bool
    {
        return $this->confirmedUntil($http) !== null;
    }

    /** Until when this session's last confirmation counts, in Unix seconds; null when it does not. */
    public function confirmedUntil(Request $http): ?int
    {
        return $this->sessions->confirmedUntil(SessionState::of($http));
    }

    /** Confirms the session with the account's password, counted as a sign-in's guess. */
    public function confirmWithPassword(Request $http, AccountUser $user, #[\SensitiveParameter] string $password): void
    {
        $this->confirmPassword($http, $user, $password, 'currentPassword');
    }

    /**
     * Confirms the session with a code from the account's authenticator
     * app, counted as any second factor is, and adds the code to what the
     * session proved, under a new id (Sessions::stepUp(), ADR 0184): a
     * session signed in by a password alone is then one of two factors.
     * Not a recovery code: those are
     * for a sign-in that has lost the app, and spending one here would be
     * spending it for nothing.
     */
    public function confirmWithCode(Request $http, AccountUser $user, #[\SensitiveParameter] string $code): void
    {
        $this->secondFactorThrottle->hit($http, $user->authIdentifier());
        $compact = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . Totp::DIGITS . '}\z/', $compact) !== 1 || !$this->twoFactor->verify($user->authIdentifier(), $compact)) {
            throw new ValidationException(['code' => ["That code didn't work. Type the code your authenticator app shows now."]]);
        }
        $this->secondFactorThrottle->succeeded($http, $user->authIdentifier());
        $this->stepUp($http, $user, SignInFactor::AuthenticatorCode, 'code');
    }

    /**
     * What navigator.credentials.get() is handed to confirm the session
     * with one of the account's passkeys: a challenge bound to this session
     * and account, and the account's passkeys as the ones to offer.
     *
     * @return array<string, mixed>
     */
    public function passkeyConfirmationOptions(Request $http, AccountUser $user): array
    {
        $party = $this->assertRelyingPartyHost($http);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::CONFIRM, $user->authIdentifier());

        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $party->id,
            'userVerification' => 'required',
            'timeout' => PasskeyChallenges::LIFETIME * 1000,
            'allowCredentials' => array_map(
                static fn (Passkey $passkey): array => ['type' => 'public-key', 'id' => Base64Url::encode($passkey->credentialId), 'transports' => $passkey->transports],
                $this->passkeys->of($user->authIdentifier()),
            ),
        ];
    }

    /**
     * Confirms the session with one of the account's own passkeys, verified
     * as a sign-in's is -- its user verified -- and steps it up to two
     * factors under a new id (Sessions::stepUp(), ADR 0184).
     */
    public function confirmWithPasskey(Request $http, AccountUser $user, string $credential): void
    {
        $this->verifyPasskey($http, $credential, PasskeyChallenges::CONFIRM, $user);
        $this->stepUp($http, $user, SignInFactor::Passkey, 'credential');
    }

    /**
     * Whether this session's sign-in proved two factors (ADR 0184): a
     * passkey, or a password or an emailed link and a code -- at sign-in, or
     * since, by confirming with a code or a passkey. What a page a stolen
     * password must not open asks (RequireMultiFactor).
     */
    public function multiFactor(Request $http): bool
    {
        return SessionState::of($http)->factors()->multiFactor();
    }

    // ----------------------------------------------------------- two-factor

    public function twoFactorEnabled(AccountUser $user): bool
    {
        return $this->twoFactor->enabled($user->authIdentifier());
    }

    public function recoveryCodesLeft(AccountUser $user): int
    {
        return $this->twoFactor->recoveryCodesLeft($user->authIdentifier());
    }

    /**
     * A new secret for the account's authenticator app, unconfirmed until a
     * code from it is typed (confirmTwoFactor()): the base32 a person types,
     * and the otpauth:// URI a QR code carries. The session must have been
     * confirmed in the last five minutes.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginTwoFactor(Request $http, AccountUser $user): array
    {
        $this->assertRecentlyConfirmed($http);
        $this->assertMayChangeFactors($http, $user);
        $secret = $this->twoFactor->begin($user->authIdentifier());

        return ['secret' => Base32::encode($secret), 'uri' => Totp::uri($secret, $this->issuer, $user->accountEmail())];
    }

    /**
     * The secret setup is waiting on, again -- for the page that shows it
     * while the code is typed -- or null when setup has not begun.
     *
     * @return array{secret: string, uri: string}|null
     */
    public function pendingTwoFactor(AccountUser $user): ?array
    {
        $secret = $this->twoFactor->pendingSecret($user->authIdentifier());

        return $secret === null ? null : ['secret' => Base32::encode($secret), 'uri' => Totp::uri($secret, $this->issuer, $user->accountEmail())];
    }

    /**
     * Turns two-factor sign-in on with a code from the new secret, counted
     * as any second factor is; the recovery codes, to show once. The
     * session must have been confirmed in the last five minutes.
     *
     * @return list<string>
     */
    public function confirmTwoFactor(Request $http, AccountUser $user, #[\SensitiveParameter] string $code): array
    {
        $this->assertRecentlyConfirmed($http);
        $this->assertMayChangeFactors($http, $user);
        $this->secondFactorThrottle->hit($http, $user->authIdentifier());
        $codes = $this->twoFactor->confirm($user->authIdentifier(), $code);
        if ($codes === null) {
            throw new ValidationException(['code' => ["That code didn't match. Type the code your authenticator app shows for this account now."]]);
        }
        $this->secondFactorThrottle->succeeded($http, $user->authIdentifier());
        $this->record(SecurityEvent::TwoFactorEnabled, $user);

        return $codes;
    }

    /**
     * Turns two-factor sign-in off, the password asked first. Every other
     * session ends and both epochs move, in one durable transaction with
     * the secret's removal -- a session or an API token that got in while
     * the second factor was asked for was let in under a rule that no longer
     * stands, and one a thief holds must not outlive what guarded it -- and
     * the owner is told by email. This browser stays signed in, under a new
     * id.
     */
    public function disableTwoFactor(Request $http, AccountUser $user, #[\SensitiveParameter] string $password): void
    {
        $this->assertMayChangeFactors($http, $user);
        $this->confirmPassword($http, $user, $password, 'currentPassword');
        $checked = $this->connection->durableTransaction(function () use ($http, $user): Credentials {
            $this->twoFactor->disable($user->authIdentifier());
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
            $this->assertSessionStands($http, $user, 'currentPassword');
            $this->record(SecurityEvent::TwoFactorDisabled, $user);

            return $this->sessions->credentials($user);
        });

        $state = SessionState::of($http);
        $this->sessions->signIn($state, $user, $checked, $state->factors());
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::TwoFactorDisabled, userId: $user->authIdentifier()));
    }

    /**
     * New recovery codes in place of the old, the password asked first.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Request $http, AccountUser $user, #[\SensitiveParameter] string $password): array
    {
        $this->assertMayChangeFactors($http, $user);
        $this->confirmPassword($http, $user, $password, 'currentPassword');
        if (!$this->twoFactor->enabled($user->authIdentifier())) {
            throw new ValidationException(['currentPassword' => ['Two-factor sign-in is off, so there are no recovery codes to replace. Turn it on first.']]);
        }

        return $this->twoFactor->regenerateRecoveryCodes($user->authIdentifier());
    }

    // ------------------------------------------------------------- passkeys

    /** @return list<Passkey> */
    public function passkeys(AccountUser $user): array
    {
        return $this->passkeys->of($user->authIdentifier());
    }

    /**
     * What the browser's navigator.credentials.create() is handed to add a
     * passkey to a signed-in account: a challenge bound to this session and
     * account, this host as the relying party, a discoverable credential
     * that verifies its user, ES256 or RS256, and the account's passkeys
     * listed so an authenticator does not make a second for it. The session
     * must have been confirmed in the last five minutes.
     *
     * @return array<string, mixed>
     */
    public function passkeyRegistrationOptions(Request $http, AccountUser $user): array
    {
        $party = $this->assertRelyingPartyHost($http);
        $this->assertRecentlyConfirmed($http);
        $this->assertMayChangeFactors($http, $user);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::REGISTER, $user->authIdentifier());

        return [
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $party->id, 'name' => $party->name],
            'user' => [
                'id' => Base64Url::encode($this->userHandle($user)),
                'name' => $user->accountEmail(),
                'displayName' => $user->accountName() === '' ? $user->accountEmail() : $user->accountName(),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => PasskeyChallenges::LIFETIME * 1000,
            'excludeCredentials' => array_map(
                static fn (Passkey $passkey): array => ['type' => 'public-key', 'id' => Base64Url::encode($passkey->credentialId), 'transports' => $passkey->transports],
                $this->passkeys->of($user->authIdentifier()),
            ),
        ];
    }

    /**
     * Adds the passkey the browser made: its challenge used up, its
     * attestation verified for this host, and kept under $name -- while the
     * session's confirmation still counts. The owner is told by email: a
     * passkey outlives every session, "sign out everywhere" included.
     */
    public function registerPasskey(Request $http, AccountUser $user, string $credential, string $name): Passkey
    {
        $party = $this->assertRelyingPartyHost($http);
        $this->assertRecentlyConfirmed($http);
        $this->assertMayChangeFactors($http, $user);
        $challenge = $this->challenges->consume(SessionState::of($http)->id, PasskeyChallenges::REGISTER, $user->authIdentifier());
        if ($challenge === null) {
            throw new ValidationException(['credential' => ['Adding the passkey took too long, or was started in another tab. Try again.']]);
        }
        if ($this->passkeys->count($user->authIdentifier()) >= PasskeyStore::MAX_PER_ACCOUNT) {
            throw new ValidationException(['credential' => [self::TOO_MANY_PASSKEYS]]);
        }

        try {
            $parts = self::credential($credential, ['clientDataJSON', 'attestationObject']);
            $verified = new WebAuthn($party)->verifyRegistration($parts['clientDataJSON'], $parts['attestationObject'], $challenge);
            if (!hash_equals($verified->credentialId, $parts['rawId'])) {
                throw new PasskeyRefused('malformed');
            }
        } catch (PasskeyRefused $e) {
            throw new ValidationException(['credential' => [$e->getMessage()]]);
        }

        $label = trim($name) === '' ? 'Passkey' : trim($name);
        if (!$this->passkeys->add($user->authIdentifier(), $this->userHandle($user), $verified, $label, $parts['transports'])) {
            throw new ValidationException(['credential' => ['That passkey is already on an account. Use it to sign in, or make a new one.']]);
        }

        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasskeyAdded, name: $label, userId: $user->authIdentifier()));

        foreach ($this->passkeys->of($user->authIdentifier()) as $passkey) {
            if (hash_equals($passkey->credentialId, $verified->credentialId)) {
                return $passkey;
            }
        }

        throw new \LogicException('A passkey just kept was not found again.');
    }

    /**
     * What navigator.credentials.get() is handed to sign in with a passkey:
     * a challenge bound to this session, this host, and no account named --
     * the authenticator offers whichever passkeys it holds for the host.
     *
     * @return array<string, mixed>
     */
    public function passkeySignInOptions(Request $http): array
    {
        $party = $this->assertRelyingPartyHost($http);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::AUTHENTICATE);

        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $party->id,
            'userVerification' => 'required',
            'timeout' => PasskeyChallenges::LIFETIME * 1000,
            'allowCredentials' => [],
        ];
    }

    /**
     * Signs in with a passkey: its challenge used up, the credential found
     * by its id and held to the account handle it was made with, the
     * assertion verified for this host with its user verified -- which is
     * both factors, so no code is asked -- and its sign count moved on.
     */
    public function signInWithPasskey(Request $http, string $credential): AccountUser
    {
        [$user, $checked] = $this->verifyPasskey($http, $credential, PasskeyChallenges::AUTHENTICATE);
        $this->sessions->signIn(SessionState::of($http), $user, $checked, SessionFactors::of(SignInFactor::Passkey));
        $this->signedIn($user);

        return $user;
    }

    /**
     * Removes one of the account's passkeys -- while the session's
     * confirmation still counts, as adding one asks -- and tells the owner
     * by email; false when it is not the account's. A session alone would
     * otherwise take away the owner's way back in: a thief with a cookie
     * removing the passkeys before turning the password over.
     *
     * A passkey is removed because it is lost or no longer trusted, so what
     * it let in goes with it, as a change of password ends what the old one
     * did: every other session ends and both epochs move, ending the API
     * tokens held to the credential epoch, in one durable transaction with
     * the removal. This browser stays signed in, under a new id.
     */
    public function removePasskey(Request $http, AccountUser $user, string $id): bool
    {
        $this->assertRecentlyConfirmed($http);
        $this->assertMayChangeFactors($http, $user);
        $name = null;
        foreach ($this->passkeys->of($user->authIdentifier()) as $passkey) {
            if ($passkey->id === $id) {
                $name = $passkey->name;
            }
        }
        if ($name === null) {
            return false;
        }
        $checked = $this->connection->durableTransaction(function () use ($http, $user, $id): ?Credentials {
            if (!$this->passkeys->remove($user->authIdentifier(), $id)) {
                return null;
            }
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
            $this->assertSessionStands($http, $user, 'confirm');

            return $this->sessions->credentials($user);
        });
        if ($checked === null) {
            return false;
        }

        $state = SessionState::of($http);
        $this->sessions->signIn($state, $user, $checked, $state->factors());
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasskeyRemoved, name: $name, userId: $user->authIdentifier()));

        return true;
    }

    // ------------------------------------------------------------ API tokens

    /**
     * Issues an API token for the account (ADR 0159) -- while the session's
     * confirmation still counts, as adding a passkey asks -- and tells the
     * owner by email, naming it, where the app has an https origin (ADR
     * 0162). A token outlives the session that made it
     * and every sign-in, so a session alone would otherwise mint a lasting
     * way in: a thief with a cookie leaving with a credential that "sign out
     * everywhere" is the only thing to end.
     *
     * The token is held to the credential epoch this session was held to
     * when the request opened it (SessionState::credentialEpoch(), ADR 0167),
     * as a change the session asks for is (ADR 0165): a sign-out everywhere
     * or the owner's reset committing while the request ran ended the
     * session, and the token is refused rather than held to the epoch after
     * the revocation, which it would outlive.
     *
     * $tokens is the application's ApiTokens (the Kernel's, from its
     * ApiAccess), which holds the token to its user, epoch, tenant, panel and
     * host, and to the lifetime the application allows. Its lookup is the
     * kit's $tokenHolders, whose epoch is the credential epoch: one that
     * counts another epoch refuses every token asked for here.
     *
     * @param list<string|\BackedEnum> $scopes
     * @throws ValidationException on `confirm` (CONFIRM_FIRST) while the session is not confirmed
     * @throws ApiTokenRefused for a choice that will not do, or a caller who may not have one --
     *         a session a revocation ended once the request began among them (ApiTokens::SIGNED_OUT,
     *         a 403 for ApiTokenChoice::Caller)
     */
    public function issueApiToken(Request $http, AccountUser $user, ApiTokens $tokens, string $name, array $scopes, ?CarbonImmutable $expiresAt): IssuedApiToken
    {
        $this->assertRecentlyConfirmed($http);
        // As a caller who may not have one, shown above the form, rather than
        // SESSION_ENDED on `confirm`, which an app's form takes as asking for
        // a confirmation: no field of its form is at fault.
        $state = SessionState::of($http);
        $epoch = $state->user()?->authIdentifier() === $user->authIdentifier() ? $state->credentialEpoch() : null;
        if ($epoch === null) {
            throw new ApiTokenRefused(ApiTokenChoice::Caller, ApiTokens::SIGNED_OUT, 403);
        }
        $issued = $tokens->issue($name, $scopes, $expiresAt, epoch: $epoch);
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::ApiTokenCreated, name: $issued->token->name, userId: $user->authIdentifier()));

        return $issued;
    }

    // ------------------------------------------------------------- the worker

    /**
     * Works out and sends an account email, on the queue worker
     * (SendAccountMail): whether the address has an account is found out
     * here, where nobody is waiting, and the link is made and sent at once.
     */
    public function deliver(SendAccountMail $job): void
    {
        // Every account email links to the app, and an app with no https
        // origin has nowhere to link: nothing is sent.
        if ($this->links->origin === null) {
            return;
        }
        $user = $job->userId === '' ? null : $this->users->find($job->userId);
        $resetPage = $this->links->page($this->links->paths->forgotPassword);
        $minutes = static fn (string $purpose): int => intdiv(EmailLinks::LIFETIMES[$purpose], 60);

        $mailable = match ($job->kind) {
            AccountMailKind::SignUp => ($existing = $this->users->findByEmail($job->email)) === null
                ? $this->mails->signUp($job->email, $job->name, $this->links->signUp($job->email, $job->name), $minutes(EmailLinks::SIGN_UP))
                : $this->mails->alreadyRegistered($existing, $this->links->page($this->links->paths->signIn), $resetPage),
            AccountMailKind::SignInLink => !$this->mails instanceof SignInLinkMails || ($existing = $this->users->findByEmail($job->email)) === null
                ? null
                : $this->mails->signInLink($existing, $this->links->signIn($existing), $minutes(EmailLinks::SIGN_IN)),
            AccountMailKind::PasswordReset => ($existing = $this->users->findByEmail($job->email)) === null
                ? null
                : $this->mails->passwordReset($existing, $this->links->passwordReset($existing), $minutes(EmailLinks::PASSWORD_RESET)),
            // To an address another account has, nothing: the account asking
            // would learn whose it is from whatever came back. Nor once the
            // epoch the session asking was opened at has moved -- a reset, a
            // sign-out everywhere -- and the link is held to that epoch
            // rather than this one, which a revocation committing as it is
            // made could have moved (ADR 0170).
            AccountMailKind::EmailChange => $user === null || $job->epoch === '' || $this->epochs->of($user->authIdentifier()) !== $job->epoch
                || $this->users->findByEmail($job->email) !== null
                ? null
                : $this->mails->emailChange($user, $job->email, $this->links->changeEmail($user, $job->email, $job->epoch), $minutes(EmailLinks::CHANGE_EMAIL)),
            AccountMailKind::EmailChanged => $user === null ? null : $this->mails->emailChanged($user, $job->email, $resetPage),
            AccountMailKind::PasswordChanged => $user === null ? null : $this->mails->passwordChanged($user, $resetPage),
            AccountMailKind::PasskeyAdded => $user === null ? null : $this->mails->passkeyAdded($user, $job->name, $this->links->page($this->links->paths->account), $resetPage),
            AccountMailKind::PasskeyRemoved => $user === null ? null : $this->mails->passkeyRemoved($user, $job->name, $this->links->page($this->links->paths->account), $resetPage),
            AccountMailKind::TwoFactorDisabled => $user === null ? null : $this->mails->twoFactorDisabled($user, $resetPage),
            AccountMailKind::ApiTokenCreated => $user === null ? null : $this->mails->apiTokenCreated($user, $job->name, $this->links->page($this->links->paths->apiTokens), $resetPage),
        };

        if ($mailable === null) {
            return;
        }
        // A provider's refusal for good fails the job at once: tried again
        // through its backoff, each try would make another link, for a send
        // that can never succeed.
        SendMail::attempt(fn () => $this->mailer->send($mailable));

        // A reset asked for is in the account's log, written here where the
        // account is known -- the request that asked answered the same for
        // every address -- and once its email has gone, so a retry records
        // it once.
        if ($job->kind === AccountMailKind::PasswordReset) {
            $this->record(SecurityEvent::PasswordResetRequested, $this->users->findByEmail($job->email));
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * A passkey assertion for this session's challenge for $purpose,
     * verified: the credential found by its id and held to the account
     * handle it was made with -- and, to confirm a session, to $owner's
     * account -- the assertion verified for this host with its user
     * verified, and its sign count moved on. Its account, and what a
     * session it signs in is held to.
     *
     * @return array{AccountUser, Credentials}
     */
    private function verifyPasskey(Request $http, string $credential, string $purpose, ?AccountUser $owner = null): array
    {
        $party = $this->assertRelyingPartyHost($http);
        $state = SessionState::of($http);
        $refused = static fn (string $message = PasskeyRefused::TRY_AGAIN): ValidationException => new ValidationException(['credential' => [$message]]);
        $notThisAccount = 'That passkey is not on this account. Use one you added here, or type your password.';

        try {
            $parts = self::credential($credential, ['clientDataJSON', 'authenticatorData', 'signature']);
        } catch (PasskeyRefused $e) {
            throw $refused($e->getMessage());
        }
        // Per address, and per credential: a passkey cannot be guessed, but
        // verifying costs a signature check, and a flood of them is someone's.
        $this->signInThrottle->hit($http, 'passkey:' . Base64Url::encode($parts['rawId']));

        $challenge = $this->challenges->consume($state->id, $purpose, $owner?->authIdentifier() ?? '');
        if ($challenge === null) {
            throw $refused('Using the passkey took too long, or was started in another tab. Try again.');
        }
        $passkey = $this->passkeys->byCredentialId($parts['rawId']);
        if ($passkey === null) {
            throw $refused($owner === null
                ? 'That passkey is not on any account here. Sign in with your password, then add it on your account page.'
                : $notThisAccount);
        }
        if ($owner !== null && $passkey->userId !== $owner->authIdentifier()) {
            throw $refused($notThisAccount);
        }
        if ($parts['userHandle'] !== null && !hash_equals($passkey->userHandle, $parts['userHandle'])) {
            throw $refused();
        }

        try {
            $assertion = new WebAuthn($party)->verifyAssertion(
                $parts['clientDataJSON'],
                $parts['authenticatorData'],
                $parts['signature'],
                $challenge,
                $passkey->publicKey,
                $passkey->signCount,
            );
        } catch (PasskeyRefused $e) {
            throw $refused($e->getMessage());
        }
        $user = $this->users->find($passkey->userId);
        if ($user === null) {
            throw $refused();
        }
        // What a session will be held to, read before the passkey is used:
        // that is the last of its checks, the one a removal fails, so a
        // removal committed after it ends the session made from it.
        $checked = $this->sessions->credentials($user);
        if (!$this->passkeys->used($passkey, $assertion)) {
            throw $refused();
        }
        $this->signInThrottle->succeeded($http, 'passkey:' . Base64Url::encode($parts['rawId']));

        return [$user, $checked];
    }

    /**
     * Inside the transaction of a change this session asked for, once the
     * change has moved the account's credential epoch: refused -- the whole
     * change rolled back, SESSION_ENDED on $field -- unless it moved the
     * epoch on from the one the session was held to when this request opened
     * it. A revocation committed since, a sign-out everywhere or the owner's
     * reset, ended the session, and the change it asked for would otherwise
     * move the epoch past that revocation and sign its browser in again: a
     * thief's new password undoing the owner's reset (ADR 0165). Read after
     * the change's own move, which holds the epoch's row until the change
     * commits, so no revocation lands between the two.
     */
    private function assertSessionStands(Request $http, AccountUser $user, string $field): void
    {
        $state = SessionState::of($http);
        $from = $state->user()?->authIdentifier() === $user->authIdentifier() ? $state->credentialEpoch() : null;
        if ($from === null || $this->credentials->of($user->authIdentifier()) !== (string) ((int) $from + 1)) {
            throw new ValidationException([$field => [self::SESSION_ENDED]]);
        }
    }

    /**
     * The hash a password just checked against $user's signs in against:
     * that one -- or, where the policy has moved on from it, the password
     * hashed again and stored in its place while the password is known, the
     * account's sessions held to the hash replaced held to the new one in
     * the same transaction (Sessions::rehashed()).
     *
     * Stored only in place of the hash checked, by a compare-and-set
     * (AccountUsers::changePasswordFrom(), ADR 0168). Where another request
     * stored one since -- a reset or a change, whose new password must stay,
     * or another sign-in's rehash of this one, a double click on the form,
     * which the sessions are held to now -- nothing is written or re-sealed,
     * and the password is checked against the hash stored: the one to sign
     * in against while the password is still the account's, and null once
     * it is not.
     */
    private function rehash(AccountUser $user, #[\SensitiveParameter] string $password): ?string
    {
        $hash = $user->accountPasswordHash();
        if (!$this->passwords->needsRehash($hash)) {
            return $hash;
        }

        $rehashed = $this->passwords->hash($password);
        $replaced = $this->connection->transaction(function () use ($user, $hash, $rehashed): bool {
            if (!$this->users->changePasswordFrom($user, $hash, $rehashed)) {
                return false;
            }
            $this->sessions->rehashed($user, $hash, $rehashed);

            return true;
        });
        if ($replaced) {
            return $rehashed;
        }

        // Read once that transaction is over, a statement of its own: inside
        // it, MySQL's REPEATABLE READ may answer a plain read from a snapshot
        // older than the write that got there first.
        $stored = $this->users->storedPasswordHash($user);

        return $stored !== null && $this->passwords->verify($password, $stored) ? $stored : null;
    }

    /**
     * The session's confirmation, with a code or a passkey, added to what it
     * proved (Sessions::stepUp()); SESSION_ENDED on $field where it ended
     * once the request began.
     */
    private function stepUp(Request $http, AccountUser $user, SignInFactor $factor, string $field): void
    {
        if (!$this->sessions->stepUp(SessionState::of($http), $user, $factor)) {
            throw new ValidationException([$field => [self::SESSION_ENDED]]);
        }
    }

    /**
     * A change to an account's second factors -- a passkey added or removed,
     * an authenticator app turned on or off, new recovery codes -- refused
     * (CONFIRM_SECOND_FACTOR on `confirm`) once the account has one, unless
     * this session proved two factors (ADR 0184). A password is all a thief
     * may have, and a factor of their own they could add with it, or the
     * owner's they could take away to add one, would sign them in with two:
     * past every page that asks for two. The first second factor is added
     * from a session of one, as it has to be.
     */
    private function assertMayChangeFactors(Request $http, AccountUser $user): void
    {
        $id = $user->authIdentifier();
        if (SessionState::of($http)->factors()->multiFactor() || (!$this->twoFactor->enabled($id) && $this->passkeys->count($id) === 0)) {
            return;
        }

        throw new ValidationException(['confirm' => [self::CONFIRM_SECOND_FACTOR]]);
    }

    /** A change to how the account signs in -- or a token made for it -- refused unless the session was confirmed in the last five minutes. */
    private function assertRecentlyConfirmed(Request $http): void
    {
        if (!$this->recentlyConfirmed($http)) {
            throw new ValidationException(['confirm' => [self::CONFIRM_FIRST]]);
        }
    }

    /**
     * Signs in, or moves to the second-factor stage where two-factor is on,
     * held to what the sign-in was checked against, with $first, what it
     * proved.
     */
    private function enter(Request $http, AccountUser $user, Credentials $checked, SignInFactor $first): SignInResult
    {
        $state = SessionState::of($http);
        if ($this->twoFactor->enabled($user->authIdentifier())) {
            $this->sessions->beginSecondFactor($state, $user, $checked, SessionFactors::of($first));

            return SignInResult::SecondFactor;
        }
        $this->sessions->signIn($state, $user, $checked, SessionFactors::of($first));
        $this->signedIn($user);

        return SignInResult::SignedIn;
    }

    /** A sign-in in the account's log, as the account it signed in: the request began with nobody. */
    private function signedIn(AccountUser $user): void
    {
        $audit = $this->audit;
        if ($audit === null) {
            return;
        }
        $subject = $user instanceof Model ? $user : null;
        if ($this->auth === null) {
            $audit->security(SecurityEvent::SignedIn, $subject);

            return;
        }
        $this->auth->actingAs($user, static function () use ($audit, $subject): void {
            $audit->security(SecurityEvent::SignedIn, $subject);
        });
    }

    /**
     * $event in the audit log, where the app keeps one, about $user's account
     * -- its model, where the app's users are models -- as whoever is
     * signed in.
     *
     * @param array<string, mixed> $data
     */
    private function record(SecurityEvent $event, ?AccountUser $user, array $data = []): void
    {
        $this->audit?->security($event, $user instanceof Model ? $user : null, $data);
    }

    /**
     * The account's current password, asked before a change and counted as
     * a sign-in's guess; right, it confirms the session too.
     */
    private function confirmPassword(Request $http, AccountUser $user, #[\SensitiveParameter] string $password, string $field): void
    {
        $this->signInThrottle->hit($http, $user->accountEmail());
        if (!$this->passwords->verify($password, $user->accountPasswordHash())) {
            throw new ValidationException([$field => [self::WRONG_PASSWORD]]);
        }
        $this->signInThrottle->succeeded($http, $user->accountEmail());
        $this->sessions->confirm(SessionState::of($http), $user);
    }

    /**
     * The account's passkey user handle: 32 bytes no one can tie to its id
     * or address without the app key, the same for every passkey it has.
     */
    private function userHandle(AccountUser $user): string
    {
        return hash_hmac('sha256', 'passkey-user-handle:' . $user->authIdentifier(), $this->key, true);
    }

    /**
     * A ceremony is for the relying party's host and no other: the session
     * it is bound to is that host's, and an assertion made for it would not
     * verify for another anyway. An app with no https origin has no relying
     * party, and starts none.
     */
    private function assertRelyingPartyHost(Request $http): RelyingParty
    {
        $party = $this->relyingParty;
        if ($party === null || !hash_equals($party->host(), Sessions::realmOf($http))) {
            throw new ValidationException(['credential' => [self::WRONG_HOST]]);
        }

        return $party;
    }

    /**
     * A PublicKeyCredential as the browser's toJSON() writes it -- every
     * buffer base64url -- read into raw bytes: its id, and the response
     * fields asked for.
     *
     * @param list<string> $fields
     * @return array{rawId: string, userHandle: ?string, transports: list<string>, clientDataJSON: string, attestationObject: string, authenticatorData: string, signature: string}
     */
    private static function credential(string $json, array $fields): array
    {
        if (\strlen($json) > 65_536) {
            throw PasskeyRefused::malformed();
        }
        try {
            $credential = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PasskeyRefused::malformed();
        }
        $id = \is_array($credential) ? ($credential['id'] ?? null) : null;
        $response = \is_array($credential) ? ($credential['response'] ?? null) : null;
        if (!\is_array($credential) || !\is_string($id) || ($credential['type'] ?? null) !== 'public-key' || !\is_array($response)
            || (($credential['rawId'] ?? $id) !== $id)) {
            throw PasskeyRefused::malformed();
        }
        $rawId = Base64Url::decode($id) ?? throw PasskeyRefused::malformed();

        $decoded = [];
        foreach (['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature'] as $field) {
            $value = $response[$field] ?? null;
            $decoded[$field] = \is_string($value) ? Base64Url::decode($value) : null;
            if (\in_array($field, $fields, true) && $decoded[$field] === null) {
                throw PasskeyRefused::malformed();
            }
        }
        $handle = $response['userHandle'] ?? null;
        $userHandle = null;
        if (\is_string($handle) && $handle !== '') {
            $userHandle = Base64Url::decode($handle) ?? throw PasskeyRefused::malformed();
        }
        $transports = $response['transports'] ?? [];

        return [
            'rawId' => $rawId,
            'userHandle' => $userHandle,
            'transports' => \is_array($transports) ? array_values(array_filter($transports, \is_string(...))) : [],
            'clientDataJSON' => $decoded['clientDataJSON'] ?? '',
            'attestationObject' => $decoded['attestationObject'] ?? '',
            'authenticatorData' => $decoded['authenticatorData'] ?? '',
            'signature' => $decoded['signature'] ?? '',
        ];
    }
}
