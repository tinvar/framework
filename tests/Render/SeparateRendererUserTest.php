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

use Tinvar\Codec\MessagePackCodec;
use Tinvar\Protocol\Frame;
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

/**
 * Linux's answer, on any platform: the user the kernel attaches to what is
 * read stood in for, null for none.
 */
function peers(?int $uid): PeerCredentials
{
    return new PeerCredentials('Linux', static fn (\Socket $socket): ?int => $uid);
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

/**
 * A connected pair's end, its peer kept open, with $answer already written
 * to it from the far end -- a renderer's answer to the hello, there before
 * it is asked, which the read cannot tell apart -- and the far end, to read
 * what was sent to it.
 *
 * @return array{resource, resource}
 */
function answered(string $answer = 'ready'): array
{
    static $kept = [];
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    \assert($pair !== false);
    $kept[] = $pair[1];
    $frame = match ($answer) {
        'ready' => new MessagePackCodec()->encodeFrame(['t' => Frame::READY]),
        'nothing' => '',
        default => $answer,
    };
    if ($frame !== '') {
        fwrite($pair[1], $frame);
    }

    return [$pair[0], $pair[1]];
}

/** @return resource a connected pair's end whose peer has answered the hello */
function aStream()
{
    return answered()[0];
}

/** @param resource $far what the far end was sent, read without waiting */
function sentTo($far): string
{
    stream_set_blocking($far, false);

    return (string) stream_get_contents($far);
}

describe('naming the renderers\' user', function () {
    it('is nobody when TINVAR_RENDERER_USER is not set', function () {
        withEnvironment([RendererUser::ENV => null], function (): void {
            expect(RendererUser::fromEnvironment())->toBeNull()
                ->and(RendererUser::socketOwners())->toBe([]);
        });
    });

    it('is a user by name, or by a uid an account need not have', function () {
        $daemon = posix_getpwnam('daemon');
        \assert($daemon !== false);
        $stranger = strangerUid();

        expect(RendererUser::named('daemon')->uid)->toBe($daemon['uid'])
            ->and(RendererUser::named('daemon')->name)->toBe('daemon')
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

    // Every answer the kernel could not attribute names it: a renderer held
    // to it would be held to no one.
    it('refuses the overflow user, nobody, on Linux', function () {
        $overflow = PeerCredentials::overflowUid();
        \assert($overflow !== null);

        expect(fn () => RendererUser::named((string) $overflow))
            ->toThrow(\InvalidArgumentException::class, 'the user Linux names when it cannot say who wrote to a socket');
    })->skip(PHP_OS_FAMILY !== 'Linux', 'the overflow user is Linux\'s');

    // The user is separate or it is nothing: PHP running as it would hold
    // every socket to its own processes, and prove nothing.
    it('is refused, for PHP, when it is the user this process runs as', function () {
        $me = posix_getpwuid(posix_geteuid());
        \assert($me !== false);

        withEnvironment([RendererUser::ENV => $me['name']], function (): void {
            expect(fn () => RendererUser::socketOwners())
                ->toThrow(\InvalidArgumentException::class, 'the user this process runs as');
        });
    })->skip(fn () => posix_geteuid() === 0, 'root is refused as root first');

    it('gives SocketTrust the one user it names', function () {
        $stranger = strangerUid();

        withEnvironment([RendererUser::ENV => (string) $stranger], function () use ($stranger): void {
            expect(RendererUser::socketOwners())->toBe([$stranger]);
        });
    });
})->skip(fn () => !\function_exists('posix_getpwnam'), 'needs POSIX');

describe('telling who is at the other end', function () {
    it('asks with a hello, and names the user the kernel attaches to the ready frame that answers it', function () {
        [$stream, $far] = answered();

        expect(peers(990)->uid($stream, new MessagePackCodec(), 1.0))->toBe(990)
            ->and(peers(990)->unsupported())->toBeNull();
        $offset = 0;
        expect(new MessagePackCodec()->decodeFrame(sentTo($far), $offset))->toBe(['t' => Frame::HELLO]);
    });

    it('says why, and names no user, when the answer is not one ready frame from one user', function (string $answer, ?int $uid, string $why) {
        [$stream] = answered($answer);

        expect(peers($uid)->uid($stream, new MessagePackCodec(), 0.2))->toBe($why);
    })->with([
        'no user attached' => ['ready', null, 'the kernel named no user for the answer to the hello (SCM_CREDENTIALS)'],
        'no answer in time' => ['nothing', 990, 'no answer to the hello within 0.2s'],
        'another frame' => [new MessagePackCodec()->encodeFrame(['t' => Frame::CHUNK, 'b' => 'x']), 990, 'the hello was answered with something other than a ready frame'],
        'a ready frame saying more' => [new MessagePackCodec()->encodeFrame(['t' => Frame::READY, 'pid' => 1]), 990, 'the hello was answered with something other than a ready frame'],
        'more after it' => [new MessagePackCodec()->encodeFrame(['t' => Frame::READY]) . new MessagePackCodec()->encodeFrame(['t' => Frame::CHUNK, 'b' => 'x']), 990, 'the hello was answered with something other than a ready frame'],
        'too much' => [str_repeat("\xff", PeerCredentials::MAX_READY_BYTES + 1), 990, 'the hello was answered with more than a ready frame'],
    ]);

    it('says why when the peer has closed the connection', function () {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        \assert($pair !== false);
        fclose($pair[1]);

        expect(peers(990)->uid($pair[0], new MessagePackCodec(), 1.0))->toBeIn([
            'the connection was closed instead of the hello being answered',
            'the hello could not be sent',
        ]);
    });

    it('leaves the connection as it found it, for the render to read and write', function () {
        [$stream, $far] = answered();
        peers(990)->uid($stream, new MessagePackCodec(), 1.0);
        fwrite($far, 'then the render');

        expect(fread($stream, 15))->toBe('then the render');
    });

    it('cannot tell on macOS, and says why, sending nothing', function () {
        $peers = new PeerCredentials('Darwin');
        [$stream, $far] = answered();

        expect($peers->unsupported())->toContain("PHP cannot read a Unix socket peer's user on Darwin")
            ->and($peers->uid($stream, new MessagePackCodec(), 1.0))->toBe($peers->unsupported())
            ->and(sentTo($far))->toBe('');
    });

    // The real thing, where there is one. An answer the renderer wrote
    // once asked names its user; one written before PHP asked carries no
    // credentials, which the kernel reads as the overflow user -- refused,
    // since anything listening could write one.
    it('reads the user of whoever answered the hello, and refuses an answer written before it, on Linux', function () {
        $path = '/tmp/tvpeer-' . bin2hex(random_bytes(4)) . '.sock';
        $server = stream_socket_server("unix://{$path}");
        \assert($server !== false);
        $ready = new MessagePackCodec()->encodeFrame(['t' => Frame::READY]);

        try {
            $early = stream_socket_client("unix://{$path}");
            \assert($early !== false);
            $accepted = stream_socket_accept($server);
            \assert($accepted !== false);
            fwrite($accepted, $ready);
            $before = new PeerCredentials()->uid($early, new MessagePackCodec(), 1.0);

            $answerer = pcntl_fork();
            if ($answerer === 0) {
                $connection = stream_socket_accept($server, 5);
                if ($connection !== false && fread($connection, 64) !== '') {
                    fwrite($connection, $ready);
                    fread($connection, 1);
                }
                posix_kill(getmypid(), SIGKILL);
                exit(0);
            }
            $asked = stream_socket_client("unix://{$path}");
            \assert($asked !== false);
            $after = new PeerCredentials()->uid($asked, new MessagePackCodec(), 2.0);
            posix_kill($answerer, SIGKILL);
            pcntl_waitpid($answerer, $status);
        } finally {
            fclose($server);
            @unlink($path);
        }

        expect($after)->toBe(posix_getuid())
            ->and($before)->toContain('it named the overflow user');
    })->skip(PHP_OS_FAMILY !== 'Linux' || !\function_exists('pcntl_fork'), 'SCM_CREDENTIALS is Linux\'s');
});

describe('a socket, once the renderers\' user is named', function () {
    it('is answered by that user, and passes', function () {
        new SocketTrust([990], peers(990))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock', new MessagePackCodec(), 1.0);

        expect(true)->toBeTrue();
    });

    it('is refused when anyone else answers -- this user, or root', function (int $uid) {
        expect(fn () => new SocketTrust([990], peers($uid))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock', new MessagePackCodec(), 1.0))
                ->toThrow(function (RendererException $e): void {
                    expect($e->failure())->toBe(RenderFailure::RendererUntrusted)
                        ->and($e->failure()->leavesRendererUsable())->toBeFalse()
                        ->and($e->getMessage())->toContain('Refusing the renderer at /run/pool/renderer-0-1.sock')
                        ->toContain("not the renderer's own user (uid 990)");
                });
    })->with([
        'this user' => [posix_getuid()],
        'root' => [0],
    ]);

    it('is refused when who answers cannot be told: fail closed', function () {
        expect(fn () => new SocketTrust([990], new PeerCredentials('Darwin'))->assertPeer(aStream(), '/run/pool/renderer-0-1.sock', new MessagePackCodec(), 1.0))
            ->toThrow(RendererException::class, 'the renderer is to run as a user of its own, and that cannot be verified: PHP cannot read');
    });

    it('is not asked about where no user is named: the renderer runs as this one', function () {
        [$stream, $far] = answered();
        new SocketTrust([], new PeerCredentials('Darwin'))->assertPeer($stream, '/tmp/r.sock', new MessagePackCodec(), 1.0);

        expect(sentTo($far))->toBe('')
            ->and(new SocketTrust()->separateUser())->toBeFalse()
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
