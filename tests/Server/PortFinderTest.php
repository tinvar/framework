<?php

declare(strict_types=1);

/**
 * Choosing where `tinvar serve` listens, with the bind check injected so no
 * port is touched -- and once against the real check, with a port held.
 */

namespace Tinvar\Tests\Server\PortFinder;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

/** @param \Closure(string, int): bool $canBind */
function finder(\Closure $canBind, int $seed = 7): PortFinder
{
    return new PortFinder($canBind, new Randomizer(new Mt19937($seed)));
}

it('picks a free port between 6000 and 7000 when none is given', function () {
    $port = finder(static fn (): bool => true)->find('127.0.0.1');

    expect($port)->toBeGreaterThanOrEqual(6000)->toBeLessThanOrEqual(7000);
});

// Random, so two servers started together do not race for one port.
it('picks at random rather than the first free port', function () {
    $picks = array_map(static fn (int $seed): int => finder(static fn (): bool => true, $seed)->find('127.0.0.1'), range(1, 5));

    expect(array_unique($picks))->not->toHaveCount(1)
        ->and($picks)->not->toContain(6000);
});

it('skips a taken port, trying each one once', function () {
    $tried = [];
    $port = finder(static function (string $host, int $port) use (&$tried): bool {
        $tried[] = $port;

        return $port === 6512;
    })->find('127.0.0.1');

    expect($port)->toBe(6512)
        ->and($tried)->toBe(array_values(array_unique($tried)), 'no port is tried twice')
        ->and(end($tried))->toBe(6512);
});

it('gives up with a clear message when every port in the range is taken', function () {
    $tried = 0;
    $finder = finder(static function () use (&$tried): bool {
        $tried++;

        return false;
    });

    expect(fn () => $finder->find('127.0.0.1'))
        ->toThrow(ServeException::class, 'No free port between 6000 and 7000 on 127.0.0.1: all 992 are in use')
        ->and($tried)->toBe(992, 'every port in the range but the nine browsers refuse');
});

// About one start in a hundred printed a URL Chromium will not load: nine
// ports in the range are on the browsers' list of bad ports, 6000 (X11) and
// the IRC ports among them.
it('never picks a port browsers refuse to load', function () {
    $tried = [];
    $finder = finder(static function (string $host, int $port) use (&$tried): bool {
        $tried[] = $port;

        return false;
    });

    try {
        $finder->find('127.0.0.1');
    } catch (ServeException) {
        // every port was refused, so every candidate was tried
    }

    expect(array_values(array_intersect($tried, PortFinder::BROWSER_UNSAFE)))->toBe([])
        ->and(array_values(array_intersect([6000, 6566, 6665, 6666, 6667, 6668, 6669, 6679, 6697], PortFinder::BROWSER_UNSAFE)))
        ->toHaveCount(9);
});

it('refuses an explicit port browsers refuse to load, and says why', function (string $port) {
    expect(fn () => finder(static fn (): bool => true)->find('127.0.0.1', $port))
        ->toThrow(ServeException::class, "Port {$port} is one browsers refuse to load");
})->with(['6000', '6667', '10080', '22']);

it('uses an explicit port that is free', function () {
    expect(finder(static fn (string $host, int $port): bool => $port === 8080)->find('127.0.0.1', '8080'))->toBe(8080);
});

it('refuses an explicit port that is in use', function () {
    expect(fn () => finder(static fn (): bool => false)->find('127.0.0.1', '8080'))
        ->toThrow(ServeException::class, 'Port 8080 on 127.0.0.1 is in use');
});

it('refuses a port that is not one', function (string $port) {
    expect(fn () => finder(static fn (): bool => true)->find('127.0.0.1', $port))
        ->toThrow(ServeException::class, '--port must be a whole number from 1 to 65535');
})->with(['zero' => ['0'], 'too high' => ['65536'], 'words' => ['abc'], 'a suffix' => ['80x'], 'negative' => ['-1'], 'empty' => [''], 'leading zero' => ['080']]);

it('refuses a host that is not one', function (string $host) {
    expect(fn () => finder(static fn (): bool => true)->find($host))
        ->toThrow(ServeException::class, '--host must be an IP address or a host name');
})->with([
    'spaces' => ['not a host'],
    'a URL' => ['http://127.0.0.1'],
    'a port glued on' => ['127.0.0.1:8080'],
    'empty' => [''],
    'an address out of range' => ['999.1.1.1'],
]);

it('takes an address or a host name', function (string $host) {
    expect(finder(static fn (): bool => true)->find($host, '8080'))->toBe(8080);
})->with(['IPv4' => ['127.0.0.1'], 'IPv6' => ['::1'], 'every interface' => ['0.0.0.0'], 'a name' => ['localhost']]);

it('writes an IPv6 host in brackets for a URL', function () {
    expect(PortFinder::hostForUrl('::1'))->toBe('[::1]')
        ->and(PortFinder::hostForUrl('127.0.0.1'))->toBe('127.0.0.1');
});

// The real check: a port someone is listening on cannot be bound.
it('sees a port another listener holds as taken', function () {
    $held = stream_socket_server('tcp://127.0.0.1:0');
    expect($held)->not->toBeFalse();
    $port = (int) substr((string) stream_socket_get_name($held, false), \strlen('127.0.0.1:'));

    try {
        expect(PortFinder::bind('127.0.0.1', $port))->toBeFalse()
            ->and(fn () => new PortFinder()->find('127.0.0.1', (string) $port))
            ->toThrow(ServeException::class, 'in use');
    } finally {
        fclose($held);
    }

    expect(PortFinder::bind('127.0.0.1', $port))->toBeTrue();
});

/**
 * PHP's listeners set SO_REUSEADDR, under which macOS lets a specific address
 * bind a port a wildcard listener already holds: a port another server was
 * serving on every interface read as free, and two servers shared it.
 *
 * @return array{resource, int} the listener, and the port it holds
 */
function hold(string $address): array
{
    $held = stream_socket_server("tcp://{$address}:0");
    expect($held)->not->toBeFalse("could not listen on {$address}");
    $name = (string) stream_socket_get_name($held, false);

    return [$held, (int) substr($name, strrpos($name, ':') + 1)];
}

it('sees a port held on every interface as taken on one', function (string $wildcard) {
    [$held, $port] = hold($wildcard);

    try {
        expect(PortFinder::bind('127.0.0.1', $port))->toBeFalse();
    } finally {
        fclose($held);
    }
})->with(['IPv4' => '0.0.0.0', 'IPv6' => '[::]']);

it('sees a port held on loopback as taken on every interface', function () {
    [$held, $port] = hold('127.0.0.1');

    try {
        expect(PortFinder::bind('0.0.0.0', $port))->toBeFalse()
            ->and(PortFinder::bind('::', $port))->toBeFalse();
    } finally {
        fclose($held);
    }
});
