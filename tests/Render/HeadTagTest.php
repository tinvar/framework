<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "PHP-rendered fallback tags
 * marked for replacement." PHP's half: what the render frame carries; the
 * renderer writes them, marked (packages/renderer/src/head.js).
 */

namespace Tinvar\Tests\Render\HeadTag;

use Tinvar\Protocol\Frame;
use Tinvar\Protocol\HeadTag;

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

it('refuses an attribute that is not one, or is a handler', function (string $name) {
    HeadTag::meta([$name => 'x']);
})->with(['onload', 'bad name', '"><script>', ''])->throws(\InvalidArgumentException::class);
