<?php

declare(strict_types=1);

/*
 * One "worker" of a parallel run, for SharedRendererPoolTest: started by the
 * test with PARATEST and TEST_TOKEN set, as paratest starts each, so its
 * parent -- the test -- is its run. Starts the pool it is handed, and says
 * what it found: the directory, whether it is the run's, and who serves it.
 */

require \dirname(__DIR__, 5) . '/vendor/autoload.php';

[, $entry, $root] = $argv;
$pool = Tinvar\Testing\RendererPool::for($entry, $root);
$pool->start();

$supervisor = @file_get_contents("{$pool->path}/supervisor.pid");
echo json_encode([
    'path' => $pool->path,
    'shared' => $pool->shared(),
    'run' => posix_getppid(),
    'supervisor' => $supervisor === false ? null : (int) $supervisor,
    'sockets' => count(glob("{$pool->path}/*.sock") ?: []),
]);
