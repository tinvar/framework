<?php

declare(strict_types=1);

namespace Tinvar\Accounts\TwoFactor;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Support\Encrypter;

/**
 * Each account's TOTP secret and recovery codes.
 *
 * The secret has to be read back to check a code, so it cannot be hashed:
 * it is kept encrypted under a key derived from the app key (Encrypter),
 * with the account's id in the context, so a value copied onto another
 * account's row does not decrypt there. It is the account's second factor
 * only once a code from it has been typed (confirm()): until then it is a
 * secret on its way to an authenticator app, which signs nothing in.
 *
 * Recovery codes are random enough to keep as a plain SHA-256, and each is
 * spent once, in one conditional write a race can win only once. So is a
 * TOTP step: the last one used is kept, and a code for it or an earlier
 * one is refused, which takes a code seen over a shoulder or replayed from
 * a log out of use the moment it has signed anyone in (RFC 6238, 5.2).
 */
final readonly class TwoFactorStore
{
    /** Recovery codes made at a time: ten, each good once. */
    public const int RECOVERY_CODES = 10;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        private Connection $connection,
        private Encrypter $encrypter,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param class-string<Model> $userModel */
    public static function define(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        $schema->create('two_factor_secrets', static function (Table $secrets) use ($userModel): void {
            $secrets->foreign($userModel, 'user_id')->primary()->cascadeOnDelete();
            $secrets->text('secret');
            $secrets->timestamp('created_at');
            $secrets->timestamp('confirmed_at')->nullable();
            $secrets->bigInteger('last_used_step')->nullable();
        }, $ifNotExists);

        $schema->create('recovery_codes', static function (Table $codes) use ($userModel): void {
            $codes->foreign($userModel, 'user_id')->cascadeOnDelete();
            $codes->string('code_hash', 64);
            $codes->timestamp('used_at')->nullable();
            $codes->primary(['user_id', 'code_hash']);
        }, $ifNotExists);
    }

    /**
     * A new secret for $user, kept unconfirmed in place of any unconfirmed
     * one before it; a confirmed one is left alone, and this refuses.
     */
    public function begin(string $user): string
    {
        if ($this->enabled($user)) {
            throw new \LogicException('Two-factor sign-in is already on for this account: turn it off before setting it up again.');
        }
        $secret = Totp::generateSecret();
        $sealed = $this->encrypter->encrypt($secret, self::context($user));
        $now = $this->moment();
        $connection = $this->connection;
        $connection->transaction(static function () use ($connection, $user, $sealed, $now): void {
            $connection->execute('DELETE FROM two_factor_secrets WHERE user_id = ? AND confirmed_at IS NULL', [$user]);
            $connection->execute('INSERT INTO two_factor_secrets (user_id, secret, created_at) VALUES (?, ?, ?)', [$user, $sealed, $now]);
        });

        return $secret;
    }

    /** The secret begin() made and no code has confirmed yet; null with none. */
    public function pendingSecret(string $user): ?string
    {
        $row = $this->connection->select('SELECT secret FROM two_factor_secrets WHERE user_id = ? AND confirmed_at IS NULL', [$user])[0] ?? null;

        return $row === null ? null : $this->encrypter->decrypt((string) $row['secret'], self::context($user));
    }

    /**
     * Turns two-factor on with a code from the pending secret, and returns
     * the recovery codes to show once; null when the code is wrong.
     *
     * @return list<string>|null
     */
    public function confirm(string $user, #[\SensitiveParameter] string $code): ?array
    {
        $secret = $this->pendingSecret($user);
        $step = $secret === null ? null : Totp::verify($secret, $code, $this->now());
        if ($step === null) {
            return null;
        }
        $connection = $this->connection;
        $now = $this->moment();
        $confirmed = $connection->transaction(static fn (): int => $connection->execute(
            'UPDATE two_factor_secrets SET confirmed_at = ?, last_used_step = ? WHERE user_id = ? AND confirmed_at IS NULL',
            [$now, $step, $user],
        ));

        return $confirmed === 1 ? $this->regenerateRecoveryCodes($user) : null;
    }

    /** Whether two-factor sign-in is on for $user: a confirmed secret. */
    public function enabled(string $user): bool
    {
        return $this->connection->select('SELECT 1 FROM two_factor_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL', [$user]) !== [];
    }

    /**
     * Whether $code is $user's second factor now: a TOTP code for a step
     * after the last one used, or a recovery code not yet spent -- either
     * spent by this call, in one write only one caller can win.
     */
    public function verify(string $user, #[\SensitiveParameter] string $code): bool
    {
        $row = $this->connection->select(
            'SELECT secret, last_used_step FROM two_factor_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL',
            [$user],
        )[0] ?? null;
        if ($row === null) {
            return false;
        }

        $compact = (string) preg_replace('/\s+/', '', $code);
        // Six digits is a TOTP code; anything else a recovery code, which
        // is ten characters.
        if (preg_match('/^\d{' . Totp::DIGITS . '}\z/', $compact) === 1) {
            $secret = $this->encrypter->decrypt((string) $row['secret'], self::context($user));
            $last = $row['last_used_step'] === null ? null : (int) $row['last_used_step'];
            $step = $secret === null ? null : Totp::verify($secret, $compact, $this->now(), $last);

            return $step !== null && $this->connection->execute(
                'UPDATE two_factor_secrets SET last_used_step = ? WHERE user_id = ? AND (last_used_step IS NULL OR last_used_step < ?)',
                [$step, $user, $step],
            ) === 1;
        }

        return $this->connection->execute(
            'UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$this->moment(), $user, self::hashCode($code)],
        ) === 1;
    }

    /** Turns two-factor sign-in off for $user: the secret and every recovery code forgotten. */
    public function disable(string $user): void
    {
        $connection = $this->connection;
        $connection->transaction(static function () use ($connection, $user): void {
            $connection->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$user]);
            $connection->execute('DELETE FROM two_factor_secrets WHERE user_id = ?', [$user]);
        });
    }

    /**
     * A new set of recovery codes in place of every one before, used or
     * not; the codes themselves, to show once.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(string $user): array
    {
        $codes = [];
        while (\count($codes) < self::RECOVERY_CODES) {
            $codes[self::newCode()] = true;
        }
        $codes = array_keys($codes);
        $connection = $this->connection;
        $connection->transaction(static function () use ($connection, $user, $codes): void {
            $connection->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$user]);
            foreach ($codes as $code) {
                $connection->execute('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)', [$user, self::hashCode($code)]);
            }
        });

        return array_map(strval(...), $codes);
    }

    /** How many of $user's recovery codes are left unspent. */
    public function recoveryCodesLeft(string $user): int
    {
        return (int) ($this->connection->select('SELECT COUNT(*) AS n FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user])[0]['n'] ?? 0);
    }

    /**
     * A recovery code: ten characters of base32, 50 random bits, in two
     * groups a person can copy -- `abcde-23456`. Guessing one is bounded by
     * the throttle a second factor is counted under, as a TOTP code is.
     */
    private static function newCode(): string
    {
        $code = strtolower(substr(Base32::encode(random_bytes(7)), 0, 10));

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /** As typed -- spaces, a missing dash, capitals -- one hash. */
    private static function hashCode(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
    }

    private static function context(string $user): string
    {
        return 'two_factor_secrets.secret:' . $user;
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function moment(): string
    {
        return TimestampStorage::for($this->connection)->format(CarbonImmutable::createFromTimestampUTC($this->now()));
    }
}
