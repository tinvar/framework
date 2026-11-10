<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * Where EmailLinks keeps its records: a table every worker and server
 * shares (DatabaseEmailLinkStore), or memory for a test
 * (ArrayEmailLinkStore).
 *
 * A record is keyed twice: by its id, which the application may show and
 * revoke it by, and by the hash of its token's secret, which is how a link
 * that arrives is found. Neither is the token.
 */
interface EmailLinkStore
{
    /** Keeps a new record, found by $secretHash. */
    public function add(EmailLink $link, string $secretHash): void;

    /** The record a secret's hash names, used or not; null for none. */
    public function find(string $secretHash): ?EmailLink;

    /**
     * Marks the record used, at $now -- once. True only for the one caller
     * that changed it: a record already used, revoked or expired at $now,
     * or of another purpose or tenant, is left as it was and answers false.
     * Atomic: of two callers racing with one link, one gets true.
     */
    public function consume(string $secretHash, string $purpose, string $tenant, int $now): bool;

    /**
     * Marks the record with this id, of $purpose in $tenant, used at $now --
     * once, as consume() does: true only for the one caller that changed
     * it, false for one used, revoked or expired at $now, or none. What an
     * invite accepted by its signed-in addressee uses (ADR 0201).
     */
    public function consumeById(string $id, string $purpose, string $tenant, int $now): bool;

    /**
     * Revokes an unused record of $purpose in $tenant, by id, at $now. True
     * when one was; false for one used, revoked or expired already, or
     * none.
     *
     * $note is added to the record's data in the same conditional write --
     * an invite declined keeps `declined_at` (ADR 0201) -- beside keys it
     * never replaces: EmailLinks refuses a note naming one the data has.
     *
     * @param array<string, string> $note
     */
    public function revoke(string $id, string $purpose, string $tenant, int $now, array $note = []): bool;

    /** The record with this id, of $purpose in $tenant; null for none. */
    public function byId(string $id, string $purpose, string $tenant): ?EmailLink;

    /**
     * Records of $purpose in $tenant still open at $now, newest first, at
     * most $limit -- and of those, only the ones whose data holds each of
     * $data's values under its key, so a list of one panel's invites is
     * that panel's newest, not the tenant's newest narrowed afterwards.
     *
     * @param int<1, max> $limit
     * @param array<string, string> $data
     * @return list<EmailLink>
     */
    public function open(string $purpose, string $tenant, int $now, int $limit, array $data = []): array;

    /**
     * Records of $purpose in every tenant still open at $now, newest first,
     * at most $limit, whose data holds each of $data's values: the invites
     * to an address, wherever they were sent from (ADR 0201). The caller
     * names the address; the store never lists a purpose whole.
     *
     * @param int<1, max> $limit
     * @param non-empty-array<string, string> $data
     * @return list<EmailLink>
     */
    public function openAcrossTenants(string $purpose, int $now, int $limit, array $data): array;

    /**
     * Forgets records that expired before $before, at most $limit of them.
     *
     * @param int<1, max> $limit
     * @return int how many
     */
    public function prune(int $before, int $limit): int;
}
