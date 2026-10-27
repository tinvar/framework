<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * Where a tag's version is kept on the server, for what is believed until
 * the tag is invalidated: a once-shared value's hold (ADR 0074).
 *
 * A hold carries the versions its key's tags had when it was minted, and
 * is believed only while they are the same. Bumping a tag -- an action
 * invalidating it, or a job that ships an order and says so -- moves its
 * version, so every hold minted before stops being believed, in every
 * browser, whether or not any response to that browser said so.
 *
 * Tags are as TagNamespace files them: one tenant's tag is never
 * another's. A version is a number nothing can guess or count towards: a
 * bump draws a fresh one at random rather than adding one, so a tag whose
 * record was lost and made again never comes back to a version a hold was
 * minted at. A tag never bumped is 0.
 *
 * A store that cannot answer throws. What reads it fails closed: a hold
 * whose versions cannot be read is not believed, and a bump that cannot be
 * made fails the write that asked for it.
 */
interface TagVersions
{
    /**
     * @param list<string> $tags as TagNamespace files them
     * @return array<string, int> each tag's version; 0 for one never bumped
     * @throws \Throwable when the store cannot say
     */
    public function versions(array $tags): array;

    /**
     * Gives each tag a new version.
     *
     * @param list<string> $tags as TagNamespace files them
     * @throws \Throwable when the store could not keep it
     */
    public function bump(array $tags): void;
}
