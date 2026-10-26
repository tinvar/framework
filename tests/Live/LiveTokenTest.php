<?php

declare(strict_types=1);

/**
 * Live subscription tokens: what a render read, as topics, under the binding
 * every token carries.
 *
 * tinvar-spec.md: "The panel and effective scope are part of query cache
 * keys, fragment and route tokens, live subscription tokens and service
 * context tokens." The topics are those of the tags the render actually read
 * -- its loaders' and its queries' -- and nothing else.
 */

namespace Tinvar\Tests\Live\LiveToken;

use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveToken;
use Tinvar\Live\TagsRead;
use Tinvar\Live\Topics;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\RouteToken;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

const KEY = 'live-token-test-application-key-0123456789';

function signer(string $key = KEY): Signer
{
    return new Signer($key, LiveToken::PURPOSE);
}

function topics(string $key = KEY): Topics
{
    return new Topics(new Signer($key, Topics::PURPOSE));
}

function orm(): Orm
{
    return new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
}

/** @param array<string, string> $params */
function minter(?TagsRead $loaders = null, string $session = 'session-a', ?string $user = 'u-1', string $epoch = 'e1', array $params = []): LiveMinter
{
    return new LiveMinter(
        signer: signer(),
        topics: topics(),
        route: 'orders.index',
        params: $params,
        panel: 'admin',
        tenant: 't-1',
        build: 'b1',
        sessionId: $session,
        user: $user,
        authEpoch: $epoch,
        loaders: $loaders,
    );
}

describe('topics', function () {
    it('are opaque, the same for the same tag and tenant, and another for another tenant', function () {
        $topic = topics()->for('t-1', 'orders:customer:42');

        expect($topic)->toMatch(Topics::SHAPE)
            ->and($topic)->not->toContain('orders')
            ->and($topic)->not->toContain('42')
            ->and(topics()->for('t-1', 'orders:customer:42'))->toBe($topic)
            ->and(topics()->for('t-2', 'orders:customer:42'))->not->toBe($topic)
            ->and(topics()->for('t-1', 'orders:customer:43'))->not->toBe($topic)
            // A tag already namespaced, as a render's tags are, is the same topic.
            ->and(topics()->of("t-1\0orders:customer:42"))->toBe($topic);
    });

    it('cannot be worked out without the application key', function () {
        expect(topics('another-application-key-0123456789abcdef')->for('t-1', 'orders'))
            ->not->toBe(topics()->for('t-1', 'orders'));
    });
});

describe('what loaders read', function () {
    it('is kept by the segment whose loader read it, in the tenant it ran as', function () {
        $orm = orm();
        $tags = new TagsRead($orm);

        $tags->within('shop', fn () => $tags->read('categories'));
        $tags->within('page', fn () => $tags->read('orders', 'categories'));
        $tags->read('outside');

        expect($tags->all())->toBe([
            "\0categories" => ['shop', 'page'],
            "\0orders" => ['page'],
            "\0outside" => [TagsRead::WHOLE],
        ]);

        $tags->forget();
        expect($tags->all())->toBe([]);
    });

    it('restores the segment around it when a loader throws', function () {
        $tags = new TagsRead(orm());

        try {
            $tags->within('page', function () {
                throw new \RuntimeException('loader failed');
            });
        } catch (\RuntimeException) {
        }
        $tags->read('after');

        expect($tags->all())->toBe(["\0after" => [TagsRead::WHOLE]]);
    });
});

describe('minting', function () {
    it('names the topics of every tag the render read, with the segments that read each', function () {
        $loaders = new TagsRead(orm());
        $loaders->within('page', fn () => $loaders->read('orders:customer:42'));
        $loaders->within('shop', fn () => $loaders->read('categories'));

        $token = minter($loaders)->mint(["t-1\0availability:7"]);
        $live = LiveToken::decode((string) $token, signer());

        expect($live->topics)->toBe(sortedTopics([
            topics()->of("\0orders:customer:42") => ['page'],
            topics()->of("\0categories") => ['shop'],
            topics()->of("t-1\0availability:7") => [TagsRead::WHOLE],
        ]));
    });

    it('makes a topic the whole page\'s when the render as a whole read it as well', function () {
        $loaders = new TagsRead(orm());
        $loaders->within('page', fn () => $loaders->read('orders'));

        $live = LiveToken::decode((string) minter($loaders)->mint(["\0orders"]), signer());

        expect($live->topics)->toBe([topics()->of("\0orders") => [TagsRead::WHOLE]]);
    });

    it('binds the session, the user and epoch, the tenant, the panel and the build', function () {
        $claims = LiveToken::decode((string) minter()->mint(["\0orders"]), signer())->claims;

        expect($claims->sessionHash)->toBe(TokenClaims::hashSession(signer(), 'session-a'))
            ->and($claims->authHash)->toBe(TokenClaims::hashAuth(signer(), 'u-1', 'e1'))
            ->and($claims->tenant)->toBe('t-1')
            ->and($claims->panel)->toBe('admin')
            ->and($claims->build)->toBe('b1')
            ->and($claims->route)->toBe('orders.index')
            ->and($claims->expiresAt - $claims->issuedAt)->toBe(LiveToken::DEFAULT_TTL);
    });

    it('mints nothing for a render that read nothing', function () {
        expect(minter()->mint([]))->toBeNull()
            ->and(minter(new TagsRead(orm()))->mint([]))->toBeNull();
    });

    it('mints nothing past the cap, rather than a token for some of what was read', function () {
        $read = array_map(static fn (int $i): string => "\0tag-{$i}", range(1, LiveToken::MAX_TOPICS + 1));

        expect(minter()->mint($read))->toBeNull()
            ->and(minter()->mint(\array_slice($read, 0, LiveToken::MAX_TOPICS)))->toBeString();
    });

    // A route whose loader takes any text took a path segment that is not,
    // and the page was a 500 for it. No token is a page that does not listen.
    it('mints nothing for a page whose params are not valid UTF-8', function () {
        expect(minter(params: ['q' => "\xFF"])->mint(["\0orders"]))->toBeNull()
            ->and(minter(params: ['q' => 'bolts'])->mint(["\0orders"]))->toBeString();
    });

    it('refuses to mint without a session', function () {
        minter(session: '');
    })->throws(\InvalidArgumentException::class);
});

describe('verifying', function () {
    it('refuses a route token, signed under its own purpose, as a live token', function () {
        $claims = new TokenClaims('orders.index', [], '', '', 'b1', 'h', time() + 60, time());
        $route = new RouteToken($claims)->encode(new Signer(KEY, RouteToken::PURPOSE));

        expect(fn () => LiveToken::decode($route, signer()))->toThrow(TokenError::class);
    });

    it('refuses one signed with the live key under another purpose, whose payload is otherwise right', function () {
        $now = time();
        $payload = [
            ...new TokenClaims('orders.index', [], '', '', 'b1', 'h', $now + 60, $now)->toMap(),
            't' => [topics()->of("\0orders") => ['page']],
        ];

        expect(fn () => LiveToken::decode(SignedToken::encode(signer(), RouteToken::PURPOSE, $payload), signer()))
            ->toThrow(TokenError::class);
    });

    it('refuses one past its expiry, and refreshes one only up to its absolute lifetime', function () {
        $token = (string) minter()->mint(["\0orders"]);
        $live = LiveToken::decode($token, signer());

        expect(fn () => LiveToken::decode($token, signer(), $live->claims->expiresAt))->toThrow(TokenError::class);

        $later = $live->claims->issuedAt + LiveToken::MAX_LIFETIME - 10;
        expect($live->refreshed($later)?->claims->expiresAt)->toBe($live->claims->issuedAt + LiveToken::MAX_LIFETIME)
            ->and($live->refreshed($live->claims->issuedAt + LiveToken::MAX_LIFETIME))->toBeNull();
    });

    it('refuses topics that are not topics, even under a good signature', function () {
        $now = time();
        foreach ([
            'none' => [],
            'a list' => [topics()->of("\0orders")],
            'a tag' => ['orders' => ['page']],
            'no segments' => [topics()->of("\0orders") => []],
            'a bad segment' => [topics()->of("\0orders") => ['<script>']],
        ] as $case => $t) {
            $payload = [...new TokenClaims('r', [], '', '', 'b1', 'h', $now + 60, $now)->toMap(), 't' => $t];

            expect(fn () => LiveToken::decode(SignedToken::encode(signer(), LiveToken::PURPOSE, $payload), signer()))
                ->toThrow(TokenError::class, null, $case);
        }
    });
});

/**
 * @param array<string, list<string>> $topics
 * @return array<string, list<string>>
 */
function sortedTopics(array $topics): array
{
    ksort($topics);

    return $topics;
}
