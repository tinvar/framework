<?php

declare(strict_types=1);

/**
 * The shop's error pages, drawn by the real renderer: 403 and 404 inside the
 * shop's header, 419, 429 and 500 as system pages -- the mark, a reference
 * that is the log line's digest, and one way back to somewhere that works --
 * each with the shop's layout data resolved for whoever is signed in, even
 * where no route matched. And PHP's own shell when the renderer is gone.
 *
 * Skipped when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopErrorPages;

use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;

const KEY = 'shop-error-pages-test-key-32-bytes!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A browser of one, against the shop on $socket. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** @var list<string> */
    public array $logged = [];

    public readonly Kernel $kernel;

    public function __construct(string $socket)
    {
        $this->kernel = Kernel::fromEnvironment($socket, key: KEY, database: Shop::database(), log: function (\Throwable $e, string $digest): void {
            $this->logged[] = $digest;
        });
    }

    public function get(string $path): Response
    {
        return $this->keep($this->kernel->handle($this->document($path)));
    }

    public function errorPage(int $status): Response
    {
        return $this->kernel->errorPage($this->document('/somewhere'), $status);
    }

    public function signIn(): void
    {
        $this->get('/sign-in');
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        $this->keep($this->kernel->handle(
            new Request(
                'POST',
                '/_action/account.signIn',
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
            ),
            json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/'], JSON_THROW_ON_ERROR),
        ));
    }

    private function document(string $path): Request
    {
        return new Request('GET', $path, ['accept' => 'text/html'], [], [], $this->cookies);
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/** The document's text as a person reads it: no markup, and none of its payload rows. */
function shown(Response $response): string
{
    $body = preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $response->body()) ?? '';

    return html_entity_decode(strip_tags(str_replace('<', ' <', $body)), ENT_QUOTES | ENT_HTML5);
}

function served(\Closure $drive): void
{
    $renderer = Renderer::start('msgpack', 'error-pages');
    try {
        $drive($renderer->socketPath);
    } finally {
        $renderer->stop();
    }
}

it('answers a path nothing matches inside the header, for the customer signed in', function () {
    served(function (string $socket): void {
        $visitor = new Visitor($socket);
        $visitor->signIn();
        $response = $visitor->get('/no-such-shelf');
        $text = shown($response);

        expect($response->status)->toBe(404)
            ->and($text)->toContain('Nothing lives at this address')
            // The shop's layout ran for the visitor, though no route matched.
            ->and($text)->toContain(DemoAccount::NAME)
            ->and($text)->not->toContain('Sign in')
            ->and($text)->toContain($visitor->logged[0])
            ->and($response->body())->toContain('<title>Nothing lives at this address · Pellwick Supply</title>');
    });
});

it('offers a visitor who is not signed in the way to sign in, on a 404', function () {
    served(function (string $socket): void {
        $text = shown(new Visitor($socket)->get('/no-such-shelf'));

        expect($text)->toContain('Sign in')->and($text)->toContain('Browse the catalog');
    });
});

it('says whose order it is not, on a 403, naming the account signed in', function () {
    served(function (string $socket): void {
        $visitor = new Visitor($socket);
        $visitor->signIn();
        $other = (string) $visitor->kernel->orm->connection()->select(
            'SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email <> ? LIMIT 1',
            [DemoAccount::EMAIL],
        )[0]['id'];

        $response = $visitor->get("/orders/{$other}");
        $text = shown($response);

        expect($response->status)->toBe(403)
            ->and($text)->toContain('This order belongs to another account')
            ->and($text)->toContain('you are signed in as ' . DemoAccount::EMAIL)
            ->and($text)->toContain(DemoAccount::NAME);
    });
});

// MER-25: a system page carries the mark alone, a traceable identifier, and
// a route back to working ground.
it('draws 419, 429 and 500 as system pages: the mark, the reference, the way back', function (int $status, string $title) {
    served(function (string $socket) use ($status, $title): void {
        $visitor = new Visitor($socket);
        $response = $visitor->errorPage($status);
        $body = $response->body();
        $text = shown($response);

        expect($response->status)->toBe($status)
            ->and($text)->toContain($title)
            ->and($text)->toContain('Reference')
            ->and($body)->toContain('<title>' . $title . ' · Pellwick Supply</title>')
            // The mark, and none of the header's navigation.
            ->and($body)->toContain('Pellwick Supply')
            ->and($body)->not->toContain('<header')
            ->and($body)->not->toContain('Sign in');
    });
})->with([
    'page expired' => [419, 'This page was open too long'],
    'too many requests' => [429, 'Too many requests from this address'],
    'server error' => [500, 'Something failed on our side'],
]);

it('leads a signed-in customer back to their orders from a server error, and anyone else to the catalog', function () {
    served(function (string $socket): void {
        $visitor = new Visitor($socket);
        expect(shown($visitor->errorPage(500)))->toContain('Browse the catalog');

        $visitor->signIn();
        $signedIn = $visitor->errorPage(500);
        expect(shown($signedIn))->toContain('Go to your orders')
            ->and($signedIn->body())->toContain('href="/orders"');
    });
});

// "PHP renders an error page in its own shell when the renderer fails."
it('draws its own shell, with the mark and the reference, when the renderer is gone', function () {
    $visitor = new Visitor('/tmp/tinvar-error-pages-none.sock');
    $response = $visitor->get('/');
    $body = $response->body();

    expect($response->status)->toBe(500)
        ->and($body)->toContain('<title>500 · Server error · Pellwick Supply</title>')
        ->and($body)->toContain('<svg')
        ->and($body)->toContain("<code>{$visitor->logged[0]}</code>")
        ->and($body)->toContain('<a class="back" href="/">Browse the catalog</a>')
        ->and($body)->not->toContain('Cannot reach the renderer');
});
