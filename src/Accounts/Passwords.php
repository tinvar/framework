<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * How a password is hashed, when a stored hash is replaced, and the decoy an
 * unknown email is checked against.
 *
 * The decoy is what keeps sign-in from saying which emails have accounts: an
 * unknown email is verified against it, so it takes as long to refuse as a
 * wrong password. That holds only while the decoy costs what the accounts
 * cost. Pinned at one cost, the shop's was two hundred times faster than an
 * account hashed at the default cost -- 0.87 ms against 193 ms -- and the
 * time taken answered the question the one message hides. So the decoy is
 * hashed at the algorithm and cost most of the accounts are at.
 *
 * Long-lived: one per worker, so the decoy is made once.
 */
final class Passwords
{
    /** What a password is hashed with: PHP's default algorithm, at its default cost. */
    public const string ALGORITHM = PASSWORD_DEFAULT;

    /** @var array<string, int> */
    public const array OPTIONS = [];

    /**
     * The shortest password an account may choose. Length is what makes a
     * password hard to guess; rules about which characters it holds mostly
     * make it hard to remember.
     */
    public const int MIN_LENGTH = 12;

    /**
     * The longest: bcrypt reads only the first 72 bytes, but a megabyte of
     * password is still a megabyte to receive, decode and compare.
     */
    public const int MAX_LENGTH = 1024;

    /** How many stored hashes are read to find the cost the accounts are at. */
    public const int SAMPLE = 101;

    private ?string $decoy = null;

    /**
     * @param \Closure(int<1, max>): list<string> $sample up to that many stored hashes
     *        (AccountUsers::passwordHashes())
     * @param (\Closure(string): bool)|null $keep whether a stored hash is kept as it is
     *        though the policy has moved on -- the shop's seeded demo accounts, hashed
     *        cheaply on purpose; none by default
     */
    public function __construct(
        private readonly \Closure $sample,
        private readonly ?\Closure $keep = null,
    ) {}

    public static function for(AccountUsers $users): self
    {
        return new self($users->passwordHashes(...));
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, self::ALGORITHM, self::OPTIONS);
    }

    /**
     * Whether $password is the one $hash was made from -- or, with no hash,
     * checked against the decoy and refused, in the time a real check takes.
     */
    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        $matched = password_verify($password, $hash ?? $this->decoy());

        return $hash !== null && $matched;
    }

    /**
     * Whether a stored hash should be replaced now its password is known: it
     * was made with an algorithm or cost the policy has since moved on from.
     */
    public function needsRehash(string $hash): bool
    {
        if ($this->keep !== null && ($this->keep)($hash) === true) {
            return false;
        }

        return password_needs_rehash($hash, self::ALGORITHM, self::OPTIONS);
    }

    /**
     * The hash an unknown email is verified against: a random password,
     * hashed as most of the accounts are -- the algorithm and cost of the
     * most common hash in a sample of them, or the policy's own with none.
     *
     * Made on the first check a worker serves, whatever its email, so no
     * one kind of attempt pays for making it.
     */
    public function decoy(): string
    {
        if ($this->decoy !== null) {
            return $this->decoy;
        }

        $seen = [];
        foreach (($this->sample)(self::SAMPLE) as $hash) {
            $info = password_get_info($hash);
            if ($info['algo'] === null) {
                continue;
            }
            $key = serialize([$info['algo'], $info['options']]);
            $seen[$key] ??= ['count' => 0, 'algo' => $info['algo'], 'options' => $info['options']];
            $seen[$key]['count']++;
        }
        usort($seen, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $common = $seen[0] ?? ['algo' => self::ALGORITHM, 'options' => self::OPTIONS];

        return $this->decoy = password_hash(bin2hex(random_bytes(16)), $common['algo'], $common['options']);
    }
}
