<?php

declare(strict_types=1);

use Tinvar\Support\Str;

it('snake-cases a class name', function (string $in, string $out) {
    expect(Str::snake($in))->toBe($out);
})->with([['Rack', 'rack'], ['RackUnit', 'rack_unit'], ['HTTPThing', 'h_t_t_p_thing'], ['a', 'a']]);

it('takes a class short name', function () {
    expect(Str::shortName('App\\Data\\RackData'))->toBe('RackData')
        ->and(Str::shortName('Rootless'))->toBe('Rootless');
});

// The wire name a #[Query] gets when it does not name itself.
it('derives a query wire name from the namespace under Queries', function (string $class, string $name) {
    expect(Str::queryName($class))->toBe($name);
})->with([
    'under Queries' => ['App\\Queries\\Racks\\UnitLoad', 'racks.unitLoad'],
    'nested deeper' => ['App\\Queries\\Sites\\Power\\Peak', 'sites.power.peak'],
    'no Queries segment' => ['App\\Reports\\Monthly', 'monthly'],
]);
