<?php

declare(strict_types=1);

/**
 * A #[Rescue] Lazy field: "A deferred or lazy field marked `rescue` that
 * throws is reported to the PHP exception handler and omitted"
 * (tinvar-spec.md, "Rescue and reloading"). Omitted as the Rescued marker,
 * so the client can tell a field that failed from one that is null.
 */

namespace Tinvar\Tests\Data\Rescue;

use Tinvar\Attributes\Rescue;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Support\Digest;

final readonly class UpdateData extends Data
{
    public function __construct(public string $title) {}
}

final readonly class OrderData extends Data
{
    public function __construct(
        public string $number,
        /** @var Lazy<list<UpdateData>> */
        #[Rescue]
        public Lazy $updates,
        /** @var Lazy<int> */
        public Lazy $count,
    ) {}
}

/** Stands in for a model: an ordinary object that must never cross. */
final class Row {}

/**
 * An encoder whose reporter records what it was handed.
 *
 * @param list<array{\Throwable, ?string}> $reported
 */
function encoder(array &$reported): Encoder
{
    return new Encoder(static function (\Throwable $e, ?string $digest) use (&$reported): string {
        $reported[] = [$e, $digest];

        return 'logged-digest';
    });
}

it('sends a rescued field that threw as the marker, with the digest it was reported under', function () {
    $reported = [];
    $failure = new \RuntimeException('carrier timed out');

    $encoded = encoder($reported)->encode(new OrderData(
        'PW-1',
        Lazy::of(static fn () => throw $failure),
        Lazy::value(3),
    ));

    expect($encoded)->toBe(['number' => 'PW-1', 'updates' => ['$rescued' => 'logged-digest'], 'count' => 3])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0][0])->toBe($failure)
        // The handler picks the digest; the encoder has none of its own.
        ->and($reported[0][1])->toBeNull();
});

it('sends a rescued field that resolved as its value, and reports nothing', function () {
    $reported = [];

    $encoded = encoder($reported)->encode(new OrderData(
        'PW-1',
        Lazy::of(static fn () => [new UpdateData('Packed')]),
        Lazy::value(3),
    ));

    expect($encoded['updates'])->toBe([['title' => 'Packed']])
        ->and($reported)->toBe([]);
});

// Rescue is a field's opt-in: a Lazy without it still fails the payload, as
// it always has, rather than every Lazy quietly becoming optional.
it('lets a field that is not marked fail the payload', function () {
    $reported = [];
    $failure = new \RuntimeException('count failed');

    expect(fn () => encoder($reported)->encode(new OrderData(
        'PW-1',
        Lazy::value([]),
        Lazy::of(static fn () => throw $failure),
    )))->toThrow(\RuntimeException::class, 'count failed');

    expect($reported)->toBe([]);
});

// The resolver is what is rescued. A model in what it returned is a bug in
// the field, and the boundary refuses it in every environment.
it('still refuses a model a rescued field resolves to', function () {
    $reported = [];

    expect(fn () => encoder($reported)->encode(new OrderData(
        'PW-1',
        Lazy::of(static fn () => [new Row()]),
        Lazy::value(3),
    )))->toThrow(DataBoundaryException::class);

    expect($reported)->toBe([]);
});

it('rescues each field in a list of Data objects on its own', function () {
    $reported = [];
    $orders = [
        new OrderData('PW-1', Lazy::of(static fn () => throw new \RuntimeException('one')), Lazy::value(1)),
        new OrderData('PW-2', Lazy::value([new UpdateData('Shipped')]), Lazy::value(2)),
    ];

    $encoded = encoder($reported)->encode($orders);

    expect($encoded[0]['updates'])->toBe(['$rescued' => 'logged-digest'])
        ->and($encoded[1]['updates'])->toBe([['title' => 'Shipped']])
        ->and($reported)->toHaveCount(1);
});

// An application that builds its own Encoder without a reporter still has
// its rescued fields logged -- to PHP's error log -- under the digest the
// exception handler would give them.
it('falls back to the error log without a reporter', function () {
    $log = tempnam(sys_get_temp_dir(), 'tinvar-rescue-');
    $previous = ini_set('error_log', (string) $log);
    $failure = new \RuntimeException('no reporter');

    try {
        $encoded = new Encoder()->encode(new OrderData('PW-1', Lazy::of(static fn () => throw $failure), Lazy::value(0)));
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
    }

    $written = (string) file_get_contents((string) $log);
    unlink((string) $log);

    expect($encoded['updates'])->toBe(['$rescued' => Digest::of($failure)])
        ->and($written)->toContain('RuntimeException: no reporter [' . Digest::of($failure) . ']');
});
