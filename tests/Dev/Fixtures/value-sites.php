<?php

declare(strict_types=1);

/**
 * Loaders whose source ValueSitesTest reads: Data built with named and
 * positional arguments, in two branches and in a helper, through an
 * aliased group import, and once in a way the source cannot say.
 */

namespace Tinvar\Tests\Dev\ValueSites;

use Tinvar\Data\Data;
use Tinvar\Tests\Dev\ValueSites\Parts\{OwnerData as Owner, TotalsData};

use function array_sum;

final readonly class LedgerData extends Data
{
    public function __construct(
        public string $title,
        public int $count,
        public Owner $owner,
        public ?TotalsData $totals = null,
        public string $note = '',
    ) {}
}

final class LedgerPage
{
    public function __invoke(bool $short): LedgerData
    {
        $rows = [1, 2, 3];
        if ($short) {
            return new LedgerData('Short', \count($rows), new Owner(name: 'Ada'));
        }

        return new LedgerData(
            title: 'Ledger',
            count: \count($rows),
            owner: $this->owner(),
            totals: new TotalsData(sum: array_sum($rows)),
        );
    }

    private function owner(): Owner
    {
        return new Owner(name: 'Grace');
    }
}

final class SpreadPage
{
    /** @param array{string, int, Owner} $arguments */
    public function __invoke(array $arguments): LedgerData
    {
        $class = LedgerData::class;

        return \count($arguments) > 3 ? new $class(...$arguments) : new LedgerData(...$arguments);
    }
}
