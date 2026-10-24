<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Where an application keeps its BuildHistory: what `tinvar build:revoke`,
 * `build:floor` and `build:current` write, and every worker reads.
 *
 * The framework defines it and the application chooses it, as with
 * ThrottleStore and FlashStore: a single server is served well by a file
 * (FileBuildStore), several behind a load balancer need something they all
 * read -- a shared volume, a database table -- and only the application knows
 * which it has.
 *
 * read() is called on every request that checks a build, so an
 * implementation must make it cheap: cache what it read, and look again only
 * when the stored history may have changed. It must return the SAME object
 * while nothing has, which is how BuildRegistry knows it has nothing to
 * rebuild.
 */
interface BuildStore
{
    /**
     * The history as last written; an empty one if nothing ever was.
     *
     * @throws \RuntimeException when what is stored cannot be read as a history
     */
    public function read(): BuildHistory;

    /**
     * Applies a change to the stored history and returns the result.
     *
     * Read, changed and written under one lock, so two operators' commands
     * at once cannot lose one of their changes -- a revocation lost that way
     * is a revocation that never happened.
     *
     * @param \Closure(BuildHistory): BuildHistory $change
     * @throws \RuntimeException when the history cannot be read or written
     */
    public function update(\Closure $change): BuildHistory;
}
