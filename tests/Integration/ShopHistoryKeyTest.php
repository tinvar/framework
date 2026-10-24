<?php

declare(strict_types=1);

/**
 * The shop's history keys, through the whole path with the renderer: which
 * pages are handed one, how, and that a sign-out leaves the key behind with
 * the session it belonged to (ADR 0019). Skipped when the shop cannot be
 * built.
 */

namespace Tinvar\Tests\Integration\ShopHistoryKey;

use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;

const KEY = 'shop-history-key-test-key-32-bytes!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A browser of one, against the shop: its cookies, kept between requests. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(public readonly Kernel $kernel) {}

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = ['accept' => 'text/html']): Response
    {
        return $this->keep($this->kernel->handle(new Request('GET', $path, $headers, [], [], $this->cookies, null, '127.0.0.1')));
    }

    public function payload(string $path): Response
    {
        return $this->get($path, ['rsc' => '1', 'accept' => 'text/x-component', 'x-version' => 'dev']);
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), self::session(...))
            ->for(new Request('GET', '/', cookies: $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                '127.0.0.1',
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    /** The key the shop derives for this visitor's session now. */
    public function historyKey(): ?string
    {
        return new HistoryKeys(new Signer(KEY, HistoryKeys::PURPOSE), self::session(...))
            ->for(new Request('GET', '/', cookies: $this->cookies));
    }

    private static function session(Request $r): string
    {
        return Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '';
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/** @return array<string, mixed> the page metadata a document carries */
function metadata(Response $document): array
{
    preg_match('#<script type="application/json" id="tinvar-page">(.*?)</script>#s', $document->body(), $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

it('hands a signed-in customer\'s pages their session\'s key, and a signed-out visitor\'s none', function () {
    $renderer = Renderer::start('msgpack', 'history-key');
    $visitor = new Visitor(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database()));

    try {
        $signedOut = $visitor->get('/');
        $signedOutPayload = $visitor->payload('/');

        $visitor->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/']);
        $key = $visitor->historyKey();
        $document = $visitor->get('/');
        $payload = $visitor->payload('/orders');

        $visitor->action('account.signOut', []);
        $afterSignOut = $visitor->historyKey();
        $signedOutAgain = $visitor->payload('/');
    } finally {
        $renderer->stop();
    }

    expect($signedOut->status)->toBe(200)
        ->and(metadata($signedOut))->not->toHaveKey('historyKey')
        ->and($signedOutPayload->header(HistoryKeys::HEADER))->toBeNull();

    // In the document's metadata, which the router reads on a load, and in
    // the header of each payload, which it reads on a visit.
    expect($key)->toBeString()
        ->and(metadata($document)['historyKey'] ?? null)->toBe($key)
        ->and($document->header(HistoryKeys::HEADER))->toBeNull()
        ->and($payload->header(HistoryKeys::HEADER))->toBe($key)
        ->and($payload->header('cache-control'))->toBe('private, no-store');

    // Signing out gives the session a new id, and the key goes with the old
    // one: nothing sealed under it opens again.
    expect($afterSignOut)->not->toBe($key)
        ->and($signedOutAgain->header(HistoryKeys::HEADER))->toBeNull();
});
