<?php

declare(strict_types=1);

/**
 * What an export or import must declare to be registered at all -- the
 * build rules, held at registration and by `tinvar check` -- and the CSV
 * cells and download tokens they write.
 */

namespace Tinvar\Tests\Transfers\Registry;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\BuildRules;
use Tinvar\Data\Data;
use Tinvar\Orm\Query;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Tests\Transfers\Fixtures\StockImport;
use Tinvar\Tests\Transfers\Fixtures\StockRow;
use Tinvar\Tests\Transfers\Fixtures\Widget;
use Tinvar\Tests\Transfers\Fixtures\WidgetRow;
use Tinvar\Tests\Transfers\Fixtures\WidgetsExport;
use Tinvar\Transfers\CompiledTransfer;
use Tinvar\Transfers\Csv;
use Tinvar\Transfers\DownloadToken;
use Tinvar\Transfers\TransferRegistry;
use Tinvar\Validation\FormRequest;

require_once __DIR__ . '/Fixtures/app.php';

#[Export(WidgetRow::class)]
final readonly class Unchecked
{
    public function __invoke(): Query
    {
        throw new \LogicException('never run');
    }
}

#[Export(WidgetRow::class), NoAuthorization(reason: 'every member of its panel may')]
final readonly class Exempt
{
    public function __invoke(): Query
    {
        throw new \LogicException('never run');
    }
}

#[Export(WidgetRow::class), Authorize('export', Widget::class)]
final readonly class UncheckedModel
{
    public function __invoke(Widget $widget): Query
    {
        throw new \LogicException('never run');
    }
}

final readonly class LeakyRow extends Data
{
    /** @param list<string> $tags */
    public function __construct(public string $sku, public array $tags) {}
}

#[Export(LeakyRow::class), Authorize('export', Widget::class)]
final readonly class LeakyExport
{
    public function __invoke(): Query
    {
        throw new \LogicException('never run');
    }
}

#[Export(WidgetRow::class), Authorize('export', Widget::class)]
final readonly class ArrayExport
{
    /** @return list<Widget> */
    public function __invoke(): array
    {
        return [];
    }
}

final readonly class UncheckedRow extends FormRequest
{
    public function __construct(public Widget $widget, public int $stock) {}
}

#[Import, Authorize('restock', Widget::class)]
final readonly class UncheckedRowImport
{
    public function __invoke(UncheckedRow $row): void {}
}

#[Import, Authorize('restock', Widget::class)]
final readonly class RowlessImport
{
    public function __invoke(): void {}
}

describe('registration', function () {
    it('compiles an export and an import, their columns from their rows', function () {
        $registry = new TransferRegistry();
        [$export, $import] = $registry->registerAll([WidgetsExport::class, StockImport::class]);

        expect($export->kind)->toBe(CompiledTransfer::EXPORT)
            ->and($export->columns)->toBe(['sku', 'name', 'stock'])
            ->and($export->formats)->toBe(['csv', 'jsonl'])
            ->and($import->kind)->toBe(CompiledTransfer::IMPORT)
            ->and($import->row)->toBe(StockRow::class)
            ->and($import->columns)->toBe(['widget', 'stock'])
            ->and($import->rowModels)->toBe(['widget' => Widget::class])
            ->and(array_map(static fn (Authorize $c): string => $c->ability, $import->rowChecks()))->toBe(['update'])
            ->and(array_map(static fn (Authorize $c): string => $c->ability, $import->startChecks()))->toBe(['restock'])
            ->and($registry->export('widgets'))->toBe($export)
            ->and($registry->import('widgets'))->toBeNull();
    });

    it('refuses an export with no #[Authorize] -- and takes one exempt with a reason', function () {
        expect(fn () => (new TransferRegistry())->register(Unchecked::class))
            ->toThrow(\InvalidArgumentException::class, BuildRules::QUERY_MUST_DECLARE_AUTHORIZATION)
            ->and((new TransferRegistry())->register(Exempt::class)->checks)->toBe([]);
    });

    it('refuses a model input no #[Authorize] names, on the start and on a row', function () {
        expect(fn () => (new TransferRegistry())->register(UncheckedModel::class))
            ->toThrow(\InvalidArgumentException::class, BuildRules::AUTHORIZE_EVERY_MODEL_INPUT)
            ->and(fn () => (new TransferRegistry())->register(UncheckedRowImport::class))
            ->toThrow(\InvalidArgumentException::class, BuildRules::AUTHORIZE_EVERY_MODEL_INPUT);
    });

    it('refuses a column that is not a scalar, a body that is not a Query, and an import with no row', function () {
        expect(fn () => (new TransferRegistry())->register(LeakyExport::class))->toThrow(\InvalidArgumentException::class, 'a column holds a scalar')
            ->and(fn () => (new TransferRegistry())->register(ArrayExport::class))->toThrow(\InvalidArgumentException::class, 'a page at a time')
            ->and(fn () => (new TransferRegistry())->register(RowlessImport::class))->toThrow(\InvalidArgumentException::class, "an import's row");
    });

    it('refuses two of a kind under one name', function () {
        $registry = new TransferRegistry();
        $registry->register(WidgetsExport::class);

        expect(fn () => $registry->register(WidgetsExport::class))->toThrow(\InvalidArgumentException::class, 'Two exports');
    });

    it('reports every refusal as a violation for tinvar check', function () {
        $violations = TransferRegistry::check([WidgetsExport::class, Unchecked::class, LeakyExport::class]);

        expect(array_map(static fn ($v): string => $v->where, $violations))->toBe([Unchecked::class, LeakyExport::class])
            ->and($violations[0]->rule)->toBe(TransferRegistry::TRANSFERS_MUST_COMPILE);
    });

    it('is held by the build rules wherever they run', function () {
        $violations = (new BuildRules())->check([Unchecked::class, UncheckedModel::class, WidgetsExport::class]);

        expect(array_map(static fn ($v): string => $v->rule, $violations))
            ->toBe([BuildRules::QUERY_MUST_DECLARE_AUTHORIZATION, BuildRules::AUTHORIZE_EVERY_MODEL_INPUT]);
    });
});

describe('a CSV cell', function () {
    it('is prefixed when a spreadsheet would read it as a formula', function (string $value) {
        expect(Csv::cell($value))->toBe("'" . $value)
            ->and(Csv::value(Csv::cell($value)))->toBe($value);
    })->with(['=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1", '=HYPERLINK("http://x","y")']);

    it('is written as it is otherwise, numbers among them', function () {
        expect(Csv::cell('plain'))->toBe('plain')
            ->and(Csv::cell(-5))->toBe('-5')
            ->and(Csv::cell(-1.5))->toBe('-1.5')
            ->and(Csv::cell(true))->toBe('true')
            ->and(Csv::cell(null))->toBe('')
            ->and(Csv::cell("it's"))->toBe("it's")
            ->and(Csv::value("'plain"))->toBe("'plain")
            ->and(fn () => Csv::cell(['a']))->toThrow(\InvalidArgumentException::class);
    });
});

describe('a download token', function () {
    it('opens for the claims it was minted with, and not past its expiry or the longest a link lives', function () {
        $signer = new Signer('download-token-test-key-0123456789abcdef', DownloadToken::PURPOSE);
        $token = new DownloadToken('t', 'widgets', 'exports/' . str_repeat('ab', 16) . '.csv', 'acme', 'staff', 'hash', 2_000, 1_000);

        expect(DownloadToken::decode($token->encode($signer), $signer, 1_500)->transfer)->toBe('t')
            ->and(fn () => DownloadToken::decode($token->encode($signer), $signer, 2_000))->toThrow(TokenError::class)
            ->and(fn () => DownloadToken::decode(
                new DownloadToken(...[...get_object_vars($token), 'expiresAt' => 1_000 + DownloadToken::MAX_TTL + 1])->encode($signer),
                $signer,
                1_500,
            ))->toThrow(TokenError::class)
            ->and(fn () => DownloadToken::decode(
                new DownloadToken(...[...get_object_vars($token), 'key' => '../etc/passwd'])->encode($signer),
                $signer,
                1_500,
            ))->toThrow(TokenError::class);
    });
});
