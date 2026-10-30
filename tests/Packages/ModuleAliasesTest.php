<?php

declare(strict_types=1);

/**
 * The classes holding the framework apart into modules moved (ADR 0077):
 * the worker and the sync queue into jobs, the user lookup and the nonce
 * stores into the core; and holding the core apart into layers moved the
 * file types into storage and the head tag into the protocol (ADR 0114).
 * Each old name is the moved class itself, for one
 * release, with a deprecation, and is recorded so a refusal can say what
 * it became.
 */

namespace Tinvar\Tests\Packages\ModuleAliases;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\DatabaseNonceStore;
use Tinvar\Http\NonceStore;
use Tinvar\Jobs\SyncQueue;
use Tinvar\Jobs\Worker;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Protocol\HeadTag;
use Tinvar\Storage\FileTypes;
use Tinvar\Support\RenamedClasses;

/** Whether $old names a class or interface, its deprecation heard and set aside. */
function aliased(string $old): bool
{
    set_error_handler(static fn (int $level): bool => $level === \E_USER_DEPRECATED);

    try {
        return class_exists($old) || interface_exists($old);
    } finally {
        restore_error_handler();
    }
}

it('makes each old name the class it became', function (string $old, string $current) {
    expect(aliased($old))->toBeTrue()
        ->and((new \ReflectionClass($old))->getName())->toBe($current)
        ->and(RenamedClasses::current($old))->toBe($current);
})->with([
    'the worker' => ['Tinvar\Queue\Worker', Worker::class],
    'its options' => ['Tinvar\Queue\WorkerOptions', WorkerOptions::class],
    'the sync queue' => ['Tinvar\Queue\SyncQueue', SyncQueue::class],
    'the user lookup' => ['Tinvar\Jobs\UserLookup', UserLookup::class],
    'the nonce store' => ['Tinvar\Webhooks\NonceStore', NonceStore::class],
    'its array store' => ['Tinvar\Webhooks\ArrayNonceStore', ArrayNonceStore::class],
    'its database store' => ['Tinvar\Webhooks\DatabaseNonceStore', DatabaseNonceStore::class],
    'the file types' => ['Tinvar\Uploads\FileTypes', FileTypes::class],
    'a head tag' => ['Tinvar\Render\HeadTag', HeadTag::class],
]);

it('takes an application\'s lookup written against the old name as the one interface there is', function () {
    $lookup = new class implements \Tinvar\Jobs\UserLookup {
        public function find(string $id): ?Authenticatable
        {
            return null;
        }

        public function epoch(Authenticatable $user): string
        {
            return '1';
        }
    };

    expect($lookup)->toBeInstanceOf(UserLookup::class);
});

// In a process of its own: an alias is made, and reported, once.
it('reports an old name as deprecated once, naming what it became', function () {
    $script = <<<'PHP'
        require $argv[1];
        set_error_handler(static function (int $level, string $message): bool {
            echo $level === E_USER_DEPRECATED ? "deprecated: {$message}\n" : "other: {$message}\n";

            return true;
        });
        var_dump(class_exists('Tinvar\Webhooks\ArrayNonceStore'), class_exists('Tinvar\Webhooks\ArrayNonceStore'));
        PHP;

    exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' ' . escapeshellarg(\dirname(__DIR__, 4) . '/vendor/autoload.php') . ' 2>&1', $output, $status);

    expect($status)->toBe(0)
        ->and($output)->toBe([
            'deprecated: Tinvar\Webhooks\ArrayNonceStore is deprecated since tinvar/framework 0.4 and is removed in 0.5; use Tinvar\Http\ArrayNonceStore.',
            'bool(true)',
            'bool(true)',
        ]);
});
