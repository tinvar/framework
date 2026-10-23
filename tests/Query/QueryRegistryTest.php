<?php

declare(strict_types=1);

use Tinvar\Query\QueryRegistry;

require_once __DIR__ . '/Fixtures/queries.php';
require_once __DIR__ . '/../Orm/Fixtures/models.php';

// Kept for the worker's life, a query a resolver built with a request-lived
// service would carry one request's state into the next. With a resolver,
// the registry asks every time and leaves keeping to the container.
it('asks the resolver for every use instead of keeping its instance', function () {
    $built = 0;
    $registry = new QueryRegistry(function (string $class) use (&$built): object {
        $built++;

        return new $class();
    });
    $name = $registry->registerAll([Tinvar\Tests\Query\Fixtures\RackSummary::class])[0];

    $registry->instanceFor($name);
    $registry->instanceFor($name);

    expect($built)->toBe(2);
});

it('keeps one instance of a query it builds itself, which has no state', function () {
    $registry = new QueryRegistry();
    $name = $registry->registerAll([Tinvar\Tests\Query\Fixtures\RackSummary::class])[0];

    expect($registry->instanceFor($name))->toBe($registry->instanceFor($name));
});
