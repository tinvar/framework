<?php

declare(strict_types=1);

namespace Tinvar\Staging;

use Tinvar\Support\Encrypter;

/**
 * What a Scrub rule is handed beside the value: the row it is in, where it
 * is, and the import's keys -- the pseudonym key, random and never kept,
 * and the two encrypters.
 *
 * @internal StagingImport's
 */
final class ScrubContext
{
    public string $table = '';

    public string $column = '';

    /** @var array<string, mixed> the production row, as read */
    public array $row = [];

    private readonly string $pseudonymKey;

    public function __construct(
        private readonly Encrypter $production,
        private readonly Encrypter $staging,
    ) {
        // One import's: the same value is the same pseudonym throughout it,
        // and no other import, and nobody after this one, can make them.
        $this->pseudonymKey = random_bytes(32);
    }

    /** 64 bits of a keyed hash of $value, as hex: the same value, the same pseudonym, in any column. */
    public function pseudonym(#[\SensitiveParameter] string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->pseudonymKey), 0, 16);
    }

    /** @throws ImportRefused for a value production's key does not open */
    public function reencrypt(#[\SensitiveParameter] string $sealed, ?string $context): string
    {
        $context ??= "{$this->table}.{$this->column}";
        $plaintext = $this->production->decrypt($sealed, $context);
        if ($plaintext === null) {
            throw new ImportRefused(
                "A value in {$this->table}.{$this->column} does not decrypt under production's key for '{$context}'. "
                . 'Nothing was imported.',
            );
        }

        return $this->staging->encrypt($plaintext, $context);
    }
}
