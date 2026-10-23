<?php

declare(strict_types=1);

namespace Tinvar\Tests\ZzProbeC8;

use Tinvar\Attributes\Dependency;
use Tinvar\Container\Container;
use Tinvar\Container\Lifetime;

#[Dependency(Lifetime::Request)] final class CurrentUser
{
    public static int $n = 0;
    public int $id;
    public function __construct()
    {
        $this->id = ++self::$n;
    }
}
#[Dependency(Lifetime::Worker)] final class Cachey
{
    public function __construct(public CurrentUser $user) {}
}
final class Plain
{
    public function __construct(public Container $c) {}
}
final class Svc {}
abstract class Base
{
    #[Dependency] private Svc $svc;
    public function svc(): Svc
    {
        return $this->svc;
    }
}
final class Child extends Base {}
final class Promoted
{
    public function __construct(#[Dependency] public readonly Svc $svc) {}
}
interface Logger {}
final class OptionalDep
{
    public function __construct(public ?Logger $logger = null) {}
}
final class Variadic
{
    public array $xs;
    public function __construct(string ...$xs)
    {
        $this->xs = $xs;
    }
}
final class SelfRef
{
    public function __construct(public ?self $parent = null) {}
}
final class NoAttr {}
interface Repo {}
#[Dependency(Lifetime::Request)] final class ReqRepo implements Repo {}

test('captive bypass when request service already cached', function () {
    $c = new Container();
    $c->get(CurrentUser::class);           // something earlier in the request used it
    $w = $c->get(Cachey::class);            // Worker now captures it, no error
    $c->endRequest();
    expect($c->get(Cachey::class)->user)->toBe($w->user); // carried into next request
});
test('captive bypass via bind with worker lifetime', function () {
    $c = new Container();
    $c->bind(Repo::class, ReqRepo::class, Lifetime::Worker);
    $c->get(ReqRepo::class);
    $r = $c->get(Repo::class);
    $c->endRequest();
    expect($c->get(Repo::class))->toBe($r);
});
test('bind class to itself with a lifetime', function () {
    $c = new Container();
    $c->bind(NoAttr::class, NoAttr::class, Lifetime::Worker);
    expect(fn () => $c->get(NoAttr::class))->toThrow(\Throwable::class);
    try {
        $c->get(NoAttr::class);
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nSELF-BIND: " . $e->getMessage() . "\n");
    }
});
test('Container autowired is a fresh container', function () {
    $c = new Container();
    $c->instance(Svc::class, $s = new Svc());
    $p = $c->get(Plain::class);
    expect($p->c)->not->toBe($c);
});
test('inherited private dependency property', function () {
    $c = new Container();
    $child = $c->get(Child::class);
    expect(fn () => $child->svc())->toThrow(\Error::class);
});
test('promoted dependency', function () {
    $c = new Container();
    try {
        $c->get(Promoted::class);
        fwrite(STDERR, "\nPROMOTED OK\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nPROMOTED: " . $e::class . ' ' . $e->getMessage() . "\n");
    }
    expect(true)->toBeTrue();
});
test('optional unbound interface', function () {
    $c = new Container();
    try {
        $c->get(OptionalDep::class);
        fwrite(STDERR, "\nOPTIONAL OK\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nOPTIONAL: " . $e->getMessage() . "\n");
    }
    expect(true)->toBeTrue();
});
test('variadic and self', function () {
    $c = new Container();
    foreach ([Variadic::class, SelfRef::class] as $k) {
        try {
            $c->get($k);
            fwrite(STDERR, "\n$k OK\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n$k: " . $e->getMessage() . "\n");
        }
    }
    try {
        $c->get('Nope\\Missing');
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nMISSING: " . $e::class . "\n");
    }
    expect($c->has(Logger::class))->toBeFalse();
    expect($c->has(Base::class))->toBeTrue(); // has() says yes, get() throws
});
