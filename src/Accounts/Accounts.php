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
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\IssuedApiToken;
use Tinvar\Http\Request;
use Tinvar\Identity\EmailLinks;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\SendMail;
use Tinvar\Orm\Connection;
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
 *   or an API token made, is told to the owner by email.
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

    /** On the `confirm` field: the session has not proved it is its owner's in the last five minutes. */
    public const string CONFIRM_FIRST = "Confirm it's you first: type your password, or use a passkey or a code from your authenticator app.";

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
        private readonly RelyingParty $relyingParty,
        #[\SensitiveParameter]
        private readonly string $key,
        string $issuer,
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
        $user = $this->connection->durableTransaction(function () use ($token, $hash): AccountUser {
            $link = $this->links->consume($token, EmailLinks::SIGN_UP);
            $email = $link?->data['email'] ?? '';
            if ($link === null || $email === '') {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }
            try {
                return $this->users->create(Emails::normalize($email), $link->data['name'] ?? '', $hash);
            } catch (EmailTaken) {
                throw new ValidationException(['token' => [self::ALREADY_REGISTERED]]);
            }
        });

        $this->sessions->signIn(SessionState::of($http), $user);

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
        $user = $this->connection->durableTransaction(function () use ($token): AccountUser {
            $link = $this->links->consume($token, EmailLinks::SIGN_IN);
            $user = $link === null ? null : $this->users->find($link->user);
            if ($user === null) {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }

            return $user;
        });

        return $this->enter($http, $user);
    }

    // ------------------------------------------------- signing in and out

    /**
     * Checks an email and password and signs the browser in -- or, for an
     * account with two-factor sign-in on, moves it to the second-factor
     * stage, signed in as nobody until a code is typed.
     */
    public function signIn(Request $http, string $email, #[\SensitiveParameter] string $password): SignInResult
    {
        $email = Emails::normalize($email);
        $this->signInThrottle->hit($http, $email);

        $user = $this->users->findByEmail($email);
        // Checked either way, against a decoy as costly as the accounts, so
        // an unknown email takes what a known one does.
        if (!$this->passwords->verify($password, $user?->accountPasswordHash()) || $user === null) {
            throw new ValidationException(['password' => [self::NO_MATCH]]);
        }
        $this->signInThrottle->succeeded($http, $email);

        // The one moment the password is known: a hash the policy has moved
        // on from is replaced while it can be.
        if ($this->passwords->needsRehash($user->accountPasswordHash())) {
            $this->users->changePassword($user, $this->passwords->hash($password));
        }

        return $this->enter($http, $user);
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
        if (!$this->twoFactor->verify($user->authIdentifier(), $code)) {
            throw new ValidationException(['code' => [self::CODE_REFUSED]]);
        }
        $this->secondFactorThrottle->succeeded($http, $user->authIdentifier());

        $this->sessions->signIn($state, $user);

        return $user;
    }

    public function signOut(Request $http): void
    {
        $this->sessions->signOut(SessionState::of($http));
    }

    public function signOutEverywhere(Request $http): void
    {
        $this->sessions->signOutEverywhere(SessionState::of($http));
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
        $user = $this->connection->durableTransaction(function () use ($token, $hash): AccountUser {
            $link = $this->links->consume($token, EmailLinks::PASSWORD_RESET);
            $user = $link === null ? null : $this->users->find($link->user);
            if ($user === null) {
                throw new ValidationException(['token' => [self::LINK_REFUSED]]);
            }
            $this->users->changePassword($user, $hash);
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());

            return $user;
        });

        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordChanged, userId: $user->authIdentifier()));

        return $this->enter($http, $user);
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
        $this->connection->durableTransaction(function () use ($user, $hash): void {
            $this->users->changePassword($user, $hash);
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
        });

        $this->sessions->signIn(SessionState::of($http), $user);
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordChanged, userId: $user->authIdentifier()));
    }

    // ------------------------------------------------------------ addresses

    /**
     * Asks to move a signed-in account to $email, its password asked first:
     * the link goes to the new address, which must prove it is read before
     * anything changes. Queued the same whether or not the address is
     * another account's, which the job finds out.
     */
    public function requestEmailChange(Request $http, AccountUser $user, string $email, #[\SensitiveParameter] string $password): void
    {
        $email = Emails::normalize($email);
        if ($email === $user->accountEmail()) {
            throw new ValidationException(['email' => [self::SAME_ADDRESS]]);
        }
        $this->confirmPassword($http, $user, $password, 'currentPassword');
        if ($this->linkThrottle->hit($http, $email)) {
            $this->jobs->dispatch(new SendAccountMail(AccountMailKind::EmailChange, $email, userId: $user->authIdentifier()));
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
        [$user, $old] = $this->connection->durableTransaction(function () use ($token): array {
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

            return [$this->users->find($user->authIdentifier()) ?? $user, $old];
        });

        $state = SessionState::of($http);
        if ($state->user()?->authIdentifier() === $user->authIdentifier()) {
            $this->sessions->signIn($state, $user);
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
     * app, counted as any second factor is. Not a recovery code: those are
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
        $this->sessions->confirm(SessionState::of($http), $user);
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
        $this->assertRelyingPartyHost($http);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::CONFIRM, $user->authIdentifier());

        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $this->relyingParty->id,
            'userVerification' => 'required',
            'timeout' => PasskeyChallenges::LIFETIME * 1000,
            'allowCredentials' => array_map(
                static fn (Passkey $passkey): array => ['type' => 'public-key', 'id' => Base64Url::encode($passkey->credentialId), 'transports' => $passkey->transports],
                $this->passkeys->of($user->authIdentifier()),
            ),
        ];
    }

    /** Confirms the session with one of the account's own passkeys, verified as a sign-in's is. */
    public function confirmWithPasskey(Request $http, AccountUser $user, string $credential): void
    {
        $this->verifyPasskey($http, $credential, PasskeyChallenges::CONFIRM, $user);
        $this->sessions->confirm(SessionState::of($http), $user);
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
        $this->secondFactorThrottle->hit($http, $user->authIdentifier());
        $codes = $this->twoFactor->confirm($user->authIdentifier(), $code);
        if ($codes === null) {
            throw new ValidationException(['code' => ["That code didn't match. Type the code your authenticator app shows for this account now."]]);
        }
        $this->secondFactorThrottle->succeeded($http, $user->authIdentifier());

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
        $this->confirmPassword($http, $user, $password, 'currentPassword');
        $this->connection->durableTransaction(function () use ($user): void {
            $this->twoFactor->disable($user->authIdentifier());
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());
        });

        $this->sessions->signIn(SessionState::of($http), $user);
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::TwoFactorDisabled, userId: $user->authIdentifier()));
    }

    /**
     * New recovery codes in place of the old, the password asked first.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Request $http, AccountUser $user, #[\SensitiveParameter] string $password): array
    {
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
        $this->assertRelyingPartyHost($http);
        $this->assertRecentlyConfirmed($http);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::REGISTER, $user->authIdentifier());

        return [
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $this->relyingParty->id, 'name' => $this->relyingParty->name],
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
        $this->assertRelyingPartyHost($http);
        $this->assertRecentlyConfirmed($http);
        $challenge = $this->challenges->consume(SessionState::of($http)->id, PasskeyChallenges::REGISTER, $user->authIdentifier());
        if ($challenge === null) {
            throw new ValidationException(['credential' => ['Adding the passkey took too long, or was started in another tab. Try again.']]);
        }
        if ($this->passkeys->count($user->authIdentifier()) >= PasskeyStore::MAX_PER_ACCOUNT) {
            throw new ValidationException(['credential' => [self::TOO_MANY_PASSKEYS]]);
        }

        try {
            $parts = self::credential($credential, ['clientDataJSON', 'attestationObject']);
            $verified = new WebAuthn($this->relyingParty)->verifyRegistration($parts['clientDataJSON'], $parts['attestationObject'], $challenge);
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
        $this->assertRelyingPartyHost($http);
        $challenge = $this->challenges->issue(SessionState::of($http)->id, PasskeyChallenges::AUTHENTICATE);

        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $this->relyingParty->id,
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
        $user = $this->verifyPasskey($http, $credential, PasskeyChallenges::AUTHENTICATE);
        $this->sessions->signIn(SessionState::of($http), $user);

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
        $name = null;
        foreach ($this->passkeys->of($user->authIdentifier()) as $passkey) {
            if ($passkey->id === $id) {
                $name = $passkey->name;
            }
        }
        if ($name === null) {
            return false;
        }
        $removed = $this->connection->durableTransaction(function () use ($user, $id): bool {
            if (!$this->passkeys->remove($user->authIdentifier(), $id)) {
                return false;
            }
            $this->sessions->endEverywhere($user->authIdentifier());
            $this->epochs->bump($user->authIdentifier());
            $this->credentials->bump($user->authIdentifier());

            return true;
        });
        if (!$removed) {
            return false;
        }

        $this->sessions->signIn(SessionState::of($http), $user);
        $this->jobs->dispatch(new SendAccountMail(AccountMailKind::PasskeyRemoved, name: $name, userId: $user->authIdentifier()));

        return true;
    }

    // ------------------------------------------------------------ API tokens

    /**
     * Issues an API token for the account (ADR 0159) -- while the session's
     * confirmation still counts, as adding a passkey asks -- and tells the
     * owner by email, naming it. A token outlives the session that made it
     * and every sign-in, so a session alone would otherwise mint a lasting
     * way in: a thief with a cookie leaving with a credential that "sign out
     * everywhere" is the only thing to end.
     *
     * $tokens is the application's ApiTokens (the Kernel's, from its
     * ApiAccess), which holds the token to its user, epoch, tenant, panel and
     * host, and to the lifetime the application allows.
     *
     * @param list<string|\BackedEnum> $scopes
     * @throws ValidationException on `confirm` (CONFIRM_FIRST) while the session is not confirmed
     * @throws ApiTokenRefused for a choice that will not do, or a caller who may not have one
     */
    public function issueApiToken(Request $http, AccountUser $user, ApiTokens $tokens, string $name, array $scopes, ?CarbonImmutable $expiresAt): IssuedApiToken
    {
        $this->assertRecentlyConfirmed($http);
        $issued = $tokens->issue($name, $scopes, $expiresAt);
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
            // would learn whose it is from whatever came back.
            AccountMailKind::EmailChange => $user === null || $this->users->findByEmail($job->email) !== null
                ? null
                : $this->mails->emailChange($user, $job->email, $this->links->changeEmail($user, $job->email), $minutes(EmailLinks::CHANGE_EMAIL)),
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
    }

    // --------------------------------------------------------------- helpers

    /**
     * A passkey assertion for this session's challenge for $purpose,
     * verified: the credential found by its id and held to the account
     * handle it was made with -- and, to confirm a session, to $owner's
     * account -- the assertion verified for this host with its user
     * verified, and its sign count moved on. Its account.
     */
    private function verifyPasskey(Request $http, string $credential, string $purpose, ?AccountUser $owner = null): AccountUser
    {
        $this->assertRelyingPartyHost($http);
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
            $assertion = new WebAuthn($this->relyingParty)->verifyAssertion(
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
        if ($user === null || !$this->passkeys->used($passkey, $assertion)) {
            throw $refused();
        }
        $this->signInThrottle->succeeded($http, 'passkey:' . Base64Url::encode($parts['rawId']));

        return $user;
    }

    /** A change to how the account signs in -- or a token made for it -- refused unless the session was confirmed in the last five minutes. */
    private function assertRecentlyConfirmed(Request $http): void
    {
        if (!$this->recentlyConfirmed($http)) {
            throw new ValidationException(['confirm' => [self::CONFIRM_FIRST]]);
        }
    }

    /** Signs in, or moves to the second-factor stage where two-factor is on. */
    private function enter(Request $http, AccountUser $user): SignInResult
    {
        $state = SessionState::of($http);
        if ($this->twoFactor->enabled($user->authIdentifier())) {
            $this->sessions->beginSecondFactor($state, $user);

            return SignInResult::SecondFactor;
        }
        $this->sessions->signIn($state, $user);

        return SignInResult::SignedIn;
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
     * verify for another anyway.
     */
    private function assertRelyingPartyHost(Request $http): void
    {
        if (!hash_equals($this->relyingParty->host(), Sessions::realmOf($http))) {
            throw new ValidationException(['credential' => [self::WRONG_HOST]]);
        }
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
