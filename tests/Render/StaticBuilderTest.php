<?php

declare(strict_types=1);

/**
 * `tinvar build:static`: every #[StaticPage] rendered as nobody -- no
 * cookies, no session -- to a file, its <Deferred> boundaries recorded for
 * /_bootstrap instead of minted, and the document refused if anything in it
 * belongs to one visitor.
 */

namespace Tinvar\Tests\Render\StaticBuilder {
    use Tinvar\Http\Request;
    use Tinvar\Render\FragmentManifest;
    use Tinvar\Render\StaticBuilder;
    use Tinvar\Render\StaticMinter;
    use Tinvar\Routing\RouteRegistry;
    use Tinvar\Attributes\StaticPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\ClimbingListedPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\DocPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\Listed;
    use Tinvar\Tests\Render\StaticBuilder\Pages\ListedPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\MislistedPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\EncryptedPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\HostedPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\HomePage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\PathlessPage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\PrivatePage;
    use Tinvar\Tests\Render\StaticBuilder\Pages\UnnamedPage;

    /** A directory removed when the test process ends. */
    function scratch(): string
    {
        $directory = sys_get_temp_dir() . '/tinvar-static-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $owner = getmypid();
        register_shutdown_function(static function () use ($directory, $owner): void {
            if (getmypid() === $owner) {
                exec('rm -rf ' . escapeshellarg($directory));
            }
        });

        return $directory;
    }

    /** @param list<class-string> $pages */
    function routes(array $pages = [HomePage::class, DocPage::class, PrivatePage::class]): RouteRegistry
    {
        $routes = new RouteRegistry();
        $routes->registerAll($pages);

        return $routes;
    }

    /** A document as the renderer writes one, with the metadata a page render puts first. */
    function document(string $body, ?string $routeToken = null, ?string $historyKey = null): string
    {
        $metadata = ['url' => '/', 'version' => 'b1', 'routeToken' => $routeToken, 'view' => 'v'];
        if ($historyKey !== null) {
            $metadata['historyKey'] = $historyKey;
        }

        return '<!DOCTYPE html><script type="application/json" id="tinvar-page">'
            . str_replace('/', '\/', json_encode($metadata, JSON_THROW_ON_ERROR))
            . "</script><html><head></head><body>{$body}</body></html>";
    }

    /**
     * A renderer that mints a boundary on the doc pages, keeping each request
     * it was handed.
     *
     * @param \ArrayObject<int, Request> $requests
     */
    function renderer(\ArrayObject $requests, string $body = 'page'): \Closure
    {
        return static function (Request $request, \Closure $mint) use ($requests, $body): string {
            $requests[] = $request;
            $placeholder = str_starts_with($request->path, '/docs/') ? $mint('Toc', ['depth' => 2]) : '';

            return document("{$body} {$request->path} {$placeholder}");
        };
    }

    /** The manifest the renderer build gives these routes: the doc pages render a Toc. */
    function docs(): FragmentManifest
    {
        return new FragmentManifest()->declareRoute('static.doc', ['Toc']);
    }

    describe('the pages to build', function () {
        it('lists each #[StaticPage] route once per set of params', function () {
            expect(new StaticBuilder(routes(), static fn (): string => '')->pages())->toEqualCanonicalizing([
                ['route' => 'static.home', 'params' => [], 'path' => '/home', 'revalidate' => null, 'tags' => []],
                ['route' => 'static.doc', 'params' => ['doc' => 'intro'], 'path' => '/docs/intro', 'revalidate' => 600, 'tags' => ['docs']],
                ['route' => 'static.doc', 'params' => ['doc' => 'guide-2'], 'path' => '/docs/guide-2', 'revalidate' => 600, 'tags' => ['docs']],
            ]);
        });

        // A directory of content: the pages are whatever its files are, listed
        // by a class the builder asks, and held to the same shape.
        it('lists the pages a StaticParams class names', function () {
            expect(new StaticBuilder(routes([ListedPage::class]), static fn (): string => '')->pages())->toEqual([
                ['route' => 'static.listed', 'params' => ['page' => 'routing'], 'path' => '/listed/routing', 'revalidate' => null, 'tags' => []],
                ['route' => 'static.listed', 'params' => ['page' => 'queries'], 'path' => '/listed/queries', 'revalidate' => null, 'tags' => []],
            ]);
        });

        it('holds params a class lists to the shape of a file name', function () {
            expect(fn () => new StaticBuilder(routes([ClimbingListedPage::class]), static fn (): string => '')->pages())
                ->toThrow(\RuntimeException::class, 'cannot name a built page');
        });

        it('refuses params from a class that is no StaticParams', function () {
            expect(fn () => new StaticBuilder(routes([MislistedPage::class]), static fn (): string => '')->pages())
                ->toThrow(\RuntimeException::class, 'which is no Tinvar\\Render\\StaticParams');
        });

        it('refuses a static page that lists params and names a class too', function () {
            expect(fn () => new StaticPage(params: [['page' => 'a']], from: Listed::class))
                ->toThrow(\InvalidArgumentException::class, 'not both');
        });

        // /_bootstrap is asked by route name.
        it('refuses a static page with no route name', function () {
            expect(fn () => new StaticBuilder(routes([UnnamedPage::class]), static fn (): string => '')->pages())
                ->toThrow(\RuntimeException::class, 'no route name');
        });

        // Its key is the session's.
        it('refuses a page that asks for its history encrypted', function () {
            expect(fn () => new StaticBuilder(routes([EncryptedPage::class]), static fn (): string => '')->pages())
                ->toThrow(\RuntimeException::class, 'history encrypted');
        });

        // The file is found by the path alone, and served whatever the host
        // (ADR 0149): a page held to a host of its own would be sent on every
        // other, so registration refuses it.
        it('refuses a static page held to a host of its own', function () {
            expect(fn () => routes([HostedPage::class]))
                ->toThrow(\Tinvar\Routing\RoutingException::class, 'primary host');
        });

        // The file is found by the path the browser asked for: an escaped or
        // climbing param has no file a request could name.
        it('refuses a param that cannot name a file', function () {
            expect(fn () => new StaticBuilder(routes([PathlessPage::class]), static fn (): string => '')->pages())
                ->toThrow(\RuntimeException::class, 'cannot name a built page');
        });
    });

    describe('building', function () {
        it('renders each page as a visitor with no session, to <path>/index.html', function () {
            $out = scratch() . '/static';
            $requests = new \ArrayObject();

            $built = new StaticBuilder(routes(), renderer($requests))->build($out, docs());

            expect($built)->toEqualCanonicalizing(['/home', '/docs/intro', '/docs/guide-2'])
                ->and(file_get_contents("{$out}/home/index.html"))->toContain('page /home')
                ->and(is_file("{$out}/docs/guide-2/index.html"))->toBeTrue()
                ->and(is_file("{$out}/private/index.html"))->toBeFalse('only #[StaticPage] routes');
            foreach ($requests as $request) {
                expect($request->method)->toBe('GET')
                    ->and($request->cookies)->toBe([], 'no session to mint for, no user to load')
                    ->and($request->remoteAddress)->toBeNull();
            }
        });

        // Where the application routes by host, a request naming none is
        // refused; a built page is the primary host's (ADR 0149).
        it('renders each page on the primary host', function () {
            $requests = new \ArrayObject();
            $routes = routes();
            $routes->serveOn(\Tinvar\Routing\Domains::fromUrl('https://shop.test:8443'));

            new StaticBuilder($routes, renderer($requests))->build(scratch() . '/static', docs());

            expect($requests)->not->toBeEmpty();
            foreach ($requests as $request) {
                expect($request->header('host'))->toBe('shop.test:8443');
            }
        });

        // The page carries a placeholder, and the manifest what /_bootstrap
        // will mint for it -- for the visitor's own session.
        it('records each boundary for /_bootstrap and puts a placeholder in the page', function () {
            $out = scratch() . '/static';
            $manifest = docs();

            new StaticBuilder(routes(), renderer(new \ArrayObject()))->build($out, $manifest);

            $id = StaticMinter::idOf('Toc', ['depth' => 2]);
            expect($manifest->boundariesFor('static.doc', ['doc' => 'intro']))
                ->toBe([['id' => $id, 'component' => 'Toc', 'props' => ['depth' => 2]]])
                ->and($manifest->hasAny())->toBeTrue('so the Kernel mounts /_bootstrap');

            preg_match('/b1\.[A-Za-z0-9_-]+/', (string) file_get_contents("{$out}/docs/intro/index.html"), $m);
            expect(json_decode((string) base64_decode(strtr(substr($m[0], 3), '-_', '+/')), true))
                ->toBe(['r' => 'static.doc', 'p' => ['doc' => 'intro'], 'b' => $id]);
        });

        it('replaces the pages a previous build left', function () {
            $out = scratch() . '/static';
            mkdir("{$out}/gone", 0o755, true);
            file_put_contents("{$out}/gone/index.html", 'stale');

            new StaticBuilder(routes(), renderer(new \ArrayObject()))->build($out, docs());

            expect(is_dir("{$out}/gone"))->toBeFalse()
                ->and(glob(\dirname($out) . '/*'))->toBe([$out], 'nothing left beside it');
        });

        // The manifest is published before the pages go live: a new page
        // served before its boundaries are declared bootstraps none of them.
        it('publishes the manifest once every page is built and before any goes live', function () {
            $out = scratch() . '/static';
            mkdir("{$out}/gone", 0o755, true);
            file_put_contents("{$out}/gone/index.html", 'stale');
            $seen = null;
            $manifest = docs();

            new StaticBuilder(routes(), renderer(new \ArrayObject()))->build($out, $manifest, static function (FragmentManifest $built) use ($out, $manifest, &$seen): void {
                $seen = [
                    'old pages still live' => is_file("{$out}/gone/index.html"),
                    'new pages not yet' => !is_file("{$out}/docs/intro/index.html"),
                    'boundaries declared' => $built === $manifest && $built->hasAny(),
                ];
            });

            expect($seen)->toBe(['old pages still live' => true, 'new pages not yet' => true, 'boundaries declared' => true])
                ->and(is_file("{$out}/docs/intro/index.html"))->toBeTrue();
        });

        // A page that fails leaves the previous build serving, whole.
        it('leaves the previous pages as they were when a page fails', function () {
            $out = scratch() . '/static';
            mkdir("{$out}/home", 0o755, true);
            file_put_contents("{$out}/home/index.html", 'previous');
            $failing = static fn (Request $request): string => $request->path === '/docs/intro'
                ? throw new \RuntimeException('403')
                : document('page');

            expect(fn () => new StaticBuilder(routes(), $failing)->build($out, new FragmentManifest()))
                ->toThrow(\RuntimeException::class, '403')
                ->and(file_get_contents("{$out}/home/index.html"))->toBe('previous')
                ->and(glob(\dirname($out) . '/*'))->toBe([$out]);
        });

        // A boundary a page render would refuse is refused here, rather than
        // shipped as a placeholder /_bootstrap would not sign.
        it('refuses a boundary the route does not render', function () {
            $manifest = new FragmentManifest()->declareRoute('static.doc', ['Sidebar']);

            expect(fn () => new StaticBuilder(routes(), renderer(new \ArrayObject()))->build(scratch() . '/static', $manifest))
                ->toThrow(\RuntimeException::class, 'not one this route renders');
        });

        // ADR 0009: a page with no deferred boundaries cannot be talked into
        // minting. Read as "any component" for a route the renderer build
        // found no boundary on, the build signed what the renderer asked and
        // wrote the route into the manifest as one that may mint anything --
        // for /_bootstrap, and for every dynamic render of it after.
        it('refuses a boundary on a route the renderer build found none on', function () {
            $manifest = new FragmentManifest()->declareRoute('static.home', ['Toc']);

            expect(fn () => new StaticBuilder(routes(), renderer(new \ArrayObject()))->build(scratch() . '/static', $manifest))
                ->toThrow(\RuntimeException::class, 'not one this route renders')
                ->and($manifest->defersOn('static.doc'))->toBeFalse()
                ->and($manifest->componentsFor('static.doc'))->toBeNull();
        });
    });

    describe('what a built page may not carry', function () {
        it('refuses a document carrying anything of one visitor', function (string $html, string $what) {
            $build = new StaticBuilder(routes([HomePage::class]), static fn (): string => $html);

            expect(fn () => $build->build(scratch() . '/static', new FragmentManifest()))
                ->toThrow(\RuntimeException::class, $what);
        })->with([
            'a route token' => [document('x', routeToken: 'v1.abc.def'), 'route token or a history key'],
            'a history key' => [document('x', historyKey: 'a2V5'), 'route token or a history key'],
            'a fragment token' => [document('<div data-token="v1.eyJjIjoiVG9jIiwicCI6e319.c2lnbmF0dXJlLW9mLWl0"></div>'), 'signed token'],
            'a CSRF field' => [document('<input type="hidden" name="_token" value="abc"/>'), 'CSRF token'],
            'a CSRF field, value first' => [document('<input value="abc" name="_token" type="hidden">'), 'CSRF token'],
        ]);

        it('takes a form whose CSRF field is empty: the cookie fills it at submit time', function () {
            expect(fn () => StaticBuilder::assertNothingPerVisitor(document('<input type="hidden" name="_token" value=""/>'), '/'))
                ->not->toThrow(\RuntimeException::class);
        });
    });

    describe('the static minter', function () {
        // Named by what they are, so a page revalidated with other props
        // cannot have an id mean one boundary in the old page and another in
        // the new; two alike are one.
        it('signs nothing and names each boundary by its component and props', function () {
            $minter = new StaticMinter('static.doc', ['doc' => 'intro']);

            $first = $minter->mint('Toc', []);
            $minter->mint('Notes', ['open' => true]);
            $again = $minter->mint('Toc', []);

            expect($first)->toStartWith(StaticMinter::PREFIX)
                ->and($first)->not->toContain('v1.')
                ->and($again)->toBe($first)
                ->and(array_column($minter->boundaries(), 'id'))->toBe([StaticMinter::idOf('Toc', []), StaticMinter::idOf('Notes', ['open' => true])])
                ->and(StaticMinter::idOf('Notes', ['open' => false]))->not->toBe(StaticMinter::idOf('Notes', ['open' => true]))
                ->and(StaticMinter::idOf('Toc', []))->toMatch('/^[0-9a-f]{16}$/');
        });

        it('refuses what a page render would, and keeps why', function () {
            $minter = new StaticMinter('static.doc', [], components: ['Toc']);

            expect(fn () => $minter->mint('Other', []))->toThrow(\Tinvar\Render\FragmentTokenError::class)
                ->and($minter->refused())->toHaveCount(1)
                ->and($minter->boundaries())->toBe([]);
        });
    });
}

namespace Tinvar\Tests\Render\StaticBuilder\Pages {
    use Tinvar\Attributes\Domain;
    use Tinvar\Attributes\EncryptHistory;
    use Tinvar\Attributes\Page;
    use Tinvar\Attributes\StaticPage;
    use Tinvar\Render\StaticParams;

    #[Page('/home', view: 'home', name: 'static.home'), StaticPage]
    final class HomePage {}

    #[Page('/docs/{doc}', view: 'doc', name: 'static.doc'), StaticPage(params: [['doc' => 'intro'], ['doc' => 'guide-2']], revalidate: '10m', tags: ['docs'])]
    final class DocPage {}

    #[Page('/private', view: 'private', name: 'static.private')]
    final class PrivatePage {}

    #[Page('/unnamed', view: 'unnamed'), StaticPage]
    final class UnnamedPage {}

    #[Page('/sealed', view: 'sealed', name: 'static.sealed'), StaticPage, EncryptHistory]
    final class EncryptedPage {}

    #[Page('/hosted', view: 'hosted', name: 'static.hosted'), StaticPage, Domain('admin.{root}')]
    final class HostedPage {}

    #[Page('/files/{file}', view: 'file', name: 'static.file'), StaticPage(params: [['file' => '../etc']])]
    final class PathlessPage {}

    final class Listed implements StaticParams
    {
        public function params(): array
        {
            return [['page' => 'routing'], ['page' => 'queries']];
        }
    }

    final class Climbing implements StaticParams
    {
        public function params(): array
        {
            return [['page' => '..']];
        }
    }

    #[Page('/listed/{page}', view: 'listed', name: 'static.listed'), StaticPage(from: Listed::class)]
    final class ListedPage {}

    #[Page('/climbing/{page}', view: 'climbing', name: 'static.climbing'), StaticPage(from: Climbing::class)]
    final class ClimbingListedPage {}

    #[Page('/mislisted/{page}', view: 'mislisted', name: 'static.mislisted'), StaticPage(from: PathlessPage::class)]
    final class MislistedPage {}
}
