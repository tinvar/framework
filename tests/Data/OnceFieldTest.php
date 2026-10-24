<?php

declare(strict_types=1);

/**
 * #[Once] fields, the encoder's half: "skipped when listed in `X-Have-Once`".
 *
 * ADR 0020. The browser keeps what was rendered from a once field, under its
 * key; a request listing the key gets a marker in the field's place, and the
 * field's Lazy is never resolved. What these pin down: the field always
 * crosses as a once field, so its generated type is one shape; held, its
 * resolver does not run and nothing of it is sent; and a key or duration that
 * could never work is refused when the class is read.
 */

namespace Tinvar\Tests\Data\OnceField;

use Tinvar\Attributes\Once;
use Tinvar\Attributes\Rescue;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;
use Tinvar\Data\PayloadContext;

final readonly class CountryData extends Data
{
    public function __construct(public string $code, public string $name) {}
}

final readonly class SettingsPageData extends Data
{
    /**
     * @param Lazy<list<CountryData>> $countries
     * @param Lazy<int> $orders
     */
    public function __construct(
        public string $title,
        #[Once(key: 'countries', until: '1d', tags: ['geo'])]
        public Lazy $countries,
        #[Once]
        public Lazy $orders,
    ) {}
}

final readonly class RescuedOnceData extends Data
{
    /** @param Lazy<int> $total */
    public function __construct(#[Once, Rescue] public Lazy $total) {}
}

final readonly class BadKeyData extends Data
{
    public function __construct(#[Once(key: 'two words')] public int $n) {}
}

final readonly class BadUntilData extends Data
{
    public function __construct(#[Once(until: 'tomorrow')] public int $n) {}
}

final readonly class PlainOnceData extends Data
{
    public function __construct(#[Once(key: 'plain')] public int $n) {}
}

/** The key a field with no key of its own is sent under: its class and name, and the page. */
function defaultKey(string $declared, string $segment = '$', string $route = ''): string
{
    return "{$declared}:" . PayloadContext::digest("{$segment}\0{$route}");
}

/** @return array{SettingsPageData, \ArrayObject<int, string>} */
function page(): array
{
    $ran = new \ArrayObject();

    return [new SettingsPageData(
        title: 'Settings',
        countries: Lazy::of(static function () use ($ran): array {
            $ran[] = 'countries';

            return [new CountryData('NZ', 'New Zealand')];
        }),
        orders: Lazy::of(static function () use ($ran): int {
            $ran[] = 'orders';

            return 12;
        }),
    ), $ran];
}

describe('a once field the browser does not hold', function () {
    it('crosses with its key, how long it may be claimed, its tags and its value', function () {
        [$data] = page();

        $encoded = (new Encoder())->encode($data, segment: 'page');

        expect($encoded['countries'])->toBe([
            '$once' => 'countries',
            'ttl' => 86400,
            'tags' => ['geo'],
            'value' => [['code' => 'NZ', 'name' => 'New Zealand']],
        ]);
    });

    // A key shared by accident would show one field's content in another's
    // place, so the default names the class as well as the field -- and the
    // page it was rendered for, which OnceSubjectTest takes through a route.
    it('is kept under the class and field when no key is named, for as long as the session lasts', function () {
        [$data] = page();

        $encoded = (new Encoder())->encode($data);

        expect($encoded['orders'])->toBe(['$once' => defaultKey('SettingsPageData.orders'), 'ttl' => null, 'tags' => [], 'value' => 12]);
    });

    it('is kept under another key on another page, and in another segment', function () {
        [$data] = page();
        $encoder = new Encoder();

        $keys = [
            $encoder->encode($data, new PayloadContext(route: 'one'), 'page')['orders']['$once'],
            $encoder->encode($data, new PayloadContext(route: 'two'), 'page')['orders']['$once'],
            $encoder->encode($data, new PayloadContext(route: 'one'), 'layout')['orders']['$once'],
        ];

        expect(array_unique($keys))->toHaveCount(3)
            ->and($keys[0])->toBe(defaultKey('SettingsPageData.orders', 'page', 'one'))
            ->and($keys[0])->toMatch(PayloadContext::KEY);
    });

    it('is a once field in every payload, whatever else the request says', function () {
        [$data] = page();

        $encoded = (new Encoder())->encode($data, new PayloadContext(heldOnce: ['something-else']));

        expect($encoded['countries'])->toHaveKey('value')
            ->and($encoded['title'])->toBe('Settings');
    });
});

describe('a once field the browser holds', function () {
    it('is sent as its key alone, and its resolver never runs', function () {
        [$data, $ran] = page();

        $encoded = (new Encoder())->encode($data, new PayloadContext(heldOnce: ['countries']), 'page');

        expect($encoded['countries'])->toBe(['$once' => 'countries', 'held' => true])
            ->and($ran->getArrayCopy())->toBe(['orders']);
    });

    it('leaves the rest of the data as it would be', function () {
        [$data] = page();

        $encoded = (new Encoder())->encode($data, new PayloadContext(heldOnce: ['countries', defaultKey('SettingsPageData.orders')]));

        expect($encoded)->toBe([
            'title' => 'Settings',
            'countries' => ['$once' => 'countries', 'held' => true],
            'orders' => ['$once' => defaultKey('SettingsPageData.orders'), 'held' => true],
        ]);
    });

    // Not a Lazy: computed by the loader anyway, but neither encoded nor
    // rendered, which is where under RSC a page's cost mostly is.
    it('is left out even when its value was computed already', function () {
        $encoded = (new Encoder())->encode(new PlainOnceData(3), new PayloadContext(heldOnce: ['plain']));

        expect($encoded)->toBe(['n' => ['$once' => 'plain', 'held' => true]]);
    });
});

it('rescues inside the once field: the marker is its value', function () {
    $data = new RescuedOnceData(Lazy::of(static fn (): int => throw new \RuntimeException('no')));

    $encoded = (new Encoder(static fn (\Throwable $e): string => 'digest-1'))->encode($data);

    expect($encoded['total'])->toBe([
        '$once' => defaultKey('RescuedOnceData.total'),
        'ttl' => null,
        'tags' => [],
        'value' => ['$rescued' => 'digest-1'],
    ]);
});

describe('what cannot be a once field', function () {
    it('refuses a key a header could not carry', function () {
        expect(fn () => DataShape::once(BadKeyData::class))->toThrow(\LogicException::class, 'two words');
    });

    it('refuses a duration it cannot read', function () {
        expect(fn () => DataShape::once(BadUntilData::class))->toThrow(\InvalidArgumentException::class, 'tomorrow');
    });
});
