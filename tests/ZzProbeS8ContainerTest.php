<?php

declare(strict_types=1);

namespace Tinvar\Tests\ZzProbeS8;

use Tinvar\Attributes\Dependency;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Container\Container;
use Tinvar\Container\Lifetime;

#[Dependency(Lifetime::Request)]
final class Basket
{
    public array $items = [];
}

#[Dependency(Lifetime::Worker)]
final class Reporter
{
    public function __construct(public Basket $basket) {}
}

final class Snapshot
{
    public ?string $who;
    public function __construct(AuthContext $auth)
    {
        $this->who = $auth->id();
    }
}

#[Dependency(Lifetime::Worker)]
final class Greeter
{
    public function __construct(public Snapshot $snap) {}
}

#[Dependency(Lifetime::Worker)]
final class PropHolder
{
    #[Dependency] public Basket $basket;
}

final class U implements Authenticatable
{
    public function __construct(private string $id) {} public function authIdentifier(): string
    {
        return $this->id;
    }
}

it('captive check is bypassed when the request-lived service is already cached', function () {
    $c = new Container();
    $c->get(Basket::class)->items[] = 'alice-secret';   // request 1 builds Basket first
    $r = $c->get(Reporter::class);                     // no exception
    $c->endRequest();
    expect($r->basket->items)->toBe(['alice-secret']); // request 2 sees it
});

it('property injection: same bypass', function () {
    $c = new Container();
    $c->get(Basket::class)->items[] = 'x';
    $h = $c->get(PropHolder::class);
    $c->endRequest();
    expect($h->basket->items)->toBe(['x']);
});

it('longLived bypass when cached', function () {
    $c = new Container();
    $c->get(Basket::class);
    $b = $c->longLived('stacks', fn () => $c->get(Basket::class));
    expect($b)->toBeInstanceOf(Basket::class);
});

it('transient that snapshots the user is frozen inside a worker service', function () {
    $auth = new AuthContext();
    $c = (new Container())->instance(AuthContext::class, $auth);
    $auth->login(new U('alice'));
    $c->get(Greeter::class);
    $auth->reset();
    $c->endRequest();
    $auth->login(new U('bob'));
    expect($c->get(Greeter::class)->snap->who)->toBe('alice');
});
