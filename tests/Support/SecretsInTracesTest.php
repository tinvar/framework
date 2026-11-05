<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\SecretsInTraces;

use Tinvar\Orm\PdoConnection;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\RouteMinter;
use Tinvar\Support\AppKey;
use Tinvar\Support\Signer;

/**
 * The application key, a password and a session id never reach an exception
 * trace.
 *
 * A trace is what an error handler, a log line and a crash report all print,
 * and each frame records its arguments: in full through getTrace(), and their
 * first bytes through getTraceAsString(). Every parameter carrying one of
 * these is #[\SensitiveParameter], so a frame records a placeholder instead.
 */
beforeEach(function () {
    // Off is PHP's development default and On its production one. Forced
    // off here so the assertion means something wherever the suite runs.
    $this->ignoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
});

afterEach(function () {
    ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
});

/** A secret whose first bytes are as distinctive as the rest, for the truncated string form. */
function secret(): string
{
    return bin2hex(random_bytes(24));
}

/** Whether anything in the exception's traces -- either form, any previous -- shows the secret. */
function leaks(\Throwable $e, string $secret): bool
{
    // getTraceAsString() cuts each string argument to
    // zend.exception_string_param_max_len bytes, so its prefix is what to
    // look for there.
    $prefix = substr($secret, 0, 12);

    for ($at = $e; $at !== null; $at = $at->getPrevious()) {
        if (str_contains($at->getTraceAsString(), $prefix)) {
            return true;
        }
        foreach ($at->getTrace() as $frame) {
            if (inArguments($frame['args'] ?? [], $secret)) {
                return true;
            }
        }
    }

    return false;
}

/** @param array<mixed> $args */
function inArguments(array $args, string $secret): bool
{
    foreach ($args as $arg) {
        if (\is_string($arg) && str_contains($arg, $secret)) {
            return true;
        }
        if (\is_array($arg) && inArguments($arg, $secret)) {
            return true;
        }
    }

    return false;
}

function thrown(\Closure $work): \Throwable
{
    try {
        $work();
    } catch (\Throwable $e) {
        return $e;
    }

    throw new \LogicException('Expected the work to throw.');
}

it('keeps the key out of the trace of a signer it refused', function () {
    $key = secret();

    $e = thrown(static fn (): Signer => new Signer($key, ''));

    expect($e)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and(leaks($e, $key))->toBeFalse();
});

it('keeps the key out of the trace of a key it refused', function () {
    // Long enough, but with a control byte: checked() refuses it, and that
    // frame is the one holding the key.
    $key = secret() . "\x01";
    putenv("TINVAR_TRACE_KEY={$key}");

    try {
        $e = thrown(static fn (): string => AppKey::resolve('TINVAR_TRACE_KEY', sys_get_temp_dir() . '/tinvar-unused/app.key'));
    } finally {
        putenv('TINVAR_TRACE_KEY');
    }

    expect($e)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($e->getMessage())->toContain('printable ASCII')
        ->and(leaks($e, rtrim($key, "\x01")))->toBeFalse();
});

// Captured rather than passed, throughout: a closure's own parameters are a
// frame too, and the test must not be the thing that records the secret.
it('keeps the session id out of the trace of a fragment minter it refused', function () {
    $session = secret();

    $e = thrown(static fn (): FragmentMinter => new FragmentMinter(
        new Signer(str_repeat('k', 32), 'fragment-token'),
        'racks.show',
        [],
        '',
        '',
        'dev',
        $session,
        ttl: 0,
    ));

    expect($e)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and(leaks($e, $session))->toBeFalse();
});

it('keeps the session id out of the trace of a route minter it refused', function () {
    $session = secret();

    $e = thrown(static fn (): RouteMinter => new RouteMinter(
        new Signer(str_repeat('k', 32), 'route-token'),
        '',
        '',
        'dev',
        $session,
        ttl: 0,
    ));

    expect($e)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and(leaks($e, $session))->toBeFalse();
});

it('keeps the password out of the trace of a refused connection', function () {
    $password = secret();

    // Port 1: nothing listens, so the connection is refused at once.
    $e = thrown(static fn (): PdoConnection => PdoConnection::postgres(
        'pgsql:host=127.0.0.1;port=1;dbname=none',
        'nobody',
        $password,
    ));

    expect($e)->toBeInstanceOf(\PDOException::class)
        ->and(leaks($e, $password))->toBeFalse();
})->skip(!\extension_loaded('pdo_pgsql'), 'pdo_pgsql is not loaded.');

// The example's boot: a database that is not there throws from the frame
// that was handed the key.
it('keeps the key out of the trace of an example that could not start', function () {
    $key = secret();

    $e = thrown(static fn (): \Shop\Kernel => \Shop\Kernel::fromEnvironment(
        '/tmp/unused.sock',
        key: $key,
        database: sys_get_temp_dir() . '/tinvar-no-such-database-' . bin2hex(random_bytes(4)) . '.sqlite',
    ));

    expect($e)->toBeInstanceOf(\PDOException::class)
        ->and(leaks($e, $key))->toBeFalse();
});

it('keeps the key and the session id out of the example session helpers', function () {
    $key = secret();
    $cookie = \Shop\Kernel::newSession($key);

    // Neither can be made to throw from outside, so the redaction is read
    // off the declarations: every parameter that carries one is marked.
    $marked = static fn (string $method, string $parameter): bool => array_any(
        new \ReflectionMethod(\Shop\Kernel::class, $method)->getParameters(),
        static fn (\ReflectionParameter $p): bool => $p->getName() === $parameter
            && $p->getAttributes(\SensitiveParameter::class) !== [],
    );

    expect(\Shop\Kernel::sessionFrom($cookie, $key))->not->toBeNull()
        ->and($marked('newSession', 'key'))->toBeTrue()
        ->and($marked('sessionFrom', 'key'))->toBeTrue()
        ->and($marked('sessionFrom', 'cookie'))->toBeTrue()
        ->and($marked('sessionCookie', 'key'))->toBeTrue()
        ->and($marked('fromEnvironment', 'key'))->toBeTrue();
});
