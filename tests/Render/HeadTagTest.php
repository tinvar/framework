<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "PHP-rendered fallback tags
 * marked for replacement." PHP's half: what the render frame carries, and
 * the markup PHP writes itself.
 */

namespace Tinvar\Tests\Render\HeadTag;

use Tinvar\Protocol\Frame;
use Tinvar\Render\HeadTag;

it('rides in the render frame, only when there are any', function () {
    $frame = Frame::render('shop/catalog', [], ['page' => null], 'r', 'n', [], head: [
        HeadTag::title('Pellwick Supply'),
        HeadTag::meta(['name' => 'description', 'content' => 'The shop.']),
    ]);

    expect($frame['head'])->toBe([
        ['tag' => 'title', 'attributes' => [], 'text' => 'Pellwick Supply'],
        ['tag' => 'meta', 'attributes' => ['name' => 'description', 'content' => 'The shop.']],
    ])->and(Frame::render('shop/catalog', [], ['page' => null], 'r', 'n', []))->not->toHaveKey('head');
});

it('is written marked for replacement, and escaped', function () {
    expect(HeadTag::title('Pellwick </title> & co')->toHtml())
        ->toBe('<title data-tinvar-head="fallback">Pellwick &lt;/title&gt; &amp; co</title>')
        ->and(HeadTag::meta(['name' => 'description', 'content' => 'A "shop"'])->toHtml())
        ->toBe('<meta name="description" content="A &quot;shop&quot;" data-tinvar-head="fallback">');
});

it('refuses an attribute that is not one, or is a handler', function (string $name) {
    HeadTag::meta([$name => 'x']);
})->with(['onload', 'bad name', '"><script>', ''])->throws(\InvalidArgumentException::class);
