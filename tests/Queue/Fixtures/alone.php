<?php

declare(strict_types=1);

/**
 * The queue's storage, run with nothing of Tinvar's loadable but itself,
 * the database and support (ADR 0077).
 *
 * The layer rule reads names; this is what it cannot read -- a class name
 * built at runtime, a class loaded through config. Every other Tinvar class
 * is refused by the autoloader here, and named on stderr if anything asks
 * for one, so the queue standing on the database alone is held at runtime
 * too, not only in its sources.
 *
 * Run as `php alone.php <repository root> <scratch directory>`; prints one
 * line per step, and exits 0 only if each went as a queue's should.
 */

$root = $argv[1] ?? throw new \LogicException('Give the repository root.');
$scratch = $argv[2] ?? throw new \LogicException('Give a scratch directory.');

$prefixes = [
    'Tinvar\\Queue\\' => "{$root}/packages/framework/src/Queue/",
    'Tinvar\\Orm\\' => "{$root}/packages/database/src/Orm/",
    'Tinvar\\Support\\' => "{$root}/packages/support/src/Support/",
    'Tinvar\\Codec\\' => "{$root}/packages/support/src/Codec/",
    'Tinvar\\Container\\' => "{$root}/packages/support/src/Container/",
];

spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }

    if (str_starts_with($class, 'Tinvar\\')) {
        fwrite(STDERR, "reached {$class}\n");

        exit(3);
    }
});

// What the packages the queue stands on autoload beside their PSR-4 maps.
require "{$root}/packages/support/aliases.php";
require "{$root}/packages/database/aliases.php";
require "{$root}/packages/database/src/Orm/functions.php";

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\DatabaseQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;

$check = static function (string $step, bool $held): void {
    echo ($held ? 'ok' : 'FAILED') . ": {$step}\n";
    if (!$held) {
        exit(1);
    }
};

$connection = PdoConnection::sqlite("{$scratch}/queue.sqlite", create: true);
DatabaseQueue::define(new Schema($connection, Dialect::for($connection)));

/** @var array<string, Queue> $queues */
$queues = ['database' => new DatabaseQueue($connection), 'array' => new ArrayQueue()];

foreach ($queues as $kind => $queue) {
    $queue->push(new PendingJob('one', 'default', 'sealed-one', 100));
    $queue->push(new PendingJob('two', 'default', 'sealed-two', 100));
    $check("{$kind}: two waiting", $queue->size('default') === 2);

    $first = $queue->reserve(['default'], 100, 60);
    $check("{$kind}: the first pushed is reserved first, its attempt counted", $first?->id === 'one' && $first->payload === 'sealed-one' && $first->attempts === 1);
    $check("{$kind}: held until extended", $first !== null && $queue->extend($first, 300));
    $check("{$kind}: released by its holder", $first !== null && $queue->release($first, 100));

    $again = $queue->reserve(['default'], 100, 60);
    $check("{$kind}: taken again, a second attempt", $again?->id === 'one' && $again->attempts === 2);
    $check("{$kind}: no longer the first holder's", $first !== null && !$queue->delete($first));
    $check("{$kind}: failed by the holder", $again !== null && $queue->fail($again, 'it broke', 101));
    $check("{$kind}: kept with why", ($queue->failed()[0] ?? null)?->error === 'it broke');
    $check("{$kind}: put back", $queue->retry('one', 102));

    // A pause or a hand-back (ADR 0078): back with a new payload, its try
    // given back.
    $paused = $queue->reserve(['default'], 103, 60);
    $check("{$kind}: requeued by its holder, with a new payload", $paused !== null && $queue->requeue($paused, 101, 'sealed-two-paused'));
    $resumed = $queue->reserve(['default'], 103, 60);
    $check("{$kind}: taken again on the same attempt, with the new payload", $resumed?->id === $paused?->id && $resumed?->attempts === $paused?->attempts && $resumed?->payload === 'sealed-two-paused');
    $check("{$kind}: requeued with the payload it has", $resumed !== null && $queue->requeue($resumed, 103));

    while (($job = $queue->reserve(['default'], 200, 60)) !== null) {
        $check("{$kind}: {$job->id} done", $queue->delete($job));
    }
    $check("{$kind}: none left", $queue->size('default') === 0 && $queue->failed() === []);
}

$check('nothing of the framework was loaded', array_filter(
    get_declared_classes(),
    static fn (string $class): bool => str_starts_with($class, 'Tinvar\\') && !str_starts_with($class, 'Tinvar\\Queue\\') && !str_starts_with($class, 'Tinvar\\Orm\\') && !str_starts_with($class, 'Tinvar\\Support\\') && !str_starts_with($class, 'Tinvar\\Codec\\') && !str_starts_with($class, 'Tinvar\\Container\\'),
) === []);
