<?php

declare(strict_types=1);

/**
 * CompiledRoute::url() promised never to build a link this router refuses,
 * and built `/tags/..`, `/tags/.`, `/tags/a%5Cb` and `/tags/a%09b` -- every
 * one a 404 here, since a request decodes each segment and refuses a
 * traversal step, a backslash or a control character.
 */

namespace Tinvar\Tests\Routing\UrlRoundTrip;

use Tinvar\Attributes\Page;
use Tinvar\Http\Request;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

#[Page('/tags/{tag}', view: 'tags/show', name: 'tags.show')]
final class Tag {}

function registry(): RouteRegistry
{
    $registry = new RouteRegistry();
    $registry->register(Tag::class);

    return $registry;
}

it('refuses a value the router would answer with a 404', function (string $value) {
    expect(fn () => registry()->url('tags.show', ['tag' => $value]))
        ->toThrow(RoutingException::class, 'a parameter is one path segment');
})->with([
    'a traversal step' => ['..'],
    'the current directory' => ['.'],
    'a backslash' => ['a\\b'],
    'a tab' => ["a\tb"],
    'a newline' => ["a\nb"],
    'a null byte' => ["a\0b"],
    'a slash' => ['a/b'],
    'nothing' => [''],
]);

it('builds every other value so that it round-trips', function (string $value) {
    $registry = registry();
    $url = $registry->url('tags.show', ['tag' => $value]);

    expect($registry->match((new Request('GET', $url))->path)?->parameters)->toBe(['tag' => $value]);
})->with([
    'plus signs' => ['c++'],
    'query and fragment characters' => ['r 1?x#y'],
    'a percent sign' => ['100%'],
    'dots inside a value' => ['v1.2..3'],
    'unicode' => ['café'],
]);

it('does not write a refused value raw into its message', function () {
    expect(fn () => registry()->url('tags.show', ['tag' => "a\nb"]))
        ->toThrow(RoutingException::class, "'a%0Ab'");
});
