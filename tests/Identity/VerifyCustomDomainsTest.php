<?php

declare(strict_types=1);

/**
 * The daily half of "re-verified daily and at every certificate renewal":
 * VerifyCustomDomains, run from `tinvar schedule:run` with no tenant, as the
 * framework's other tasks are.
 */

namespace Tinvar\Tests\Identity\VerifyCustomDomains;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Console\ArrayScheduleStore;
use Tinvar\Console\Console;
use Tinvar\Console\Output;
use Tinvar\Console\Scheduler;
use Tinvar\Container\Container;
use Tinvar\Http\RequestScope;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomainVerification;
use Tinvar\Identity\DomainStatus;
use Tinvar\Identity\VerifyCustomDomains;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

use function Tinvar\Tests\Identity\Fixtures\Domains\failure;
use function Tinvar\Tests\Identity\Fixtures\Domains\verification;

use const Tinvar\Tests\Identity\Fixtures\Domains\TARGET;

require_once __DIR__ . '/Fixtures/domains.php';

/**
 * A scheduler and console with the task registered, as an application
 * registers it, and the verification in its container.
 *
 * @param list<string> $reports
 */
function scheduled(CustomDomainVerification $verification, string $at, array &$reports): Console
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container()->instance(CustomDomainVerification::class, $verification);
    $scope = RequestScope::standard($orm, new AuthContext(), new PolicyRegistry());
    $scheduler = new Scheduler(
        [VerifyCustomDomains::class],
        $orm,
        $container->resolver(),
        $scope,
        new ArrayScheduleStore(),
        report: static function (\Throwable $e) use (&$reports): void {
            $reports[] = $e->getMessage();
        },
    );

    return new Console([], $orm, $container->resolver(), $scope, scheduler: $scheduler, clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable($at));
}

/** @return array{int, list<string>} the exit status and what was written */
function run(Console $console, string $command = 'schedule:run'): array
{
    $lines = [];
    $status = $console->run($command, [], new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line): void {}));

    return [$status, $lines];
}

describe('the daily task', function () {
    it('runs from schedule:run once a day, with no tenant, checking every domain due', function () {
        [$verification, $records, $dns] = verification([
            'here.acme.example' => TARGET,
            'away.acme.example' => ['198.51.100.4'],
            'flaky.acme.example' => failure(),
        ], ['here.acme.example', 'away.acme.example', 'flaky.acme.example']);
        $records->domains['old.acme.example'] = new CustomDomain('old.acme.example', false);
        $reports = [];

        $early = scheduled($verification, '2026-10-26T03:40:00Z', $reports);
        expect(run($early))->toBe([0, []])
            ->and($dns->asked)->toBe([]);

        $console = scheduled($verification, '2026-10-26T03:41:20Z', $reports);
        expect(run($console))->toBe([0, ['ran  ' . VerifyCustomDomains::class]])
            ->and(run($console, 'schedule:list')[1])->toBe(['41 3 * * *  ' . VerifyCustomDomains::class])
            ->and($records->domains['here.acme.example']->active)->toBeTrue()
            ->and($records->domains['away.acme.example']->active)->toBeFalse()
            ->and($records->domains['flaky.acme.example']->active)->toBeTrue()
            ->and($records->domains['flaky.acme.example']->failingSince)->not->toBeNull()
            ->and($dns->asked)->not->toContain('old.acme.example')
            ->and($reports)->toBe([]);
    });

    it('checks the rest when one domain\'s record cannot be written, and reports that one', function () {
        [$verification, $records] = verification(
            ['a.acme.example' => TARGET, 'b.acme.example' => TARGET, 'c.acme.example' => TARGET],
            ['a.acme.example', 'b.acme.example', 'c.acme.example'],
        );
        $records->broken['b.acme.example'] = true;
        $reports = [];
        $console = scheduled($verification, '2026-10-26T03:41:00Z', $reports);

        expect(run($console))->toBe([1, ['failed  ' . VerifyCustomDomains::class]])
            ->and($records->domains['a.acme.example']->checkedAt)->not->toBeNull()
            ->and($records->domains['c.acme.example']->checkedAt)->not->toBeNull()
            ->and($reports)->toHaveCount(1)
            ->and($reports[0])->toContain('b.acme.example: RuntimeException: The records of b.acme.example cannot be written.');
    });

    it('pages through more domains than a batch, each once', function () {
        $hosts = array_map(static fn (int $i): string => "shop{$i}.acme.example", range(1, CustomDomainVerification::BATCH + 30));
        [$verification, , $dns] = verification(array_fill_keys($hosts, TARGET), $hosts);

        $outcomes = $verification->verifyDue()['outcomes'];

        expect($outcomes)->toHaveCount(CustomDomainVerification::BATCH + 30)
            ->and(array_unique($outcomes, SORT_REGULAR))->toBe(['shop1.acme.example' => DomainStatus::Verified])
            ->and($dns->asked)->toHaveCount(CustomDomainVerification::BATCH + 30);
    });

    // A whole batch whose records cannot be written stays due, first in
    // line, and the run ended on it: every domain behind went unchecked.
    it('checks the domains behind a batch whose every record fails', function () {
        $hosts = array_map(static fn (int $i): string => \sprintf('s%03d.acme.example', $i), range(1, CustomDomainVerification::BATCH + 30));
        [$verification, $records, $dns] = verification(array_fill_keys($hosts, TARGET), $hosts);
        foreach (\array_slice($hosts, 0, CustomDomainVerification::BATCH) as $host) {
            $records->broken[$host] = true;
        }

        $result = $verification->verifyDue();

        expect($result['failures'])->toHaveCount(CustomDomainVerification::BATCH)
            ->and($result['outcomes'])->toHaveCount(30)
            ->and($dns->asked)->toHaveCount(CustomDomainVerification::BATCH + 30);
    });
});
