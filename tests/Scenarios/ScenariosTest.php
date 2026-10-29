<?php

declare(strict_types=1);

/**
 * `#[Scenario]` users exist only where a profile allows them -- staging and
 * test, never production -- and their sign-ins reach a file of the runner's
 * own, never the output (ADR 0090).
 */

namespace Tinvar\Tests\Scenarios\Scenarios;

use Tinvar\Attributes\Scenario;
use Tinvar\Scenarios\ScenarioSeedCommand;
use Tinvar\Scenarios\Scenarios;
use Tinvar\Scenarios\Seeded;
use Tinvar\Scenarios\SeedsScenario;
use Tinvar\Support\PrivateDirectory;

#[Scenario('shoppers')]
final class Shoppers implements SeedsScenario
{
    public int $seeded = 0;

    public function seed(array $input): Seeded
    {
        $count = $input['count'] ?? 1;
        if (!\is_int($count) || $count < 1) {
            throw new \InvalidArgumentException('`count` is a whole number.');
        }
        $this->seeded += $count;
        $users = array_map(static fn (int $n): string => "user-{$n}", range(1, $count));

        return new Seeded(
            $users,
            ['note' => 'made'],
            array_map(static fn (string $user): array => ['login' => "{$user}@example.test", 'password' => 'p-' . $user], $users),
        );
    }
}

final class Unnamed implements SeedsScenario
{
    public function seed(array $input): Seeded
    {
        return new Seeded(['x']);
    }
}

const STAGING = ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging'];

it('seeds a scenario under staging and test', function (array $env) {
    $shoppers = new Shoppers();
    $seeded = Scenarios::of([$shoppers])->seed('shoppers', ['count' => 2], $env);

    expect($seeded->users)->toBe(['user-1', 'user-2'])
        ->and($shoppers->seeded)->toBe(2);
})->with([
    'staging' => [STAGING],
    'test' => [['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing']],
]);

it('refuses to seed a scenario under production, and seeds nothing', function (array $env, string $message) {
    $shoppers = new Shoppers();

    expect(fn () => Scenarios::of([$shoppers])->seed('shoppers', [], $env))->toThrow(\Exception::class, $message)
        ->and($shoppers->seeded)->toBe(0);
})->with([
    'nothing said' => [[], 'production profile'],
    'local, but production\'s profile' => [['TINVAR_ENV' => 'local'], 'production profile'],
    'test beside NODE_ENV=production' => [['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'NODE_ENV' => 'production'], 'NODE_ENV=production'],
]);

it('refuses a class with no #[Scenario] name, and two with one', function () {
    expect(fn () => Scenarios::of([new Unnamed()]))->toThrow(\LogicException::class, 'says no #[Scenario] name')
        ->and(fn () => Scenarios::of([new Shoppers(), new Shoppers()]))->toThrow(\LogicException::class, 'Two scenarios');
});

it('refuses a scenario there is none of', function () {
    Scenarios::of([new Shoppers()])->seed('admins', [], STAGING);
})->throws(\InvalidArgumentException::class, "There is no scenario 'admins'");

it('hands a browser test its users and details, and no credential', function () {
    $seeded = Scenarios::of([new Shoppers()])->seed('shoppers', [], STAGING);

    expect($seeded->forTest())->toBe(['users' => ['user-1'], 'note' => 'made'])
        ->and(print_r($seeded, true))->not->toContain('p-user-1');
});

it('writes the sign-ins to a new private file, and never prints them', function () {
    $directory = PrivateDirectory::create('scenario-seed');
    $file = "{$directory}/credentials.json";
    $said = '';
    try {
        $status = new ScenarioSeedCommand(
            static fn (): Scenarios => Scenarios::of([new Shoppers()]),
            STAGING,
            static function (string $line) use (&$said): void {
                $said .= $line;
            },
            static function (string $line) use (&$said): void {
                $said .= $line;
            },
        )->run(['shoppers', '--input={"count":3}', "--credentials={$file}"]);

        expect($status)->toBe(0)
            ->and($said)->not->toContain('p-user')
            ->and(fileperms($file) & 0o777)->toBe(0o600)
            ->and(json_decode((string) file_get_contents($file), true))->toHaveCount(3)
            ->and(json_decode((string) file_get_contents($file), true)[0])->toBe(['login' => 'user-1@example.test', 'password' => 'p-user-1']);
    } finally {
        PrivateDirectory::remove($directory);
    }
});

it('refuses a credentials file already there, or named relatively, before seeding', function (string $kind) {
    $directory = PrivateDirectory::create('scenario-seed');
    $file = $kind === 'there' ? "{$directory}/planted.json" : 'credentials.json';
    if ($kind === 'there') {
        file_put_contents($file, '');
    }
    $shoppers = new Shoppers();
    try {
        $status = new ScenarioSeedCommand(static fn (): Scenarios => Scenarios::of([$shoppers]), STAGING, static function (): void {}, static function (): void {})
            ->run(['shoppers', "--credentials={$file}"]);

        expect($status)->toBe(ScenarioSeedCommand::USAGE)->and($shoppers->seeded)->toBe(0);
    } finally {
        PrivateDirectory::remove($directory);
    }
})->with(['there', 'relative']);

it('refuses under production at the command line too', function () {
    $status = new ScenarioSeedCommand(static fn (): Scenarios => Scenarios::of([new Shoppers()]), [], static function (): void {}, static function (): void {})
        ->run(['shoppers']);

    expect($status)->toBe(ScenarioSeedCommand::REFUSED);
});
