<?php

declare(strict_types=1);

/**
 * Runs a command for as long as standard input stays open.
 *
 *   php tethered.php <command> [arguments...]
 *
 * For a child of `tinvar serve` that watches nothing of its parent's --
 * FrankenPHP. The command holds the other end of this process's standard
 * input and never writes to it. Killed outright, it can forward no signal,
 * and FrankenPHP kept serving the port as an orphan; but the system closes
 * the pipe whatever killed it, and then this stops the command: SIGTERM, and
 * SIGKILL if it has not gone in ten seconds.
 *
 * SIGINT, SIGTERM and SIGHUP are passed on, and this exits as the command
 * did, so to whoever started it this is the command.
 */

$arguments = $_SERVER['argv'] ?? [];
$command = \is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), \is_string(...))) : [];
if ($command === []) {
    fwrite(STDERR, "usage: tethered.php <command> [arguments...]\n");
    exit(64);
}

$child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($child)) {
    fwrite(STDERR, "Could not start {$command[0]}.\n");
    exit(1);
}
$pid = proc_get_status($child)['pid'];

pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
    pcntl_signal($signal, static function (int $signal) use ($pid): void {
        posix_kill($pid, $signal);
    });
}

stream_set_blocking(STDIN, false);

for (;;) {
    // Read once: the exit status is reported by the first look after the
    // command has gone, and not again.
    $status = proc_get_status($child);
    if (!$status['running']) {
        exit($status['signaled'] ? 128 + $status['termsig'] : $status['exitcode']);
    }

    // A signal interrupts the wait, which is fine: the loop looks again.
    $read = [STDIN];
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 250_000) !== 1 || fread(STDIN, 8192) !== '' || !feof(STDIN)) {
        continue;
    }

    // Standard input has closed: whoever started this is gone.
    posix_kill($pid, SIGTERM);
    $deadline = microtime(true) + 10;
    while (proc_get_status($child)['running'] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    if (proc_get_status($child)['running']) {
        posix_kill($pid, SIGKILL);
    }

    exit(0);
}
