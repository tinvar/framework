<?php

declare(strict_types=1);

/**
 * The Mercure transport: the access tokens the hub checks, the cookie they
 * travel in, and the keys behind them.
 *
 * Checked against the hub itself by tests/Integration/ShopLiveTest.php, and
 * against one run apart from any app server by RemoteHubTest.php; here
 * the shape each part must have for the hub to accept it -- the protocol's
 * modern mode, which FrankenPHP 1.13 runs -- and what must never reach a
 * client.
 */

namespace Tinvar\Tests\Live\Mercure;

use Tinvar\Http\Response;
use Tinvar\Live\HubLink;
use Tinvar\Live\HubSettings;
use Tinvar\Live\HubUnavailable;
use Tinvar\Live\LiveBlock;
use Tinvar\Live\Mercure;
use Tinvar\Support\Signer;

const KEY = 'mercure-test-application-key-0123456789abc';
const SUBSCRIBER = 'subscriber-key-0123456789abcdef0123456789';
const PUBLISHER = 'publisher-key-0123456789abcdef01234567890';
const AUDIENCE = 'https://live.example.test/.well-known/mercure';

/** @return array{array<string, mixed>, array<string, mixed>, string} header, claims, and whether the MAC is the key's */
function jwt(string $token, string $key): array
{
    [$header, $claims, $mac] = explode('.', $token);

    return [
        json_decode((string) Signer::decode($header), true),
        json_decode((string) Signer::decode($claims), true),
        hash_equals(Signer::encode(hash_hmac('sha256', "{$header}.{$claims}", $key, true)), $mac) ? 'valid' : 'invalid',
    ];
}

describe('an access token', function () {
    it('is an at+jwt under HS256, with every claim the modern protocol requires, for exactly the topics given', function () {
        $token = Mercure::accessToken(SUBSCRIBER, AUDIENCE, 'session-hash', ['subscribe'], ['tinvar:a', 'tinvar:b'], 1_000_300, now: 1_000_000);
        [$header, $claims, $mac] = jwt($token, SUBSCRIBER);

        expect($header)->toBe(['alg' => 'HS256', 'typ' => 'at+jwt'])
            ->and($mac)->toBe('valid')
            ->and($claims)->toMatchArray([
                'iss' => Mercure::ISSUER,
                'aud' => AUDIENCE,
                'sub' => 'session-hash',
                'client_id' => Mercure::ISSUER,
                'iat' => 1_000_000,
                'exp' => 1_000_300,
                'authorization_details' => [[
                    'type' => Mercure::DETAIL,
                    'actions' => ['subscribe'],
                    'topics' => [['match' => 'tinvar:a'], ['match' => 'tinvar:b']],
                ]],
            ])
            ->and($claims['jti'])->toMatch('/^[0-9a-f]{32}$/');
    });
});

describe('a grant', function () {
    it('travels in an HttpOnly cookie for the hub\'s path alone, lasting as long as the grant', function () {
        $hub = Mercure::remote('http://hub.internal/.well-known/mercure', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static fn (): int => 200);

        $response = $hub->grant(Response::make(200), ['tinvar:a'], time() + 300, 'session-hash');
        $cookie = $response->cookies()[Mercure::COOKIE];

        expect($cookie)->toMatch('#^__Secure-tinvar-live=[A-Za-z0-9_.-]+; Path=/\.well-known/mercure; Secure; HttpOnly; SameSite=Strict; Max-Age=(299|300)$#');

        [, $claims, $mac] = jwt(explode(';', explode('=', $cookie, 2)[1])[0], SUBSCRIBER);
        expect($mac)->toBe('valid')
            ->and($claims['authorization_details'][0]['topics'])->toBe([['match' => 'tinvar:a']]);
    });

    // A browser keeps no cookie whose name and value pass 4,096 bytes: the
    // stream then opened with no grant, was refused, and the client traded
    // and opened again for good. 64 topics came to 4,122.
    it('fits every topic a trade may grant in the cookie a browser keeps, under a long audience', function () {
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, str_repeat('a', 256), post: static fn (): int => 200);
        $topics = array_map(static fn (int $i): string => 'tinvar:' . substr(str_repeat((string) $i, 22), 0, 22), range(1, \Tinvar\Live\LiveToken::MAX_TOPICS));

        $cookie = $hub->grant(Response::make(200), $topics, time() + 300, str_repeat('f', 32))->cookies()[Mercure::COOKIE];

        expect(\strlen(explode(';', $cookie)[0]))->toBeLessThanOrEqual(4096);
    });

    it('is taken away by an emptied cookie', function () {
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static fn (): int => 200);

        expect($hub->revoke(Response::make(200))->cookies()[Mercure::COOKIE])
            ->toBe('__Secure-tinvar-live=; Path=/.well-known/mercure; Secure; HttpOnly; SameSite=Strict; Max-Age=0');
    });

    it('opens on a same-origin URL naming each topic, and nothing secret', function () {
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static fn (): int => 200);

        expect($hub->subscribeUrl(['tinvar:a', 'tinvar:b']))->toBe('/.well-known/mercure?match=tinvar%3Aa&match=tinvar%3Ab');
    });
});

describe('a hub of its own', function () {
    it('is published to privately, with a token that may publish to that one topic only', function () {
        $sent = [];
        $hub = Mercure::remote('http://hub.internal/.well-known/mercure', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function (string $url, array $headers, string $body) use (&$sent): int {
            $sent[] = [$url, $headers, $body];

            return 200;
        });

        $hub->publish('tinvar:a', '{"tag":"orders"}');

        [$url, $headers, $body] = $sent[0];
        parse_str($body, $form);
        [, $claims, $mac] = jwt(substr($headers['authorization'], \strlen('Bearer ')), PUBLISHER);

        expect($url)->toBe('http://hub.internal/.well-known/mercure')
            ->and($form)->toBe(['topic' => 'tinvar:a', 'data' => '{"tag":"orders"}', 'private' => 'on'])
            ->and($mac)->toBe('valid')
            ->and($claims['authorization_details'][0])->toBe([
                'type' => Mercure::DETAIL,
                'actions' => ['publish'],
                'topics' => [['match' => 'tinvar:a']],
            ])
            ->and($claims['exp'] - $claims['iat'])->toBe(60);
    });

    it('says so when the hub refuses an update', function () {
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static fn (): int => 403);

        $hub->publish('tinvar:a', '{}');
    })->throws(\RuntimeException::class, 'refused an update with 403');

    // A publish runs after the commit, and the write's response waits on
    // it: a hub that is down is asked once, then left alone for a while,
    // each update meanwhile skipped at once -- and said so, to be logged.
    it('leaves a hub that could not be reached alone for a while, skipping each update at once', function () {
        $now = 1_000.0;
        $posts = 0;
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function () use (&$posts, &$now): int {
            $posts++;
            $now += 1.0;

            throw new \RuntimeException('connection timed out');
        }, clock: static function () use (&$now): float {
            return $now;
        });

        $failures = [];
        foreach (['tinvar:a', 'tinvar:b', 'tinvar:c'] as $topic) {
            try {
                $hub->publish($topic, '{}');
            } catch (HubUnavailable $e) {
                $failures[] = $e->getMessage();
            }
        }

        expect($posts)->toBe(1)
            ->and($failures)->toHaveCount(3)
            ->and($failures[0])->toContain('could not be reached: connection timed out')
            ->and($failures[1])->toContain('taken for down');

        // Asked again once the cooldown is over.
        $now += HubLink::COOLDOWN;
        $hub->endRequest();
        expect(fn () => $hub->publish('tinvar:d', '{}'))->toThrow(HubUnavailable::class, 'could not be reached')
            ->and($posts)->toBe(2);
    });

    it('takes a hub that failed with a 5xx for down, and one that refused with a 4xx for up', function () {
        $status = 503;
        $posts = 0;
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function () use (&$posts, &$status): int {
            $posts++;

            return $status;
        });

        expect(fn () => $hub->publish('tinvar:a', '{}'))->toThrow(HubUnavailable::class, 'failed an update with 503')
            ->and(fn () => $hub->publish('tinvar:b', '{}'))->toThrow(HubUnavailable::class, 'taken for down')
            ->and($posts)->toBe(1);

        $refusing = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function () use (&$posts): int {
            $posts++;

            return 401;
        });
        expect(fn () => $refusing->publish('tinvar:a', '{}'))->toThrow(\RuntimeException::class, 'refused an update with 401')
            ->and(fn () => $refusing->publish('tinvar:b', '{}'))->toThrow(\RuntimeException::class, 'refused an update with 401')
            ->and($posts)->toBe(3);
    });

    // A slow hub that still answers: one request's publishes stop at its
    // deadline, and the next request has a whole one.
    it('spends at most its deadline publishing in one request, each POST given what is left of it', function () {
        $now = 1_000.0;
        $timeouts = [];
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function (string $url, array $headers, string $body, float $timeout) use (&$now, &$timeouts): int {
            $timeouts[] = $timeout;
            $now += 1.25;

            return 200;
        }, clock: static function () use (&$now): float {
            return $now;
        });

        $sent = 0;
        $skipped = [];
        foreach (['tinvar:a', 'tinvar:b', 'tinvar:c', 'tinvar:d'] as $topic) {
            try {
                $hub->publish($topic, '{}');
                $sent++;
            } catch (HubUnavailable $e) {
                $skipped[] = $e->getMessage();
            }
        }

        expect($sent)->toBe(3)
            ->and($timeouts)->toBe([HubLink::TIMEOUT, HubLink::REQUEST_BUDGET - 1.25, HubLink::REQUEST_BUDGET - 2.5])
            ->and($skipped)->toHaveCount(1)
            ->and($skipped[0])->toContain('has spent the 3.0s it may spend publishing');

        $hub->endRequest();
        $hub->publish('tinvar:e', '{}');
        expect($timeouts)->toHaveCount(4);
    });

    // A process that ends no request -- a queue worker -- has its budget
    // renewed by the clock, or one slow job would silence every later one.
    it('renews the deadline of a process that ends no request, once its first publish is a window old', function () {
        $now = 1_000.0;
        $hub = Mercure::remote('http://hub.internal/', PUBLISHER, SUBSCRIBER, AUDIENCE, post: static function () use (&$now): int {
            $now += 1.5;

            return 200;
        }, clock: static function () use (&$now): float {
            return $now;
        });

        $hub->publish('tinvar:a', '{}');
        $hub->publish('tinvar:b', '{}');
        expect(fn () => $hub->publish('tinvar:c', '{}'))->toThrow(HubUnavailable::class, 'has spent');

        $now += HubLink::WINDOW;
        $hub->publish('tinvar:d', '{}');
    });

    it('gives up connecting to a hub that does not answer within about a second', function () {
        if (!\extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl is not loaded.');
        }
        // An address nothing answers on: the connection is neither refused
        // nor accepted, and waits out the connect timeout.
        $hub = Mercure::remote('http://10.255.255.1/.well-known/mercure', PUBLISHER, SUBSCRIBER, AUDIENCE);

        $started = microtime(true);
        expect(fn () => $hub->publish('tinvar:a', '{}'))->toThrow(HubUnavailable::class);
        expect(microtime(true) - $started)->toBeLessThan(HubLink::CONNECT_TIMEOUT + 0.5);
    });

    it('refuses keys too short to sign with', function () {
        Mercure::remote('http://hub.internal/', 'short', SUBSCRIBER, AUDIENCE);
    })->throws(\InvalidArgumentException::class);
});

it('is FrankenPHP\'s own only where mercure_publish() is defined', function () {
    if (\function_exists('mercure_publish')) {
        $this->markTestSkipped('Running under FrankenPHP.');
    }

    Mercure::builtIn(SUBSCRIBER, AUDIENCE);
})->throws(\LogicException::class, 'mercure_publish()');

describe('the hub\'s settings', function () {
    it('are derived from the application key, one key per purpose, neither of them the key itself', function () {
        $settings = HubSettings::derive(KEY);

        expect($settings->subscriberKey)->toMatch('/^[0-9a-f]{64}$/')
            ->and($settings->publisherKey)->toMatch('/^[0-9a-f]{64}$/')
            ->and($settings->subscriberKey)->not->toBe($settings->publisherKey)
            ->and($settings->subscriberKey)->not->toContain(KEY)
            ->and(HubSettings::derive(KEY)->subscriberKey)->toBe($settings->subscriberKey)
            ->and(HubSettings::derive(KEY . 'x')->subscriberKey)->not->toBe($settings->subscriberKey)
            ->and($settings->audience)->toBe(HubSettings::DEFAULT_AUDIENCE);
    });

    it('come from the environment where it says, as the hub was configured', function () {
        putenv(HubSettings::SUBSCRIBER_ENV . '=from-the-environment-0123456789abcdef');
        putenv(HubSettings::AUDIENCE_ENV . '=' . AUDIENCE);
        try {
            $settings = HubSettings::resolve(KEY);
        } finally {
            putenv(HubSettings::SUBSCRIBER_ENV);
            putenv(HubSettings::AUDIENCE_ENV);
        }

        expect($settings->subscriberKey)->toBe('from-the-environment-0123456789abcdef')
            ->and($settings->publisherKey)->toBe(HubSettings::derive(KEY)->publisherKey)
            ->and($settings->audience)->toBe(AUDIENCE)
            ->and($settings->environment())->toBe([
                HubSettings::SUBSCRIBER_ENV => 'from-the-environment-0123456789abcdef',
                HubSettings::PUBLISHER_ENV => HubSettings::derive(KEY)->publisherKey,
                HubSettings::AUDIENCE_ENV => AUDIENCE,
                // The built-in hub's: nothing for the Caddyfile to pass on to.
                HubSettings::HUB_UPSTREAM_ENV => '',
            ]);
    });
});

describe('a hub of its own', function () {
    it('is named by TINVAR_LIVE_HUB_URL, and the app server is told its origin to pass subscribers on to', function () {
        putenv(HubSettings::HUB_URL_ENV . '=https://hub.internal.example:8443/.well-known/mercure');
        try {
            $settings = HubSettings::resolve(KEY);
        } finally {
            putenv(HubSettings::HUB_URL_ENV);
        }

        expect($settings->hubUrl)->toBe('https://hub.internal.example:8443/.well-known/mercure')
            ->and($settings->environment()[HubSettings::HUB_UPSTREAM_ENV])->toBe('https://hub.internal.example:8443')
            // Its keys are the same ones, and still never in what a client gets.
            ->and($settings->publisherKey)->toBe(HubSettings::derive(KEY)->publisherKey);
    });

    it('is published to over HTTP from every process, ahead of the built-in hub or the same server', function () {
        $settings = new HubSettings(SUBSCRIBER, PUBLISHER, AUDIENCE, 'https://hub.example.com/.well-known/mercure');
        $sent = [];
        $post = static function (string $url, array $headers) use (&$sent): int {
            $sent[] = [$url, jwt(substr($headers['authorization'], \strlen('Bearer ')), PUBLISHER)[2]];

            return 200;
        };

        $settings->hub('http://127.0.0.1:8080/.well-known/mercure', $post)?->publish('tinvar:x', '{}');

        expect($sent)->toBe([['https://hub.example.com/.well-known/mercure', 'valid']]);
    });

    it('leaves a process outside FrankenPHP the same server\'s hub, over HTTP, or none', function () {
        if (\function_exists('mercure_publish')) {
            $this->markTestSkipped('Running under FrankenPHP.');
        }
        $settings = new HubSettings(SUBSCRIBER, PUBLISHER, AUDIENCE);
        $sent = [];
        $post = static function (string $url) use (&$sent): int {
            $sent[] = $url;

            return 200;
        };

        $settings->hub('http://127.0.0.1:8080/.well-known/mercure', $post)?->publish('tinvar:x', '{}');

        expect($settings->hub())->toBeNull()
            ->and($sent)->toBe(['http://127.0.0.1:8080/.well-known/mercure']);
    });

    it('is reached over https anywhere, and over plain HTTP on this machine alone', function (string $url, string $upstream) {
        expect(HubSettings::upstreamOf($url))->toBe($upstream);
    })->with([
        ['https://hub.example.com/.well-known/mercure', 'https://hub.example.com'],
        ['HTTPS://Hub.Example.com:444/.well-known/mercure', 'https://hub.example.com:444'],
        ['http://127.0.0.1:3000/.well-known/mercure', 'http://127.0.0.1:3000'],
        ['http://127.0.0.2:3000/.well-known/mercure', 'http://127.0.0.2:3000'],
        ['http://[::1]:3000/.well-known/mercure', 'http://[::1]:3000'],
        ['http://localhost:3000/.well-known/mercure', 'http://localhost:3000'],
    ]);

    // Refused when the application starts, not on the first publish: a
    // hub over plain HTTP elsewhere sends every publisher token and every
    // access cookie across the network in the clear.
    it('is refused at any other URL', function (string $url, string $message) {
        expect(static fn () => new HubSettings(SUBSCRIBER, PUBLISHER, AUDIENCE, $url))
            ->toThrow(\InvalidArgumentException::class, $message);
    })->with([
        'plain HTTP to another machine' => ['http://hub.internal/.well-known/mercure', 'plain HTTP'],
        'plain HTTP to a private address' => ['http://10.0.0.5:3000/.well-known/mercure', 'plain HTTP'],
        'a name that only starts like loopback' => ['http://127.evil.example/.well-known/mercure', 'plain HTTP'],
        'another scheme' => ['ftp://hub.example.com/.well-known/mercure', HubSettings::HUB_URL_ENV],
        'credentials' => ['https://user:pass@hub.example.com/.well-known/mercure', HubSettings::HUB_URL_ENV],
        'a query' => ['https://hub.example.com/.well-known/mercure?topic=x', HubSettings::HUB_URL_ENV],
        'a fragment' => ['https://hub.example.com/.well-known/mercure#x', HubSettings::HUB_URL_ENV],
        'another path, which the proxy would not reach' => ['https://hub.example.com/hub', HubSettings::HUB_URL_ENV],
        'no host' => ['https:///.well-known/mercure', HubSettings::HUB_URL_ENV],
        'not a URL' => ['hub.example.com', HubSettings::HUB_URL_ENV],
    ]);
});

describe('a document\'s token', function () {
    it('goes at the end of the body, escaped so nothing in it closes the block', function () {
        $html = '<!doctype html><html><body><main>page</main></body></html>';

        expect(LiveBlock::into($html, 'v1.abc.def'))
            ->toBe('<!doctype html><html><body><main>page</main><script type="application/json" id="tinvar-live">{"token":"v1.abc.def"}</script></body></html>')
            ->and(LiveBlock::tag('</script><script>alert(1)</script>'))->not->toContain('</script><script>')
            ->and(LiveBlock::into('<p>no body</p>', null))->toBe('<p>no body</p><script type="application/json" id="tinvar-live">{"token":null}</script>');
    });
});

describe('a cookie for one path', function () {
    it('must be __Secure-, and carry nothing that would end it early', function (string $name, string $value, string $path) {
        Response::make(200)->withPathCookie($name, $value, $path);
    })->throws(\InvalidArgumentException::class)->with([
        'no prefix' => ['live', 'v', '/x'],
        'a __Host- prefix, which needs Path=/' => ['__Host-live', 'v', '/x'],
        'a semicolon in the value' => ['__Secure-live', 'v; Domain=evil', '/x'],
        'a relative path' => ['__Secure-live', 'v', 'x'],
        'a semicolon in the path' => ['__Secure-live', 'v', '/x; Domain=evil'],
    ]);
});
