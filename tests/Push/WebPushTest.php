<?php

declare(strict_types=1);

/**
 * Web push (ADR 0096): VAPID keys as a #[Config] class with a Secret,
 * messages encrypted as RFC 8291 has them and signed as RFC 8292 does,
 * subscriptions bound to their user and tenant, and each message sent from
 * a worker through OutboundHttp, to a push service the application names.
 */

namespace Tinvar\Tests\Push\WebPush;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Auth\UserLookup;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Push\ArrayPushSubscriptions;
use Tinvar\Push\DatabasePushSubscriptions;
use Tinvar\Push\P256;
use Tinvar\Push\PushEncryption;
use Tinvar\Push\PushNotifications;
use Tinvar\Push\PushServices;
use Tinvar\Push\PushSubscriptions;
use Tinvar\Push\SendPush;
use Tinvar\Push\VapidConfig;
use Tinvar\Push\VapidKeys;
use Tinvar\Push\WebPush;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Support\Signer;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Script;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

/** A browser: its key pair and auth secret, and the subscription JSON it would hand over. */
final class Browser
{
    public \OpenSSLAsymmetricKey $key;
    public string $auth;

    public function __construct()
    {
        $this->key = P256::generate();
        $this->auth = random_bytes(16);
    }

    public function p256dh(): string
    {
        return Signer::encode(P256::point($this->key));
    }

    public function authSecret(): string
    {
        return Signer::encode($this->auth);
    }

    /** What the service worker's push event would read: RFC 8291 undone. */
    public function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $idlen = \ord($body[20]);
        $sender = substr($body, 21, $idlen);
        $ciphertext = substr($body, 21 + $idlen, -16);
        $tag = substr($body, -16);
        $shared = P256::sharedSecret($this->key, P256::publicKey($sender));
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . P256::point($this->key) . $sender, $this->auth);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt($ciphertext, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        \assert(\is_string($plain) && str_ends_with($plain, "\x02"));

        return substr($plain, 0, -1);
    }
}

final readonly class ShippedPush extends Data
{
    public function __construct(public string $title, public string $order) {}
}

/** @return array{public: string, private: string} */
function pair(): array
{
    static $pair = null;

    return $pair ??= VapidKeys::generate();
}

function keys(): VapidKeys
{
    return VapidKeys::fromStrings(pair()['public'], pair()['private'], 'mailto:ops@shop.example');
}

/** Verifies an ES256 JWT's signature with the public key, the way a push service does. */
function verifies(string $jwt, string $publicKey): bool
{
    [$header, $claims, $signature] = explode('.', $jwt);
    $raw = (string) Signer::decode($signature);
    $integer = static function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || \ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . \chr(\strlen($bytes)) . $bytes;
    };
    $sequence = $integer(substr($raw, 0, 32)) . $integer(substr($raw, 32));
    $der = "\x30" . \chr(\strlen($sequence)) . $sequence;

    return openssl_verify("{$header}.{$claims}", $der, P256::publicKey((string) Signer::decode($publicKey)), OPENSSL_ALGO_SHA256) === 1;
}

describe('VAPID keys', function () {
    it('are a #[Config] class whose private key is a Secret', function () {
        $config = Configs::load(VapidConfig::class, [
            'TINVAR_VAPID_PUBLIC_KEY' => pair()['public'],
            'TINVAR_VAPID_PRIVATE_KEY' => pair()['private'],
            'TINVAR_VAPID_SUBJECT' => 'mailto:ops@shop.example',
        ]);
        \assert($config instanceof VapidConfig);

        expect(print_r($config, true))->not->toContain(pair()['private'])
            ->and(json_encode($config))->not->toContain(pair()['private'])
            ->and(print_r(VapidKeys::fromConfig($config), true))->not->toContain(pair()['private'])
            ->and(fn () => serialize(VapidKeys::fromConfig($config)))->toThrow(\LogicException::class);
    });

    it('are refused at boot by variable: a mismatched pair, a key that is not one, a subject that is neither', function (array $env, string $variable) {
        $env += ['TINVAR_VAPID_PUBLIC_KEY' => pair()['public'], 'TINVAR_VAPID_PRIVATE_KEY' => pair()['private'], 'TINVAR_VAPID_SUBJECT' => 'https://shop.example'];

        expect(fn () => Configs::load(VapidConfig::class, $env))
            ->toThrow(fn (ConfigException $e) => expect($e->getMessage())->toContain($variable)->not->toContain(pair()['private']));
    })->with([
        'another pair\'s private key' => [['TINVAR_VAPID_PRIVATE_KEY' => VapidKeys::generate()['private']], 'TINVAR_VAPID_PRIVATE_KEY'],
        'a private key too short' => [['TINVAR_VAPID_PRIVATE_KEY' => Signer::encode(random_bytes(16))], 'TINVAR_VAPID_PRIVATE_KEY'],
        'a public key off the curve' => [['TINVAR_VAPID_PUBLIC_KEY' => Signer::encode("\x04" . str_repeat("\x01", 64))], 'TINVAR_VAPID_PUBLIC_KEY'],
        'a subject over http' => [['TINVAR_VAPID_SUBJECT' => 'http://shop.example'], 'TINVAR_VAPID_SUBJECT'],
        'no private key' => [['TINVAR_VAPID_PRIVATE_KEY' => ''], 'TINVAR_VAPID_PRIVATE_KEY'],
    ]);

    it('sign a header for one push service, for twelve hours, that the public key verifies', function () {
        $header = keys()->authorization('https://fcm.googleapis.com', 1_800_000_000);
        \assert(preg_match('/^vapid t=([^,]+), k=(\S+)\z/', $header, $m) === 1);
        $claims = json_decode((string) Signer::decode(explode('.', $m[1])[1]), true);

        expect($m[2])->toBe(pair()['public'])
            ->and($claims)->toBe(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 43_200, 'sub' => 'mailto:ops@shop.example'])
            ->and(verifies($m[1], pair()['public']))->toBeTrue()
            ->and(verifies($m[1], VapidKeys::generate()['public']))->toBeFalse();
    });
});

describe('encryption', function () {
    // RFC 8291, Appendix A.
    it('matches the RFC\'s worked example', function () {
        $body = PushEncryption::encrypt(
            'When I grow up, I want to be a watermelon',
            (string) Signer::decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            (string) Signer::decode('BTBZMqHH6r4Tts7J_aSIgg'),
            (string) Signer::decode('DGv6ra1nlYgDCS1FRnbzlw'),
            P256::privateKey((string) Signer::decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw')),
        );

        expect(Signer::encode($body))->toBe(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_'
            . 'yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
        );
    });

    it('uses a fresh key and salt each message, and refuses one too large to carry', function () {
        $browser = new Browser();
        $a = PushEncryption::encrypt('hello', P256::point($browser->key), $browser->auth);
        $b = PushEncryption::encrypt('hello', P256::point($browser->key), $browser->auth);

        expect($browser->decrypt($a))->toBe('hello')
            ->and(substr($a, 0, 16))->not->toBe(substr($b, 0, 16))
            ->and(substr($a, 21, 65))->not->toBe(substr($b, 21, 65))
            ->and(\strlen(PushEncryption::encrypt(str_repeat('x', PushEncryption::MAX_PAYLOAD), P256::point($browser->key), $browser->auth)))->toBe(4096)
            ->and(fn () => PushEncryption::encrypt(str_repeat('x', PushEncryption::MAX_PAYLOAD + 1), P256::point($browser->key), $browser->auth))->toThrow(\InvalidArgumentException::class);
    });
});

describe('subscriptions', function () {
    it('are bound to the user and the tenant they were made in', function () {
        $harness = new Harness();
        DatabasePushSubscriptions::define(schemaOf($harness->fixture->connection));
        $store = new DatabasePushSubscriptions($harness->fixture->connection);
        $browser = new Browser();
        $held = $store->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());

        expect($store->forUser('acme', 'u-1'))->toEqual([$held])
            ->and($store->forUser('acme', 'u-2'))->toBe([])
            ->and($store->forUser('globex', 'u-1'))->toBe([])
            ->and($store->find('globex', $held->id))->toBeNull()
            // Only its holder takes it away.
            ->and($store->unsubscribe('acme', 'u-2', ENDPOINT))->toBeFalse()
            ->and($store->unsubscribe('globex', 'u-1', ENDPOINT))->toBeFalse()
            ->and($store->find('acme', $held->id))->not->toBeNull();

        // The same browser subscribed again by whoever is signed in on it
        // now is theirs alone.
        $store->subscribe('acme', 'u-2', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());

        expect($store->forUser('acme', 'u-1'))->toBe([])
            ->and($store->forUser('acme', 'u-2'))->toHaveCount(1)
            ->and($store->unsubscribe('acme', 'u-2', ENDPOINT))->toBeTrue()
            ->and($store->forUser('acme', 'u-2'))->toBe([]);
    });

    it('refuse an endpoint at no named push service, over http, or keys that are not a browser\'s', function (string $endpoint, ?string $p256dh, ?string $auth) {
        $browser = new Browser();

        expect(fn () => new ArrayPushSubscriptions()->subscribe('acme', 'u-1', 'e1', $endpoint, $p256dh ?? $browser->p256dh(), $auth ?? $browser->authSecret()))
            ->toThrow(\InvalidArgumentException::class);
    })->with([
        'a host of the subscriber\'s choosing' => ['https://attacker.example/collect', null, null],
        'a look-alike host' => ['https://fcm.googleapis.com.attacker.example/x', null, null],
        'the metadata address' => ['https://169.254.169.254/latest', null, null],
        'plain http' => ['http://fcm.googleapis.com/fcm/send/abc', null, null],
        'a key off the curve' => [ENDPOINT, Signer::encode("\x04" . str_repeat("\x02", 64)), null],
        'a short auth secret' => [ENDPOINT, null, Signer::encode(random_bytes(8))],
    ]);

    it('show a push endpoint\'s origin alone in a dump', function () {
        $browser = new Browser();
        $held = new ArrayPushSubscriptions()->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());

        expect(print_r($held, true))->not->toContain('abc123')->not->toContain($browser->authSecret());
    });

    it('take a service the application names, and no other', function () {
        $services = new PushServices(['push.example']);

        expect($services->allows('https://eu.push.example/x'))->toBeTrue()
            ->and($services->allows('https://push.example/x'))->toBeTrue()
            ->and($services->allows('https://notpush.example/x'))->toBeFalse()
            ->and($services->allows(ENDPOINT))->toBeFalse();
    });
});

/** An application sending push over the jobs harness. */
final class Sender
{
    public Harness $h;
    public FakeOutboundHttp $http;
    public ArrayPushSubscriptions $subscriptions;
    public PushNotifications $push;

    /** @param (\Closure(OutboundRequest): OutboundResponse)|null $respond */
    public function __construct(?\Closure $respond = null, ?FakeOutboundHttp $http = null)
    {
        $this->h = new Harness();
        $this->http = $http ?? new FakeOutboundHttp($respond ?? static fn (): OutboundResponse => new OutboundResponse(201));
        $this->subscriptions = new ArrayPushSubscriptions();
        $this->h->container
            ->instance(OutboundHttp::class, $this->http)
            ->instance(PushSubscriptions::class, $this->subscriptions)
            ->instance(WebPush::class, new WebPush($this->http, keys(), clock: fn (): int => $this->h->now))
            ->instance(UserLookup::class, $this->h->users);
        // u-1 and u-2 are on the epoch the tests subscribe them at.
        $this->h->users->epochs = ['u-1' => 'e1', 'u-2' => 'e1'];
        $this->push = new PushNotifications($this->h->dispatcher, $this->subscriptions, $this->h->orm, $this->h->users);
    }

    public function work(): int
    {
        return $this->h->worker->run(new WorkerOptions(queues: [SendPush::QUEUE], stopWhenEmpty: true, signals: false));
    }

    public function queue(): ArrayQueue
    {
        \assert($this->h->queue instanceof ArrayQueue);

        return $this->h->queue;
    }
}

describe('sending', function () {
    it('encrypts each message for its browser, signs it for its service, and queues it as the tenant', function () {
        $s = new Sender();
        $browser = new Browser();
        $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $s->subscriptions->subscribe('globex', 'u-1', 'e1', 'https://updates.push.services.mozilla.com/wpush/v2/x', $browser->p256dh(), $browser->authSecret());

        $queued = $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), new ShippedPush('Shipped', 'A-100'), topic: 'order-A-100'));
        $payload = $s->queue()->pushed()[0]->payload;
        $s->work();
        $request = $s->http->sent[0];

        expect($queued)->toBe(1)
            ->and($payload)->toContain('"tenant":"acme"')
            ->and($payload)->not->toContain($browser->authSecret())
            ->and($s->http->sent)->toHaveCount(1)
            ->and($request->method)->toBe('POST')
            ->and($request->url)->toBe(ENDPOINT)
            ->and($request->maxRedirects)->toBe(0)
            ->and($request->readBody)->toBeFalse()
            ->and($request->headers['content-encoding'])->toBe('aes128gcm')
            ->and($request->headers['topic'])->toBe('order-A-100')
            ->and($request->headers['authorization'])->toStartWith('vapid t=')
            ->and($request->body)->not->toContain('Shipped')
            ->and(json_decode($browser->decrypt($request->body), true))->toBe(['title' => 'Shipped', 'order' => 'A-100']);
    });

    it('removes a subscription its service says is gone, and tries again what it says to', function () {
        $answers = [503, 201];
        $s = new Sender(static function () use (&$answers): OutboundResponse {
            return new OutboundResponse(array_shift($answers) ?? 410);
        });
        $browser = new Browser();
        $held = $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 1]));
        $s->work();
        $s->h->now += 30;
        $s->work();

        expect($s->http->sent)->toHaveCount(2)
            ->and($s->queue()->failed())->toBe([])
            ->and($s->subscriptions->find('acme', $held->id))->not->toBeNull();

        $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 2]));
        $s->work();

        expect($s->subscriptions->find('acme', $held->id))->toBeNull();
    });

    it('refuses a model, and a message too large, before anything is queued', function () {
        $s = new Sender();
        $browser = new Browser();
        $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());

        expect(fn () => $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['script' => new Script()])))->toThrow(DataBoundaryException::class)
            ->and(fn () => $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['x' => str_repeat('y', 4000)])))->toThrow(\InvalidArgumentException::class)
            ->and($s->queue()->pushed())->toBe([]);
    });

    it('sends nothing to an endpoint resolving inside the network, and forgets it', function () {
        $http = new FakeOutboundHttp(null, new OutboundPolicy(), new StaticResolver(['fcm.googleapis.com' => ['10.0.0.9']]));
        $s = new Sender(http: $http);
        $browser = new Browser();
        $held = $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 1]));
        $s->work();

        expect($http->sent)->toBe([])
            ->and($s->queue()->failed())->toHaveCount(1)
            ->and($s->subscriptions->find('acme', $held->id))->toBeNull();
    });
});

describe('the auth epoch (ADR 0124)', function () {
    it('queues nothing for a subscription made before the user\'s epoch moved, and removes it', function () {
        $s = new Sender();
        $browser = new Browser();
        $old = $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $phone = new Browser();
        $s->subscriptions->subscribe('acme', 'u-1', 'e2', 'https://updates.push.services.mozilla.com/wpush/v2/phone', $phone->p256dh(), $phone->authSecret());
        // Signed out, or signed out everywhere: the epoch moved on.
        $s->h->users->epochs['u-1'] = 'e2';

        $queued = $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 1]));
        $s->work();

        expect($queued)->toBe(1)
            ->and($s->http->sent)->toHaveCount(1)
            ->and($s->http->sent[0]->url)->toBe('https://updates.push.services.mozilla.com/wpush/v2/phone')
            ->and($s->subscriptions->find('acme', $old->id))->toBeNull();
    });

    // Queued at one epoch, sent at another: the epoch is read again when
    // the push is sent, so a sign-out between the two stops it.
    it('sends nothing when the epoch moves between queueing and sending', function () {
        $s = new Sender();
        $browser = new Browser();
        $held = $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 1]));
        $s->h->users->epochs['u-1'] = 'e2';
        $s->work();

        expect($s->http->sent)->toBe([])
            ->and($s->queue()->failed())->toBe([])
            ->and($s->subscriptions->find('acme', $held->id))->toBeNull();
    });

    it('sends nothing to a user who is gone', function () {
        $s = new Sender();
        $browser = new Browser();
        $held = $s->subscriptions->subscribe('acme', 'u-1', 'e1', ENDPOINT, $browser->p256dh(), $browser->authSecret());
        $s->h->orm->actAs('acme', fn (): int => $s->push->send(new User('u-1'), ['n' => 1]));
        unset($s->h->users->epochs['u-1']);
        $s->work();

        expect($s->http->sent)->toBe([])
            ->and($s->subscriptions->find('acme', $held->id))->toBeNull();
    });

    it('is kept with the subscription in the database', function () {
        $harness = new Harness();
        DatabasePushSubscriptions::define(schemaOf($harness->fixture->connection));
        $store = new DatabasePushSubscriptions($harness->fixture->connection);
        $browser = new Browser();
        $held = $store->subscribe('acme', 'u-1', 'epoch-7', ENDPOINT, $browser->p256dh(), $browser->authSecret());

        expect($store->find('acme', $held->id)?->epoch)->toBe('epoch-7')
            ->and($store->forUser('acme', 'u-1')[0]->epoch)->toBe('epoch-7');
    });
});
