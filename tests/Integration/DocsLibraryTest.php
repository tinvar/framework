<?php

declare(strict_types=1);

/**
 * The docs' content as PHP reads it (Docs\Content\Library): the nav, the
 * neighbours, the pages the static build is told to build -- and a closed
 * front matter, refused with the file named when it is anything else.
 */

namespace Tinvar\Tests\Integration\DocsLibrary;

use Docs\Content\ContentException;
use Docs\Content\Library;
use Docs\Kernel;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\StaticBuilder;

/**
 * A content directory of its own, with the pages given, by "section/slug" =>
 * front matter lines.
 *
 * @param array<string, string> $pages
 */
function content(array $pages, string $sections = '[{"key":"basics","title":"The basics"},{"key":"more","title":"More"}]'): string
{
    $root = sys_get_temp_dir() . '/tinvar-docs-content-' . bin2hex(random_bytes(4));
    mkdir($root, 0o700);
    file_put_contents("{$root}/sections.json", $sections);
    foreach ($pages as $path => $front) {
        is_dir($root . '/' . \dirname($path)) || mkdir($root . '/' . \dirname($path), 0o700, true);
        file_put_contents("{$root}/{$path}.md", "---\n{$front}\n---\n\nBody.\n");
    }
    $owner = getmypid();
    register_shutdown_function(static function () use ($root, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    });

    return $root;
}

function page(string $title, string $section, int $order, string $extra = ''): string
{
    return "title: {$title}\ndescription: About {$title}.\nsection: {$section}\norder: {$order}" . ($extra === '' ? '' : "\n{$extra}");
}

it('orders pages by section, then order, and links each to its neighbours across sections', function () {
    $library = new Library(content([
        'basics/routing' => page('Routing', 'basics', 2),
        'basics/pages' => page('Pages', 'basics', 1),
        'more/caching' => page('Caching', 'more', 1, 'stub: true'),
    ]));

    expect(array_map(static fn ($p): string => $p->slug, $library->pages()))->toBe(['pages', 'routing', 'caching'])
        ->and($library->find('caching')?->stub)->toBeTrue()
        ->and(array_map(static fn ($p): ?string => $p?->slug, $library->neighbours('routing')))->toBe(['pages', 'caching'])
        ->and(array_map(static fn ($p): ?string => $p?->slug, $library->neighbours('pages')))->toBe([null, 'routing'])
        ->and($library->params())->toBe([['page' => 'pages'], ['page' => 'routing'], ['page' => 'caching']])
        ->and($library->sectionTitle('more'))->toBe('More');
});

it('refuses front matter it does not know, with the file named', function (string $front, string $message) {
    expect(fn () => new Library(content(['basics/page' => $front]))->pages())
        ->toThrow(ContentException::class, $message);
})->with([
    'an unknown key' => [page('A', 'basics', 1, 'layout: wide'), "'layout' is no front matter key"],
    'a line that is no pair' => [page('A', 'basics', 1, '- item'), 'is not a `key: value` line'],
    'a key twice' => [page('A', 'basics', 1, 'title: B'), 'title is given twice'],
    'another section' => [page('A', 'more', 1), "its section is 'more'"],
    'no description' => ["title: A\nsection: basics\norder: 1", 'no description'],
    'an order that is no number' => [page('A', 'basics', 1) . 'x', 'whole number'],
    'a stub that is not true' => [page('A', 'basics', 1, 'stub: yes'), 'stub is true'],
]);

it('refuses two pages with one slug, and a slug that could not name a built page', function () {
    expect(fn () => new Library(content(['basics/pages' => page('A', 'basics', 1), 'more/pages' => page('B', 'more', 1)]))->pages())
        ->toThrow(ContentException::class, "Two pages are called 'pages'")
        ->and(fn () => new Library(content(['basics/Bad_Name' => page('A', 'basics', 1)]))->pages())
        ->toThrow(ContentException::class, 'lowercase words and dashes');
});

// The static build asks the Library which pages to build: one per file, the
// landing page and the not-found file beside them.
it('is what the static build builds the docs\' pages from', function () {
    $kernel = new Kernel('/nonexistent/renderer.sock', new FragmentManifest());
    $built = array_column(new StaticBuilder($kernel->routes, static fn (): string => '')->pages(), 'path');
    $pages = array_map(static fn ($p): string => "/docs/{$p->slug}", new Library()->pages());

    expect($built)->toEqualCanonicalizing(['/', '/not-found', ...$pages]);
});
