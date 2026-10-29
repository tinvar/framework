<?php

declare(strict_types=1);

/*
 * A worker of a parallel run, for SharedRendererPoolTest, whose run's pool
 * record names a pid that is not the pool's: it writes $pid where the pool's
 * supervisor would be, then stops the pool, and says what it found.
 */

require \dirname(__DIR__, 5) . '/vendor/autoload.php';

[, $entry, $root, $pid] = $argv;
$pool = Tinvar\Testing\RendererPool::for($entry, $root);
file_put_contents("{$pool->path}/supervisor.pid", $pid);

echo json_encode(['shared' => $pool->shared(), 'running' => $pool->running(), 'path' => $pool->path, 'run' => posix_getppid()]);
$pool->stop();
