<?php

declare(strict_types=1);

/**
 * The names the queue's worker, the user lookup and the nonce stores had
 * before the framework was held apart into modules, kept for one release
 * (ADR 0077).
 *
 * @deprecated since 0.4: use the names below. Removed in 0.5.
 *
 * The worker and the sync queue run jobs, so they are the jobs module's,
 * and the queue's storage stands on the database alone; the user lookup
 * and the nonce stores are asked by the cross-host handoff as well as by
 * jobs and webhooks, so they are the core's.
 *
 * As tinvar/database's are, an old name is aliased when something first
 * asks for it, not when this file loads, and is reported once per process
 * as a deprecation. The alias is the class itself: an application's
 * `implements \Tinvar\Jobs\UserLookup` implements the one interface there
 * is, and a `new \Tinvar\Webhooks\DatabaseNonceStore(...)` is the store the
 * handoff checks for durability.
 *
 * It lives beside src/ rather than in it, because it names the old
 * namespaces on purpose: the layer rule reads src/ alone.
 */
$renamed = [
    'tinvar\\queue\\worker' => Tinvar\Jobs\Worker::class,
    'tinvar\\queue\\workeroptions' => Tinvar\Jobs\WorkerOptions::class,
    'tinvar\\queue\\syncqueue' => Tinvar\Jobs\SyncQueue::class,
    'tinvar\\jobs\\userlookup' => Tinvar\Auth\UserLookup::class,
    'tinvar\\webhooks\\noncestore' => Tinvar\Http\NonceStore::class,
    'tinvar\\webhooks\\arraynoncestore' => Tinvar\Http\ArrayNonceStore::class,
    'tinvar\\webhooks\\databasenoncestore' => Tinvar\Http\DatabaseNonceStore::class,
];

// Recorded as the database's are, so whatever reports an old name can say
// what it became (Tinvar\Support\RenamedClasses).
Tinvar\Support\RenamedClasses::record($renamed);

spl_autoload_register(static function (string $class) use ($renamed): void {
    $current = $renamed[strtolower(ltrim($class, '\\'))] ?? null;

    if ($current !== null) {
        @trigger_error("{$class} is deprecated since tinvar/framework 0.4 and is removed in 0.5; use {$current}.", \E_USER_DEPRECATED);
        class_alias($current, $class);
    }
});
