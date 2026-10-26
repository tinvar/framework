<?php

declare(strict_types=1);

/**
 * The shop's history keys, through the whole path with the renderer: which
 * pages are handed one, how, and that a sign-out leaves the key behind with
 * the session it belonged to (ADR 0019). Skipped when the shop cannot be
 * built.
 */

namespace Tinvar\Tests\Integration\ShopHistoryKey;

use Shop\Auth\AuthEpochs;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\TenantClaim;
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

    /** The key the shop derives for this visitor's session now, signed in as the demo customer or no one. */
    public function historyKey(bool $signedIn = true): ?string
    {
        [$user, $epoch] = $signedIn ? demo() : ['', ''];

        return new HistoryKeys(new Signer(KEY, HistoryKeys::PURPOSE), self::session(...))
            ->for(new Request('GET', '/', cookies: $this->cookies), TenantClaim::current($this->kernel->orm), user: $user, epoch: $epoch);
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

/** @return array{string, string} the demo customer's id, and the auth epoch the shop keeps for them */
function demo(): array
{
    $pdo = new \PDO('sqlite:' . Shop::database());
    $id = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();
    $epoch = $pdo->query('SELECT epoch FROM auth_epochs WHERE customer_id = ' . $pdo->quote($id))?->fetchColumn();

    return [$id, (string) ($epoch === false || $epoch === null ? 0 : $epoch)];
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

        // A role changed, the session id kept: the shop moves the customer's
        // epoch, and the key moves with it.
        new AuthEpochs(new Orm(PdoConnection::sqlite(Shop::database(), 'shop'), new IdentityMap('shop')))->bump(demo()[0]);
        $promoted = $visitor->payload('/orders');

        $visitor->action('account.signOut', []);
        $afterSignOut = $visitor->historyKey(signedIn: false);
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

    // Moving the customer's auth epoch in the same session -- a role change,
    // an impersonation -- is another key: what was sealed under the old role
    // does not open under the new.
    expect($promoted->header(HistoryKeys::HEADER))->toBeString()
        ->and($promoted->header(HistoryKeys::HEADER))->not->toBe($key);

    // Signing out gives the session a new id, and the key goes with the old
    // one: nothing sealed under it opens again.
    expect($afterSignOut)->not->toBe($key)
        ->and($signedOutAgain->header(HistoryKeys::HEADER))->toBeNull();
});
