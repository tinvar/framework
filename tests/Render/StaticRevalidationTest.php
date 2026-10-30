<?php

declare(strict_types=1);

/**
 * Static pages revalidated where they are served: built again in place once
 * older than their interval, taken out of service the moment an action's
 * committed writes invalidate what they show, and never built as anyone
 * but nobody -- with /_bootstrap reading each rebuilt page's boundaries as
 * the manifest's file now holds them.
 */

namespace Tinvar\Tests\Render\StaticRevalidation {
    use Tinvar\Actions\ActionRegistry;
    use Tinvar\Actions\ActionRunner;
    use Tinvar\Actions\TagInvalidation;
    use Tinvar\Attributes\StaticPage;
    use Tinvar\Auth\AllowAllGate;
    use Tinvar\Http\Request;
    use Tinvar\Live\Invalidator;
    use Tinvar\Live\MemoryHub;
    use Tinvar\Live\Topics;
    use Tinvar\Orm\IdentityMap;
    use Tinvar\Orm\Orm;
    use Tinvar\Orm\PdoConnection;
    use Tinvar\Render\FragmentManifest;
    use Tinvar\Render\RevalidateStaticPages;
    use Tinvar\Render\StaticBuilder;
    use Tinvar\Render\StaticMinter;
    use Tinvar\Render\StaticPages;
    use Tinvar\Routing\RouteRegistry;
    use Tinvar\Support\Signer;
    use Tinvar\Tests\Render\StaticRevalidation\Fixtures\DocActions;
    use Tinvar\Tests\Render\StaticRevalidation\Fixtures\DocPage;
    use Tinvar\Tests\Render\StaticRevalidation\Fixtures\HomePage;
    use Tinvar\Validation\Validator;

    const NOW = 1_800_000_000;

    /** A directory removed when the test process ends. */
    function scratch(): string
    {
        $directory = sys_get_temp_dir() . '/tinvar-revalidate-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $owner = getmypid();
        register_shutdown_function(static function () use ($directory, $owner): void {
            if (getmypid() === $owner) {
                exec('rm -rf ' . escapeshellarg($directory));
            }
        });

        return $directory;
    }

    /**
     * What the "renderer" draws now: each doc page names a Toc boundary with
     * the depth this holds, and every page says which edition it is.
     */
    final class Content
    {
        public static int $depth = 2;

        public static string $edition = 'first';

        /** @var list<Request> */
        public static array $requests = [];

        /** @var (\Closure(string): void)|null run as each page renders */
        public static ?\Closure $during = null;
    }

    function builder(): StaticBuilder
    {
        $routes = new RouteRegistry();
        $routes->registerAll([HomePage::class, DocPage::class]);

        return new StaticBuilder($routes, static function (Request $request, \Closure $mint): string {
            Content::$requests[] = $request;
            if (Content::$during !== null) {
                (Content::$during)($request->path);
            }
            $placeholder = str_starts_with($request->path, '/docs/') ? $mint('Toc', ['depth' => Content::$depth]) : '';

            return '<!DOCTYPE html><html><head></head><body>' . Content::$edition . " {$request->path} {$placeholder}</body></html>";
        });
    }

    /**
     * Pages built into a fresh directory, and the manifest beside it.
     *
     * @return array{string, string} the directory, and the manifest's file
     */
    function built(): array
    {
        Content::$depth = 2;
        Content::$edition = 'first';
        Content::$requests = [];
        Content::$during = null;

        $root = scratch();
        $manifest = new FragmentManifest()->declareRoute('static.doc', ['Toc']);
        builder()->build("{$root}/static", $manifest);
        $manifest->write("{$root}/fragments.json");

        return ["{$root}/static", "{$root}/fragments.json"];
    }

    /** Sets a built page's file to have been written $ago seconds before NOW. */
    function age(string $directory, string $path, int $ago): void
    {
        touch(StaticBuilder::fileOf($directory, $path), NOW - $ago);
    }

    describe('a page rebuilt in place', function () {
        it('replaces the file where it is, rendered as a visitor with no session', function () {
            [$directory, $manifest] = built();
            Content::$edition = 'second';
            Content::$requests = [];

            $result = builder()->rebuild($directory, $manifest, ['/home']);

            expect($result)->toBe(['built' => ['/home'], 'failed' => []])
                ->and(file_get_contents("{$directory}/home/index.html"))->toContain('second /home')
                // Only what it was asked to: the doc pages are as they were.
                ->and(file_get_contents("{$directory}/docs/intro/index.html"))->toContain('first /docs/intro')
                ->and(Content::$requests)->toHaveCount(1)
                ->and(Content::$requests[0]->cookies)->toBe([])
                ->and(glob("{$directory}/home/.[!.]*"))->toBe([], 'nothing left beside it');
        });

        // The new page names a boundary with other props, under its own id;
        // the old one stays in the manifest until the next rebuild, for the
        // visitor who loaded the old page a moment before -- then goes.
        it('declares the new page\'s boundaries, keeping the replaced page\'s until the next', function () {
            [$directory, $manifest] = built();
            $old = StaticMinter::idOf('Toc', ['depth' => 2]);
            $new = StaticMinter::idOf('Toc', ['depth' => 3]);

            Content::$depth = 3;
            builder()->rebuild($directory, $manifest, ['/docs/intro']);
            $once = FragmentManifest::fromFile($manifest)->boundariesFor('static.doc', ['doc' => 'intro']);

            builder()->rebuild($directory, $manifest, ['/docs/intro']);
            $twice = FragmentManifest::fromFile($manifest)->boundariesFor('static.doc', ['doc' => 'intro']);

            expect(array_column($once, 'id'))->toBe([$new, $old])
                ->and(array_column($twice, 'id'))->toBe([$new])
                ->and($twice[0]['props'])->toBe(['depth' => 3])
                // The routes the build said may mint are untouched.
                ->and(FragmentManifest::fromFile($manifest)->componentsFor('static.doc'))->toBe(['Toc'])
                // The other built page of the route keeps its own.
                ->and(array_column(FragmentManifest::fromFile($manifest)->boundariesFor('static.doc', ['doc' => 'guide']), 'id'))->toBe([$old]);
        });

        it('leaves a page that fails serving as it was, and builds the rest', function () {
            [$directory, $manifest] = built();
            Content::$edition = 'second';
            Content::$during = static fn (string $path) => $path === '/docs/intro' ? throw new \RuntimeException('403') : null;

            $result = builder()->rebuild($directory, $manifest);

            expect($result['built'])->toEqualCanonicalizing(['/home', '/docs/guide'])
                ->and(array_keys($result['failed']))->toBe(['/docs/intro'])
                ->and($result['failed']['/docs/intro'])->toContain('403')
                ->and(file_get_contents("{$directory}/docs/intro/index.html"))->toContain('first');
        });

        it('holds a rebuilt page to carrying nothing of anyone\'s, as a build does', function () {
            $root = scratch();
            $routes = new RouteRegistry();
            $routes->registerAll([HomePage::class]);
            $leaky = new StaticBuilder($routes, static fn (): string => '<html><head></head><body><input type="hidden" name="_token" value="abc"></body></html>');
            mkdir("{$root}/static/home", 0o755, true);
            file_put_contents("{$root}/static/home/index.html", 'as it was');

            $result = $leaky->rebuild("{$root}/static", "{$root}/fragments.json");

            expect($result['built'])->toBe([])
                ->and($result['failed']['/home'])->toContain('CSRF token')
                ->and(file_get_contents("{$root}/static/home/index.html"))->toBe('as it was');
        });

        it('reports a path that is no static page, and touches nothing for it', function () {
            [$directory, $manifest] = built();

            expect(builder()->rebuild($directory, $manifest, ['/private', '/../etc']))
                ->toBe(['built' => [], 'failed' => [
                    '/private' => 'no #[StaticPage] is built at this path',
                    '/../etc' => 'no #[StaticPage] is built at this path',
                ]]);
        });
    });

    describe('StaticPages', function () {
        it('finds stale the pages missing or older than their interval, and only those', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);
            age($directory, '/home', 365 * 86_400);     // no interval: never stale by age
            age($directory, '/docs/intro', 601);        // past its ten minutes
            age($directory, '/docs/guide', 599);        // not yet

            expect($pages->stale())->toBe(['/docs/intro']);

            unlink("{$directory}/home/index.html");

            expect($pages->stale())->toEqualCanonicalizing(['/home', '/docs/intro']);
        });

        it('builds the stale pages again, and nothing when none are', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);
            age($directory, '/home', 0);
            age($directory, '/docs/intro', 601);
            age($directory, '/docs/guide', 0);
            Content::$edition = 'second';

            expect($pages->revalidate())->toBe(['built' => ['/docs/intro'], 'failed' => []])
                ->and(file_get_contents("{$directory}/docs/intro/index.html"))->toContain('second');

            age($directory, '/docs/intro', 0);
            Content::$requests = [];

            expect($pages->revalidate())->toBe(['built' => [], 'failed' => []])
                ->and(Content::$requests)->toBe([]);
        });

        // Out of service at once: a server finds no file and sends the
        // request to PHP, which renders it fresh until the next build.
        it('takes the pages a tag names out of service, and the next run builds them', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);

            expect($pages->invalidate(['docs']))->toEqualCanonicalizing(['/docs/intro', '/docs/guide'])
                ->and(is_file("{$directory}/docs/intro/index.html"))->toBeFalse()
                ->and(is_file("{$directory}/home/index.html"))->toBeTrue()
                ->and($pages->invalidate(['other']))->toBe([])
                ->and($pages->invalidate([]))->toBe([])
                // The marker is beside the directory, where nothing serves it.
                ->and(glob("{$directory}/.[!.]*"))->toBe([]);

            $later = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW + 60);

            expect($later->revalidate()['built'])->toEqualCanonicalizing(['/docs/intro', '/docs/guide'])
                ->and(is_file("{$directory}/docs/intro/index.html"))->toBeTrue();
        });

        // Invalidated while it was being rendered: it may have been drawn
        // from what the invalidating action changed, so it is not moved in.
        it('leaves out of service a page invalidated while it was built', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);
            Content::$during = static function (string $path) use ($pages): void {
                if ($path === '/docs/intro') {
                    $pages->invalidate(['docs']);
                }
            };

            $result = $pages->revalidate(['/docs/intro']);

            expect($result['built'])->toBe([])
                ->and($result['failed']['/docs/intro'])->toContain('invalidated while it was built')
                ->and(is_file("{$directory}/docs/intro/index.html"))->toBeFalse()
                ->and($pages->stale())->toContain('/docs/intro');
        });

        // Checked once the whole run was over, the page drawn before the
        // action's commit was served from the moment it was moved in until
        // then -- while every page after it rendered.
        it('takes a page invalidated while it was built out of service before the run goes on', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);
            $servedMeanwhile = null;
            Content::$during = static function (string $path) use ($pages, $directory, &$servedMeanwhile): void {
                if ($path === '/docs/intro') {
                    $pages->invalidate(['docs']);
                }
                if ($path === '/docs/guide') {
                    $servedMeanwhile = is_file("{$directory}/docs/intro/index.html");
                }
            };

            $pages->revalidate(['/docs/intro', '/docs/guide']);

            expect($servedMeanwhile)->toBeFalse()
                ->and(is_file("{$directory}/docs/intro/index.html"))->toBeFalse();
        });

        // A run that dies between moving a page in and finding it invalidated
        // left it serving, its marker older than any later run: never built
        // again for a page with no interval, and not before its interval for
        // one with.
        it('finds stale a page whose invalidation no build has answered, whatever its file', function () {
            [$directory, $manifest] = built();
            $pages = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW);
            $pages->invalidate(['docs']);
            file_put_contents("{$directory}/docs/intro/index.html", 'drawn before the commit');
            age($directory, '/docs/intro', 0);
            age($directory, '/home', 0);

            expect($pages->stale())->toContain('/docs/intro')
                ->and($pages->stale())->not->toContain('/home');

            $later = new StaticPages(builder(), $directory, $manifest, static fn (): int => NOW + 60);
            $later->revalidate();
            age($directory, '/docs/intro', -60);
            age($directory, '/docs/guide', -60);

            expect(file_get_contents("{$directory}/docs/intro/index.html"))->toContain('first /docs/intro')
                ->and($later->stale())->toBe([]);
        });
    });

    describe('the scheduled task', function () {
        it('builds what is stale, and throws together what it could not', function () {
            [$directory, $manifest] = built();
            unlink("{$directory}/home/index.html");
            unlink("{$directory}/docs/intro/index.html");
            Content::$during = static fn (string $path) => $path === '/docs/intro' ? throw new \RuntimeException('down') : null;

            expect(fn () => new RevalidateStaticPages()(new StaticPages(builder(), $directory, $manifest)))
                ->toThrow(\RuntimeException::class, '/docs/intro')
                ->and(is_file("{$directory}/home/index.html"))->toBeTrue();
        });
    });

    describe('an action invalidating a tag', function () {
        /** @return array{ActionRunner, Orm, StaticPages, string} */
        function action(): array
        {
            [$directory, $manifest] = built();
            $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
            $registry = new ActionRegistry();
            $registry->register(DocActions::class);
            $pages = new StaticPages(builder(), $directory, $manifest);

            return [new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm), static: $pages), $orm, $pages, $directory];
        }

        // After the commit, never before: a build in between would read the
        // old rows and serve them as fresh.
        it('takes its pages out of service once its writes commit', function () {
            [$runner, $orm, , $directory] = action();

            $orm->transaction(function () use ($runner, $directory): void {
                $runner->run('docs.edit', []);

                expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue('not before the commit');
            });

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeFalse()
                ->and(is_file("{$directory}/home/index.html"))->toBeTrue();
        });

        it('takes nothing down for writes that roll back', function () {
            [$runner, $orm, , $directory] = action();

            try {
                $orm->transaction(function () use ($runner): void {
                    $runner->run('docs.edit', []);

                    throw new \RuntimeException('the write failed');
                });
            } catch (\RuntimeException) {
            }

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue();
        });

        // A static page is no tenant's; one tenant's tags name its own rows.
        it('takes nothing down for a tenant\'s action', function () {
            [$runner, $orm, , $directory] = action();

            $orm->actAs('tenant-a', static fn () => $runner->run('docs.edit', []));

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue();
        });
    });

    // A job's or a task's write takes the pages showing it down as an
    // action's does: through the TagInvalidation the container hands it, or
    // the live Invalidator given the pages (ADR 0106). It did not, and the
    // page served the old rows until the scheduler next rebuilt it.
    describe('a job or a task invalidating a tag', function () {
        /** @return array{Orm, StaticPages, string} */
        function pages(): array
        {
            [$directory, $manifest] = built();
            $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));

            return [$orm, new StaticPages(builder(), $directory, $manifest), $directory];
        }

        it('takes its pages out of service through TagInvalidation, once its writes commit', function () {
            [$orm, $pages, $directory] = pages();
            $tags = new TagInvalidation($orm, static: $pages);

            $orm->transaction(function () use ($tags, $directory): void {
                $tags->invalidate(['docs']);

                expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue('not before the commit');
            });

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeFalse()
                ->and(is_file("{$directory}/docs/guide/index.html"))->toBeFalse()
                ->and(is_file("{$directory}/home/index.html"))->toBeTrue('a page carrying no such tag stays');
        });

        it('takes its pages out of service through the live Invalidator given them', function () {
            [$orm, $pages, $directory] = pages();
            $hub = new MemoryHub();
            $live = new Invalidator($hub, new Topics(new Signer('static-revalidation-test-key-0123456789', Topics::PURPOSE)), $orm, static: $pages);

            $live->invalidate(['docs']);

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeFalse()
                ->and(is_file("{$directory}/home/index.html"))->toBeTrue()
                ->and($hub->published)->not->toBe([], 'and still tells the clients listening');
        });

        it('takes nothing down for writes that roll back', function () {
            [$orm, $pages, $directory] = pages();
            $tags = new TagInvalidation($orm, static: $pages);

            try {
                $orm->transaction(function () use ($tags): void {
                    $tags->invalidate(['docs']);

                    throw new \RuntimeException('the write failed');
                });
            } catch (\RuntimeException) {
            }

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue();
        });

        // A job runs in the tenant it was dispatched in, and a tenant's tags
        // name its own rows: no static page, which is no tenant's, goes.
        it('takes nothing down for a job running as a tenant', function () {
            [$orm, $pages, $directory] = pages();
            $tags = new TagInvalidation($orm, static: $pages);
            $live = new Invalidator(new MemoryHub(), new Topics(new Signer('static-revalidation-test-key-0123456789', Topics::PURPOSE)), $orm, static: $pages);

            $orm->actAs('tenant-a', static function () use ($tags, $live): void {
                $tags->invalidate(['docs']);
                $live->invalidate(['docs']);
            });
            $tags->invalidate(['docs'], 'tenant-b');

            expect(is_file("{$directory}/docs/intro/index.html"))->toBeTrue();
        });
    });

    describe('the attribute', function () {
        it('reads the interval as a duration, and refuses an empty tag', function () {
            expect(new StaticPage(revalidate: '15m')->revalidateAfter)->toBe(900)
                ->and(new StaticPage()->revalidateAfter)->toBeNull()
                ->and(fn () => new StaticPage(revalidate: '0m'))->toThrow(\InvalidArgumentException::class)
                ->and(fn () => new StaticPage(tags: ['']))->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('the manifest, as its file holds it now', function () {
        it('reads the file again once it changes, and parses nothing while it does not', function () {
            [, $path] = built();
            $booted = FragmentManifest::fromFile($path);
            $first = $booted->refreshed();

            expect($first->boundariesFor('static.doc', ['doc' => 'intro']))->toHaveCount(1)
                ->and($booted->refreshed())->toBe($first);

            FragmentManifest::fromFile($path)->forget('static.doc', ['doc' => 'intro'])->write($path);

            expect($booted->refreshed()->boundariesFor('static.doc', ['doc' => 'intro']))->toBe([])
                ->and($booted->boundariesFor('static.doc', ['doc' => 'intro']))->toHaveCount(1, 'as it booted')
                ->and(new FragmentManifest()->refreshed())->toBeInstanceOf(FragmentManifest::class);
        });
    });
}

namespace Tinvar\Tests\Render\StaticRevalidation\Fixtures {
    use Tinvar\Actions\ActionResult;
    use Tinvar\Attributes\Action;
    use Tinvar\Attributes\NoAuthorization;
    use Tinvar\Attributes\Page;
    use Tinvar\Attributes\StaticPage;

    #[Page('/home', view: 'home', name: 'static.home'), StaticPage]
    final class HomePage {}

    #[Page('/docs/{doc}', view: 'doc', name: 'static.doc'), StaticPage(params: [['doc' => 'intro'], ['doc' => 'guide']], revalidate: '10m', tags: ['docs'])]
    final class DocPage {}

    final class DocActions
    {
        #[Action(name: 'docs.edit'), NoAuthorization(reason: 'test')]
        public function edit(): ActionResult
        {
            return ActionResult::revalidateTags('docs');
        }
    }
}
