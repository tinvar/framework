<?php

declare(strict_types=1);

/**
 * The renderer run as a user of its own (ADR 0028), PHP's half, without a
 * second user: how the user is named (RendererUser), how the process at the
 * other end of a connection is told (PeerCredentials), and what a socket is
 * held to once a user is named (SocketTrust) -- every refusal a refusal of
 * the render, never a fallback to rendering anyway.
 *
 * Running a renderer as another user for real needs root; that is
 * Integration/SeparateRendererUserTest.
 */

namespace Tinvar\Tests\Render\SeparateRendererUser;

use Tinvar\Render\PeerCredentials;
use Tinvar\Render\RendererException;
use Tinvar\Render\RendererUser;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\SocketTrust;

/** A uid no account here has, for a renderer's user that is not this one. */
function strangerUid(): int
{
    for ($uid = 4_000_000_001; ; $uid++) {
        if (posix_getpwuid($uid) === false) {
            return $uid;
        }
    }
}

/** A procfs of one process, $pid, whose status says these uids: real, effective, saved, filesystem. */
function fakeProc(int $pid, string $uids): string
{
    $proc = '/tmp/tvproc-' . bin2hex(random_bytes(4));
    mkdir("{$proc}/{$pid}", 0700, true);
    file_put_contents("{$proc}/{$pid}/status", "Name:\tnode\nPid:\t{$pid}\nUid:\t{$uids}\nGid:\t33\t33\t33\t33\n");

    return $proc;
}

function removeProc(string $proc): void
{
    exec('rm -rf ' . escapeshellarg($proc));
}

/**
 * Linux's answer, on any platform: the kernel's pid stood in for, and the
 * user read from a procfs the test wrote.
 */
function linuxPeers(string $proc, int|false $pid = 4242): PeerCredentials
{
    return new PeerCredentials('Linux', 'x86_64', $proc, static fn ($stream): int|false => $pid);
}

/** @param array<string, string|null> $env */
function withEnvironment(array $env, \Closure $body): mixed
{
    $saved = [];
    foreach ($env as $name => $value) {
        $saved[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        return $body();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

/** @return resource a connected pair's end, which no test here reads from */
function aStream()
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    \assert($pair !== false);

    return $pair[0];
}

describe('naming the renderers\' user', function () {
    it('is nobody when TINVAR_RENDERER_USER is not set', function () {
        withEnvironment([RendererUser::ENV => null], function (): void {
            expect(RendererUser::fromEnvironment())->toBeNull()
                ->and(RendererUser::socketOwners())->toBe([]);
        });
    });

    it('is a user by name, or by a uid an account need not have', function () {
        $nobody = posix_getpwnam('nobody');
        \assert($nobody !== false);
        $stranger = strangerUid();

        expect(RendererUser::named('nobody')->uid)->toBe($nobody['uid'])
            ->and(RendererUser::named('nobody')->name)->toBe('nobody')
            ->and(RendererUser::named((string) $stranger)->uid)->toBe($stranger)
            ->and(RendererUser::named((string) $stranger)->owners())->toBe([$stranger]);
    });

    it('refuses a name no user has', function () {
        expect(fn () => RendererUser::named('tinvar-no-such-user'))
            ->toThrow(\InvalidArgumentException::class, "names 'tinvar-no-such-user', and there is no such user here");
    });

    it('refuses root, by name or by number', function (string $root) {
        expect(fn () => RendererUser::named($root))
            ->toThrow(\InvalidArgumentException::class, 'names root');
    })->with(['root', '0']);

    // The user is separate or it is nothing: PHP running as it would hold
    // every socket to its own processes, and prove nothing.
    it('is refused, for PHP, when it is the user this process runs as', function () {
        $me = posix_getpwuid(posix_geteuid());
        \assert($me !== false);

        withEnvironment([RendererUser::ENV => $me['name']], function (): void {
            expect(fn () => RendererUser::socketOwners())
                ->toThrow(\InvalidArgumentException::class, 'the user this process runs as');
        });
    });

    it('gives SocketTrust the one user it names', function () {
        $stranger = strangerUid();

        withEnvironment([RendererUser::ENV => (string) $stranger], function () use ($stranger): void {
            expect(RendererUser::socketOwners())->toBe([$stranger]);
        });
    });
})->skip(fn () => !\function_exists('posix_getpwnam'), 'needs POSIX');

describe('telling who is at the other end', function () {
    it('reads the effective uid of the peer process the kernel names, on Linux', function () {
        $proc = fakeProc(4242, "1001\t1002\t1001\t1001");

        try {
            expect(linuxPeers($proc)->uid(aStream()))->toBe(1002)
                ->and(linuxPeers($proc)->unsupported())->toBeNull();
        } finally {
            removeProc($proc);
        }
    });

    it('says why, and names no user, when the kernel names no process', function (int|false $pid) {
        $proc = fakeProc(4242, "1001\t1001\t1001\t1001");

        try {
            expect(linuxPeers($proc, $pid)->uid(aStream()))->toBe('the kernel named no peer process for the connection (SO_PEERCRED)');
        } finally {
            removeProc($proc);
        }
    })->with([
        // A peer in a pid namespace this process cannot see.
        'pid 0' => [0],
        'no answer' => [false],
    ]);

    // Gone already, or hidden by procfs's hidepid.
    it('says why, and names no user, when the process cannot be read', function () {
        $proc = fakeProc(4242, "1001\t1001\t1001\t1001");

        try {
            expect(linuxPeers($proc, 4343)->uid(aStream()))->toBe("cannot read the user of the peer process 4343 from {$proc}");
        } finally {
            removeProc($proc);
        }
    });

    it('cannot tell on macOS, and says why', function () {
        $peers = new PeerCredentials('Darwin', 'arm64');

        expect($peers->unsupported())->toContain("PHP cannot read a Unix socket peer's user on Darwin")
            ->and($peers->uid(aStream()))->toBe($peers->unsupported());
    });

    it('cannot tell on a Linux whose SO_PEERCRED is numbered otherwise', function () {
        expect(new PeerCredentials('Linux', 'mips64')->unsupported())->toBe('SO_PEERCRED is numbered otherwise on mips64');
    });

    // The real thing, where there is one: the peer of a socket this process
    // listens on is this process's own user.
    it('reads this process\'s own user off a socket it listens on, on Linux', function () {
        $path = '/tmp/tvpeer-' . bin2hex(random_bytes(4)) . '.sock';
        $server = stream_socket_server("unix://{$path}");
        \assert($server !== false);

        try {
            $client = stream_socket_client("unix://{$path}");
            \assert($client !== false);
            expect(new PeerCredentials()->uid($client))->toBe(posix_geteuid());
        } finally {
            fclose($server);
            @unlink($path);
        }
    })->skip(PHP_OS_FAMILY !== 'Linux', 'SO_PEERCRED is Linux\'s');
});

describe('a socket, once the renderers\' user is named', function () {
    it('is answered by that user, and passes', function () {
        $proc = fakeProc(4242, "990\t990\t990\t990");

        try {
            new SocketTrust([990], linuxPeers($proc))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock');
            expect(true)->toBeTrue();
        } finally {
            removeProc($proc);
        }
    });

    it('is refused when anyone else answers -- this user, or root', function (string $uids) {
        $proc = fakeProc(4242, $uids);

        try {
            expect(fn () => new SocketTrust([990], linuxPeers($proc))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock'))
                ->toThrow(function (RendererException $e): void {
                    expect($e->failure())->toBe(RenderFailure::RendererUntrusted)
                        ->and($e->failure()->leavesRendererUsable())->toBeFalse()
                        ->and($e->getMessage())->toContain('Refusing the renderer at /run/pool/renderer-0-1.sock')
                        ->toContain("not the renderer's own user (uid 990)");
                });
        } finally {
            removeProc($proc);
        }
    })->with([
        'this user' => [(string) posix_geteuid() . "\t" . (string) posix_geteuid() . "\t0\t0"],
        'root' => ["0\t0\t0\t0"],
        // SO_PEERCRED names the effective user, and so does this.
        'another, really this one' => ["990\t1001\t990\t990"],
    ]);

    it('is refused when who answers cannot be told: fail closed', function () {
        expect(fn () => new SocketTrust([990], new PeerCredentials('Darwin', 'arm64'))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock'))
            ->toThrow(RendererException::class, 'the renderer is to run as a user of its own, and that cannot be verified: PHP cannot read');
    });

    it('is not asked about where no user is named: the renderer runs as this one', function () {
        new SocketTrust([], new PeerCredentials('Darwin', 'arm64'))->assertPeer(aStream(), '/tmp/r.sock');

        expect(new SocketTrust()->separateUser())->toBeFalse()
            ->and(new SocketTrust([990])->separateUser())->toBeTrue();
    });

    // Shared with PHP through a group of the two, 0660; open to everyone,
    // it is not the deployment that was asked for.
    it('is refused when every user may connect to it', function (int $mode, bool $refused) {
        $dir = '/tmp/tvmode-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $path = "{$dir}/renderer-0-1.sock";
        $server = stream_socket_server("unix://{$path}");
        \assert($server !== false);
        chmod($path, $mode);

        try {
            $separate = fn (): bool => new SocketTrust([strangerUid()])->assertSocket($path);
            if ($refused) {
                expect($separate)->toThrow(RendererException::class, \sprintf('it is mode %o, open to every user', $mode));
            } else {
                expect($separate())->toBeTrue();
            }
            // As before where the renderer runs as this user.
            expect(new SocketTrust()->assertSocket($path))->toBeTrue();
        } finally {
            fclose($server);
            @unlink($path);
            rmdir($dir);
        }
    })->with([
        '0660' => [0660, false],
        '0600' => [0600, false],
        '0666' => [0666, true],
        '0664' => [0664, true],
    ]);
})->skip(fn () => !\function_exists('posix_geteuid') || posix_geteuid() === 0, 'needs POSIX ownership, and not root');
