<?php

declare(strict_types=1);

/**
 * The renderer run as a user of its own (ADR 0028), end to end: a real pool
 * started with --socket-group, and PHP rendering through it held to the
 * renderers' user.
 *
 * What runs anywhere: the pool shares each socket (0660) and its directory
 * (0750) with the group and with no one else, PHP renders through it once
 * the process answering is the renderers' user, and refuses -- with nothing
 * sent -- when it is anyone else, or when the platform gives PHP no way to
 * ask. Where PHP cannot ask the kernel (macOS), the kernel's answer is stood
 * in for in the first, and the refusal is the real one.
 *
 * What needs root, on Linux, and is skipped otherwise: the pool started as
 * `bin` in a group of its own, and PHP as `daemon` rendering through it
 * -- a real second user, the kernel's real answer, and a user outside the
 * group refused by the file system. The manual procedure in
 * docs/deployment.md does the same by hand.
 */

namespace Tinvar\Tests\Integration\SeparateRendererUser;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\PeerCredentials;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Render\RendererHealth;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\SocketTrust;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

$root = \dirname(__DIR__, 4);

/** An app that says which process rendered it. */
const APP = <<<'JS'
    const once = (text) => new ReadableStream({ start(c) { c.enqueue(new TextEncoder().encode(text)); c.close(); } });
    export async function renderHtml() {
      return { htmlStream: once('<p>pid ' + process.pid + '</p>'), rscStream: once('0:"row"\n') };
    }
    export function renderRsc() { return once('0:"row"\n'); }
    JS;

/** A scratch directory with the app in it, readable by everyone, as a build is. */
function scratch(): string
{
    $dir = '/tmp/tvsu-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/app/dist/rsc", 0755, true);
    foreach ([$dir, "{$dir}/app", "{$dir}/app/dist", "{$dir}/app/dist/rsc"] as $each) {
        chmod($each, 0755);
    }
    file_put_contents("{$dir}/app/dist/rsc/index.js", APP);
    chmod("{$dir}/app/dist/rsc/index.js", 0644);

    return $dir;
}

/** A group this process is in, other than its own where it has one. */
function sharedGroup(): int
{
    foreach (posix_getgroups() ?: [] as $gid) {
        if ($gid !== posix_getegid() && $gid !== 0) {
            return $gid;
        }
    }

    return posix_getegid();
}

/** @return list<string> */
function socketsIn(string $pool): array
{
    return array_values(array_map(
        static fn (string $name): string => "{$pool}/{$name}",
        array_filter(is_dir($pool) ? scandir($pool) ?: [] : [], static fn (string $name): bool => str_ends_with($name, '.sock')),
    ));
}

/**
 * The pool, started as renderer:start starts it, sharing its sockets with
 * $group; resolves once $processes sockets are there.
 *
 * @return resource
 */
function startPool(string $root, string $pool, string $entry, int $group, int $processes = 2)
{
    $process = proc_open(
        ['node', "{$root}/packages/renderer/bin/renderer.js", $pool, $entry, "--processes={$processes}", "--socket-group={$group}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', \dirname($pool) . '/pool.log', 'a']],
        $pipes,
        $root,
        ['NODE_ENV' => 'production', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
    );
    \assert(\is_resource($process));

    // Until every renderer has finished making its socket: each appears at
    // 0600 and is given to the group only after, so a socket counted the
    // moment it appears can still be read at 0600 under load.
    $settled = static function () use ($pool, $processes): bool {
        $sockets = socketsIn($pool);
        foreach ($sockets as $socket) {
            clearstatcache(true, $socket);
            if ((@fileperms($socket) & 0777) !== 0660) {
                return false;
            }
        }

        return \count($sockets) >= $processes;
    };

    $deadline = microtime(true) + 20;
    while (!$settled()) {
        if (!proc_get_status($process)['running'] || microtime(true) > $deadline) {
            $log = (string) @file_get_contents(\dirname($pool) . '/pool.log');
            proc_terminate($process, SIGKILL);
            proc_close($process);

            throw new \RuntimeException("The pool did not start:\n{$log}");
        }
        usleep(50_000);
    }

    return $process;
}

/** @param resource $process */
function stopPool($process): void
{
    proc_terminate($process, SIGTERM);
    proc_close($process);
}

/** @param list<int> $owners */
function channel(string $pool, array $owners, ?PeerCredentials $peers = null): RenderChannel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return new RenderChannel(
        $pool,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 5.0,
        trustedSocketOwners: $owners,
        peerCredentials: $peers,
    );
}

function renderOnce(RenderChannel $channel): string
{
    $html = '';
    $channel->render(['t' => 'render'], [], static function (string $bytes) use (&$html): void {
        $html .= $bytes;
    });

    return $html;
}

/**
 * The kernel's answer where PHP can ask it (Linux), and otherwise stood in
 * for: the process answering is this user's, as it is.
 */
function peersOfThisUser(): PeerCredentials
{
    if (PHP_OS_FAMILY === 'Linux') {
        return new PeerCredentials();
    }
    $uid = posix_geteuid();

    return new PeerCredentials('Linux', static fn (\Socket $socket): int => $uid);
}

/** Renders served, by every renderer in the pool, as each says. */
function served(string $pool): int
{
    $total = 0;
    foreach (new RendererHealth()->checkAll($pool) as $answer) {
        \assert(\is_array($answer));
        $total += $answer['served'];
    }

    return $total;
}

describe('a pool sharing its sockets with a group', function () use ($root) {
    it('opens each socket to the group alone, and PHP renders through it once the renderers\' user answers', function () use ($root) {
        $dir = scratch();
        $pool = "{$dir}/pool";
        $group = sharedGroup();
        $process = startPool($root, $pool, "{$dir}/app/dist/rsc/index.js", $group);

        try {
            foreach (socketsIn($pool) as $socket) {
                expect(fileperms($socket) & 0777)->toBe(0660)
                    ->and(filegroup($socket))->toBe($group);
            }
            expect(fileperms($pool) & 0777)->toBe(0750)
                ->and(filegroup($pool))->toBe($group);

            // Held to the renderers' user -- here, the one this test runs as.
            $html = renderOnce(channel($pool, [posix_geteuid()], peersOfThisUser()));
        } finally {
            stopPool($process);
            exec('rm -rf ' . escapeshellarg($dir));
        }

        expect($html)->toMatch('#<p>pid \d+</p>#');
    });

    // Fail closed: the renderers are said to run as another user, and they
    // run as this one -- or PHP cannot ask who they run as. Either way the
    // render is refused before its frame is sent.
    it('refuses to render, and sends nothing, when the process answering is not the renderers\' user', function () use ($root) {
        $dir = scratch();
        $pool = "{$dir}/pool";
        $process = startPool($root, $pool, "{$dir}/app/dist/rsc/index.js", sharedGroup());
        $stranger = 4_000_000_001;
        while (posix_getpwuid($stranger) !== false) {
            $stranger++;
        }

        try {
            expect(fn () => renderOnce(channel($pool, [$stranger])))
                ->toThrow(function (RendererException $e) use ($stranger): void {
                    expect($e->failure())->toBe(RenderFailure::RendererUntrusted)
                        ->and($e->getMessage())->toContain(PHP_OS_FAMILY === 'Linux'
                            ? "not the renderer's own user (uid {$stranger})"
                            : 'that cannot be verified: PHP cannot read a Unix socket peer\'s user on ' . PHP_OS_FAMILY);
                });
            $served = served($pool);
            // And a probe is held to the same.
            $probe = new RendererHealth(trust: new SocketTrust([$stranger]))->checkAll($pool);
        } finally {
            stopPool($process);
            exec('rm -rf ' . escapeshellarg($dir));
        }

        expect($served)->toBe(0)
            ->and($probe)->each->toContain('Refusing the renderer at');
    });
})->skip(fn () => !\function_exists('posix_geteuid') || posix_geteuid() === 0 || !is_file($root . '/node_modules/@msgpack/msgpack/package.json'), 'needs npm ci, POSIX ownership, and not root');

/**
 * Runs $body as $user, in $groups, in a child process, and returns what it
 * returned, or the message of what it threw.
 *
 * @param list<int> $groups supplementary groups beside the user's own
 */
function asUser(string $user, array $groups, \Closure $body): string
{
    $entry = posix_getpwnam($user);
    \assert($entry !== false);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    \assert($pair !== false);

    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        try {
            // Groups first, while still root: initgroups() takes the user's
            // own from the group database and adds the one given.
            posix_initgroups($entry['name'], $groups[0] ?? $entry['gid']);
            posix_setgid($entry['gid']);
            posix_setuid($entry['uid']);
            $result = (string) $body();
        } catch (\Throwable $e) {
            $result = 'threw: ' . $e->getMessage();
        }
        fwrite($pair[1], $result);
        fclose($pair[1]);
        // Gone without running destructors or the test runner's shutdown.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }

    fclose($pair[1]);
    $result = (string) stream_get_contents($pair[0]);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);

    return $result;
}

describe('a pool run as another user', function () use ($root) {
    it('is rendered through by PHP as a user in its group, held to the renderers\' user by the kernel', function () use ($root) {
        $renderer = posix_getpwnam('bin');
        $php = posix_getpwnam('daemon');
        \assert($renderer !== false && $php !== false);
        // A group no account has: as root, a process may be put in any gid.
        $group = 47_391;
        $dir = scratch();
        $pool = "{$dir}/pool";
        // As systemd's RuntimeDirectory= makes it: the renderers' user's, 0700.
        mkdir($pool, 0700);
        chown($pool, $renderer['uid']);
        // Made here, in a directory only root may write, for the pool to append to.
        touch("{$dir}/pool.log");
        chown("{$dir}/pool.log", $renderer['uid']);
        // PHP's user's own, for a renderer of PHP's user to listen in.
        mkdir("{$dir}/php", 0700);
        chown("{$dir}/php", $php['uid']);

        // The checkout must be readable by the renderers' user for this to run
        // at all -- a deployment's permissions, which a checkout under /root
        // does not have.
        $readable = asUser('bin', [], static fn (): string => is_readable("{$root}/packages/renderer/bin/renderer.js")
            && is_readable("{$root}/node_modules/@msgpack/msgpack/package.json") ? 'yes' : 'no');
        if ($readable !== 'yes') {
            exec('rm -rf ' . escapeshellarg($dir));
            test()->markTestSkipped("the checkout at {$root} is not readable by bin");
        }

        $node = trim((string) shell_exec('command -v node'));
        $supervisor = pcntl_fork();
        if ($supervisor === 0) {
            posix_initgroups('bin', $group);
            posix_setgid($group);
            posix_setuid($renderer['uid']);
            pcntl_exec('/bin/sh', [
                '-c',
                'exec "$0" "$@" >>"$LOG" 2>&1',
                $node,
                "{$root}/packages/renderer/bin/renderer.js",
                $pool,
                "{$dir}/app/dist/rsc/index.js",
                '--processes=2',
                "--socket-group={$group}",
            ], ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'NODE_ENV' => 'production', 'LOG' => "{$dir}/pool.log"]);
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }

        try {
            $deadline = microtime(true) + 20;
            while (\count(socketsIn($pool)) < 2 && microtime(true) < $deadline) {
                usleep(50_000);
            }
            $sockets = array_map(
                static fn (string $socket): array => [fileowner($socket), filegroup($socket), fileperms($socket) & 0777],
                socketsIn($pool),
            );
            $directory = [fileowner($pool), filegroup($pool), fileperms($pool) & 0777];

            $render = static function () use ($pool, $renderer): string {
                return renderOnce(channel($pool, [$renderer['uid']]));
            };
            $inGroup = asUser('daemon', [$group], $render);
            $outsideGroup = asUser('daemon', [], $render);
            // Held to another user, the pool's directory is that user's no longer.
            $heldToAnother = asUser('daemon', [$group], static fn (): string => renderOnce(channel($pool, [$php['uid'] + 100_000])));
            // A renderer of PHP's own user, where the files pass every check:
            // the kernel says who answers, and it is not the renderers' user.
            $wrongPeer = asUser('daemon', [$group], static function () use ($dir, $renderer): string {
                $server = stream_socket_server("unix://{$dir}/php/r.sock");
                \assert($server !== false);
                chmod("{$dir}/php/r.sock", 0600);
                // It answers the hello as a renderer would: the kernel, not
                // the answer, says whose it is.
                $answerer = pcntl_fork();
                if ($answerer === 0) {
                    $connection = stream_socket_accept($server, 5);
                    if ($connection !== false) {
                        fwrite($connection, new MessagePackCodec()->encodeFrame(['t' => 'ready']));
                        fread($connection, 1);
                    }
                    posix_kill(getmypid(), SIGKILL);
                    exit(0);
                }

                try {
                    return renderOnce(channel("{$dir}/php/r.sock", [$renderer['uid']]));
                } finally {
                    posix_kill($answerer, SIGKILL);
                    pcntl_waitpid($answerer, $status);
                }
            });
            // The kernel's own answer, read the way a render reads it.
            $peer = asUser('daemon', [$group], static function () use ($pool): string {
                $socket = stream_socket_client('unix://' . socketsIn($pool)[0]);
                \assert($socket !== false);

                return (string) new PeerCredentials()->uid($socket, new MessagePackCodec(), 2.0);
            });
            // Open to every user, a socket is refused however it is answered.
            foreach (socketsIn($pool) as $socket) {
                chmod($socket, 0666);
            }
            $openToAll = asUser('daemon', [$group], $render);
        } finally {
            posix_kill($supervisor, SIGTERM);
            pcntl_waitpid($supervisor, $status);
            $log = (string) @file_get_contents("{$dir}/pool.log");
            exec('rm -rf ' . escapeshellarg($dir));
        }

        expect($sockets)->toBe([[$renderer['uid'], $group, 0660], [$renderer['uid'], $group, 0660]], $log)
            ->and($directory)->toBe([$renderer['uid'], $group, 0750])
            ->and($inGroup)->toMatch('#<p>pid \d+</p>#')
            // Not in the group, the directory is closed to it: there are no
            // renderers to be found.
            ->and($outsideGroup)->toStartWith('threw: Cannot reach the renderer at')
            ->and($heldToAnother)->toContain('the pool directory belongs to another user')
            ->and($wrongPeer)->toStartWith("threw: Refusing the renderer at {$dir}/php/r.sock")
            ->and($wrongPeer)->toContain("it is answered by a process of uid {$php['uid']}, not the renderer's own user (uid {$renderer['uid']})")
            ->and($peer)->toBe((string) $renderer['uid'])
            ->and($openToAll)->toContain('it is mode 666, open to every user');
    });

    // ADR 0130: who answers is read from the kernel's credentials on the
    // answer, in PHP's user namespace and no pid namespace, so the two
    // sides need not share one -- as two containers of a pod need not.
    it('is held to the renderers\' user across pid namespaces kept apart', function () use ($root) {
        $renderer = posix_getpwnam('bin');
        $php = posix_getpwnam('daemon');
        \assert($renderer !== false && $php !== false);
        foreach (['unshare', 'setpriv'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                test()->markTestSkipped("needs {$tool} (util-linux) to start each side in a pid namespace of its own");
            }
        }
        $group = 47_392;
        $dir = scratch();
        $pool = "{$dir}/pool";
        mkdir($pool, 0700);
        chown($pool, $renderer['uid']);
        touch("{$dir}/pool.log");
        chown("{$dir}/pool.log", $renderer['uid']);
        $node = trim((string) shell_exec('command -v node'));

        // Each side in a pid namespace of its own, under the test's: neither
        // can see the other's processes.
        $pidNamespace = ['unshare', '--pid', '--fork', '--mount-proc', '--kill-child=SIGTERM', 'setpriv'];
        $process = proc_open(
            [...$pidNamespace, "--reuid={$renderer['uid']}", "--regid={$group}", '--clear-groups', $node,
                "{$root}/packages/renderer/bin/renderer.js", $pool, "{$dir}/app/dist/rsc/index.js", '--processes=2', "--socket-group={$group}"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/pool.log", 'a'], 2 => ['file', "{$dir}/pool.log", 'a']],
            $pipes,
            $root,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'NODE_ENV' => 'production'],
        );
        \assert(\is_resource($process));

        $check = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $seen = 0;
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
                $seen += str_contains((string) @file_get_contents($cmdline), 'renderer' . '.js') ? 1 : 0;
            }
            $answers = new Tinvar\Render\RendererHealth(trust: new Tinvar\Render\SocketTrust([(int) $argv[3]]))->checkAll($argv[2]);
            echo json_encode(['seen' => $seen, 'answers' => $answers]);
            PHP;

        try {
            $deadline = microtime(true) + 20;
            while (\count(socketsIn($pool)) < 2 && microtime(true) < $deadline) {
                usleep(50_000);
            }
            usleep(200_000);
            $out = (string) shell_exec(implode(' ', array_map(escapeshellarg(...), [
                ...$pidNamespace, "--reuid={$php['uid']}", "--regid={$php['gid']}", "--groups={$group}",
                PHP_BINARY, '-r', $check, $root, $pool, (string) $renderer['uid'],
            ])) . ' 2>&1');
        } finally {
            // unshare ignores SIGTERM while it waits; killed, it hands its child SIGTERM.
            proc_terminate($process, SIGKILL);
            proc_close($process);
            $log = (string) @file_get_contents("{$dir}/pool.log");
            exec('rm -rf ' . escapeshellarg($dir));
        }

        /** @var array{seen: int, answers: array<string, array<string, int>|string>}|null $read */
        $read = json_decode($out, true);
        expect($read)->toBeArray("{$out}\n{$log}")
            ->and($read['seen'] ?? null)->toBe(0)
            ->and($read['answers'] ?? [])->toHaveCount(2)
            ->and($read['answers'] ?? [])->each->toBeArray();
    });
})->skip(
    fn () => PHP_OS_FAMILY !== 'Linux' || !\function_exists('posix_geteuid') || posix_geteuid() !== 0 || posix_getpwnam('bin') === false || posix_getpwnam('daemon') === false,
    'needs root on Linux, and the users bin and daemon: run as root to start renderers as another user',
);
