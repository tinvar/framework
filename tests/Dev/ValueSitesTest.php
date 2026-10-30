<?php

declare(strict_types=1);

/**
 * Where a loader made each field of the Data it returns (ADR 0132): read
 * from the loader's class, named and positional arguments alike, nested
 * Data under its field, every branch listed, and nothing guessed where the
 * source does not say.
 */

namespace Tinvar\Tests\Dev\ValueSites;

use Tinvar\Dev\FieldOrigins;
use Tinvar\Dev\Recording;
use Tinvar\Dev\ValueSites;
use Tinvar\Http\Request;

require_once __DIR__ . '/Fixtures/value-sites-parts.php';
require_once __DIR__ . '/Fixtures/value-sites.php';

const FIXTURE = __DIR__ . '/Fixtures/value-sites.php';

/** The `file:line` of the fixture's line holding $text. */
function at(string $text): string
{
    foreach (file(FIXTURE, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        if (str_contains($line, $text)) {
            return FIXTURE . ':' . ($i + 1);
        }
    }

    throw new \LogicException("No line holds {$text}.");
}

it('names the lines that fill each field, in every branch and in the class\'s helpers', function () {
    $sites = ValueSites::of(LedgerPage::class, LedgerData::class, FieldOrigins::of(LedgerData::class));
    $short = at("new LedgerData('Short'");

    expect($sites['title'])->toBe([$short, at("title: 'Ledger'")])
        ->and($sites['count'])->toBe([$short, at('count: \count($rows)')])
        ->and($sites['owner'])->toBe([$short, at('owner: $this->owner()')])
        ->and($sites['owner.name'])->toBe([$short, at("new Owner(name: 'Grace')")])
        ->and($sites['totals'])->toBe([at('totals: new TotalsData')])
        ->and($sites['totals.sum'])->toBe([at('totals: new TotalsData')])
        ->and($sites)->not->toHaveKey('note');
});

it('says nothing of a spread, a class held in a variable, or a class it cannot read', function () {
    expect(ValueSites::of(SpreadPage::class, LedgerData::class, FieldOrigins::of(LedgerData::class)))->toBe([])
        ->and(ValueSites::of('Tinvar\Tests\Dev\ValueSites\Nowhere', LedgerData::class, []))->toBe([]);
});

it('keeps a few sites per field, as text', function () {
    $recording = new Recording(new Request('GET', '/ledger', ['accept' => 'text/html']));
    $recording->origins([[
        'slot' => 'page', 'kind' => 'page', 'loader' => LedgerPage::class, 'data' => LedgerData::class, 'site' => '', 'route' => 'ledger',
        'fields' => [['name' => 'title', 'type' => 'string', 'site' => '', 'declaredIn' => LedgerData::class, 'made' => ['/a.php:1', '/a.php:2', '/a.php:3', '/a.php:4', '/a.php:5']]],
    ]]);

    expect($recording->finish(200, 's', 'a')['detail']['origins'][0]['fields'][0]['made'])
        ->toBe(['/a.php:1', '/a.php:2', '/a.php:3', '/a.php:4']);
});
