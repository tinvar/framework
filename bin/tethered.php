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
 *
 *   php tethered.php --kill-after=<seconds> <command> [arguments...]
 *
 * And the command is killed if it is still running that long after a stop
 * this passed on: serve gives FrankenPHP so long to stop and then kills
 * this outright, which passes nothing on -- a server held open past them by
 * a stream it was still sending kept serving the port as an orphan. Given
 * less than serve gives, the command is gone before this is.
 */

$arguments = $_SERVER['argv'] ?? [];
$command = \is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), \is_string(...))) : [];
$killAfter = null;
if (str_starts_with($command[0] ?? '', '--kill-after=')) {
    $seconds = substr(array_shift($command), \strlen('--kill-after='));
    $killAfter = preg_match('/^[1-9]\d{0,5}\z/', $seconds) === 1 ? (int) $seconds : -1;
}
if ($command === [] || $killAfter === -1) {
    fwrite(STDERR, "usage: tethered.php [--kill-after=<seconds>] <command> [arguments...]\n");
    exit(64);
}

$child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($child)) {
    fwrite(STDERR, "Could not start {$command[0]}.\n");
    exit(1);
}
$pid = proc_get_status($child)['pid'];

// When a stop passed on has had its time: null until one is.
$killAt = null;

pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
    pcntl_signal($signal, static function (int $signal) use ($pid, $killAfter, &$killAt): void {
        posix_kill($pid, $signal);
        if ($killAfter !== null) {
            $killAt ??= microtime(true) + $killAfter;
        }
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

    if ($killAt !== null && microtime(true) >= $killAt) {
        posix_kill($pid, SIGKILL);
        $killAt = null;
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
