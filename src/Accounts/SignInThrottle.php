<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Throttle;
use Tinvar\Http\ThrottleResult;
use Tinvar\Http\ThrottleStore;

/**
 * Sign-in attempts, counted per account and per address -- and, under a
 * prefix of their own, any other guess at a credential: a two-factor code,
 * a current password asked before a change.
 *
 * `throttle:actions` already limits every action, but it is sized for a
 * person clicking around, and keyed by user where there is one -- and a
 * sign-in has no user yet. Guessing passwords wants its own, tighter
 * budget, on three axes:
 *
 * - failures on one account from one address, so a guesser gets a handful
 *   of tries and is then paused while the account's owner, anywhere else,
 *   is not. Counted per account alone, as this first was, anyone who knew
 *   an email address could keep its owner out with ten wrong passwords;
 * - failures on one account from every address together, much looser, so
 *   a guess spread over many addresses is still bounded. Reaching it does
 *   pause the account for everyone, but it takes that many addresses to
 *   reach -- the trade any limit on guessing makes;
 * - attempts from one address on any account, so one address cannot try
 *   many accounts;
 * - where asked (`dailyCeiling`), failures on one account from every
 *   address together in a day, which the second factor counts (ADR 0203):
 *   a code is six digits, and fifty guesses every fifteen minutes from
 *   someone holding the password came to about 1.4% a day.
 *
 * Only failures count against an account. An attempt is counted BEFORE its
 * password is checked, since concurrent attempts must not all pass a check
 * none of them has counted yet, and succeeded() takes it back. A wrong
 * password and an unknown email are both failures, counted alike, so
 * neither the refusal nor the time it takes says which it was.
 *
 * All three are counted in a store of their own, which nothing but these
 * counts fills -- never APCu, which the shop's other throttles count in
 * under FrankenPHP. APCu clears every entry once it is full, and anyone
 * can fill it: each new address, an IPv6 /64, is new throttle entries.
 * Counted there, enough of them handed every account its budget back, the
 * ceiling across addresses included, as often as they were sent (ADR
 * 0023).
 */
final class SignInThrottle
{
    /** Failures on one account from one address, per window. */
    public const int PER_ACCOUNT = 10;
    public const int ACCOUNT_WINDOW = 900;

    /** Failures on one account from all addresses together, per window. */
    public const int ACCOUNT_CEILING = 100;

    /** Attempts from one address, on any account, per window. */
    public const int PER_ADDRESS = 30;
    public const int ADDRESS_WINDOW = 60;

    /**
     * Failures on one account from all addresses together, per day, where a
     * throttle counts them: the second factor's default (ADR 0203). Twenty
     * guesses at a code a TOTP check takes three of -- the step before, now
     * and the step after -- is about one in 17,000 a day.
     */
    public const int DAILY_CEILING = 20;
    public const int DAY = 86_400;

    /**
     * @param ThrottleStore $store where every sign-in count is kept: one a flood of
     *        other keys cannot clear, so never APCu
     * @param Throttle $throttle names the address a request comes from, as every
     *        other throttle does; nothing is counted through it
     * @param int $perAccount 0 turns the per-account limit off
     * @param int $perAddress 0 turns the per-address limit off
     * @param int $accountCeiling 0 turns the account's ceiling across addresses off
     * @param int $dailyCeiling failures on one account from every address in a day;
     *        0, the default, counts none
     * @param string $prefix what the counts are kept under: `sign-in` for passwords,
     *        another for each other kind of guess, so one never spends another's budget
     */
    public function __construct(
        private readonly ThrottleStore $store,
        private readonly Throttle $throttle,
        private readonly int $perAccount = self::PER_ACCOUNT,
        private readonly int $perAddress = self::PER_ADDRESS,
        private readonly int $accountCeiling = self::ACCOUNT_CEILING,
        private readonly string $prefix = 'sign-in',
        private readonly int $dailyCeiling = 0,
    ) {
        if (preg_match('/^[a-z][a-z-]{0,31}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException("'{$prefix}' is not a throttle prefix: lowercase letters and dashes, as `two-factor`.");
        }
        if ($store instanceof ApcuThrottleStore) {
            throw new \InvalidArgumentException(
                'Sign-in attempts are not counted in APCu: a full APCu clears every entry, and anyone can fill it. '
                . 'Give sign-in a store of its own, such as a SqliteThrottleStore.',
            );
        }
    }

    /**
     * Counts one attempt, as a failure until succeeded() says otherwise, or
     * refuses it with a 429 that says when to retry.
     *
     * @param string $email the account the guess is at: the address typed for a
     *        sign-in, the account's id for a guess made by someone already known
     */
    public function hit(Request $request, string $email): void
    {
        $results = [];
        if ($this->perAddress > 0) {
            $results[] = $this->count($this->prefix . '|ip|' . $this->throttle->addressFor($request), $this->perAddress, self::ADDRESS_WINDOW);
        }

        // Each account budget only once the ones before it allowed the
        // attempt: one refused earlier is never checked, so is no failure.
        $counted = [];
        $budgets = [
            [$this->fromHere($request, $email), $this->perAccount, self::ACCOUNT_WINDOW],
            [$this->account($email), $this->accountCeiling, self::ACCOUNT_WINDOW],
            [$this->accountDaily($email), $this->dailyCeiling, self::DAY],
        ];
        foreach ($budgets as [$key, $limit, $window]) {
            if ($limit <= 0 || ($results !== [] && !Throttle::strictest($results)->allowed)) {
                continue;
            }
            $results[] = $this->count($key, $limit, $window);
            $counted[] = [$key, $window];
        }

        if ($results === []) {
            return;
        }

        $verdict = Throttle::strictest($results);
        if (!$verdict->allowed) {
            // Refused before its password was checked, so taken back: an
            // account's budgets count guesses that were tried, and nothing
            // else.
            foreach ($counted as [$key, $window]) {
                $this->store->release($key, $window);
            }

            throw new HttpException(429, 'Too many sign-in attempts.', 'too_many_attempts', $verdict->headers());
        }
    }

    /**
     * The attempt hit() counted was right: this address's failures on the
     * account are cleared, and the attempt is taken back from the ceiling.
     */
    public function succeeded(Request $request, string $email): void
    {
        if ($this->perAccount > 0) {
            $this->store->release($this->fromHere($request, $email), self::ACCOUNT_WINDOW, PHP_INT_MAX);
        }

        if ($this->accountCeiling > 0) {
            $this->store->release($this->account($email), self::ACCOUNT_WINDOW);
        }

        if ($this->dailyCeiling > 0) {
            $this->store->release($this->accountDaily($email), self::DAY);
        }
    }

    /**
     * Counts one failed guess against $scope -- one sign-in waiting on its
     * second factor, named by its session (ADR 0203) -- and says how many it
     * has had in $window: what the caller ends the scope at. Kept in the
     * same store as every other count here, hashed, so the scope's name is
     * not listed in it.
     */
    public function strike(#[\SensitiveParameter] string $scope, int $window): int
    {
        return $this->store->increment($this->prefix . '|scope|' . hash('sha256', $scope), $window)[0];
    }

    private function count(string $key, int $limit, int $window): ThrottleResult
    {
        [$used, $resetsAt] = $this->store->increment($key, $window);

        return new ThrottleResult(
            allowed: $used <= $limit,
            limit: $limit,
            remaining: max(0, $limit - $used),
            resetsAt: $resetsAt,
        );
    }

    /**
     * One account from the address this request came from, named as the
     * throttle names an address: an IPv6 caller by its /64, since one
     * subscriber is handed a whole one.
     */
    private function fromHere(Request $request, string $email): string
    {
        return $this->prefix . '|account-address|' . hash('sha256', self::folded($email) . "\0" . $this->throttle->addressFor($request));
    }

    /**
     * Hashed and folded: the key is the account, not how it was typed, and a
     * store's keys are not somewhere addresses should be listed.
     */
    private function account(string $email): string
    {
        return $this->prefix . '|account|' . hash('sha256', self::folded($email));
    }

    /** As account(), counted over a day. */
    private function accountDaily(string $email): string
    {
        return $this->prefix . '|account-day|' . hash('sha256', self::folded($email));
    }

    private static function folded(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
