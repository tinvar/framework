<?php

declare(strict_types=1);

/**
 * The demo staff account's password is in the repository, so a shop that
 * says it is production refuses to boot while that password still signs
 * the account in; and the seed can leave the account out, as the
 * Kubernetes demo seed does.
 */

use Shop\Database\Seeders\Seeder;
use Shop\Support\StaffAccount;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\PrivateDirectory;

function demoStaffDatabase(bool $staff): PdoConnection
{
    $connection = PdoConnection::sqlite(PrivateDirectory::create('tinvar-demostaff') . '/shop.sqlite', 'shop', create: true);
    new Seeder($connection)->run(customers: 1, staff: $staff);

    return $connection;
}

it('refuses to boot in production while the published staff password still signs the account in', function () {
    $connection = demoStaffDatabase(staff: true);

    expect(static fn () => StaffAccount::refuseInProduction($connection, ['TINVAR_ENV' => 'production']))
        ->toThrow(\RuntimeException::class, StaffAccount::EMAIL)
        // Not production: the demo account is what it is for.
        ->and(StaffAccount::refuseInProduction($connection, ['TINVAR_ENV' => 'local']))->toBeNull();

    // Its password changed: production boots.
    $connection->execute('UPDATE customers SET password_hash = ? WHERE email = ?', [password_hash('something only the owner knows', PASSWORD_BCRYPT, ['cost' => 4]), StaffAccount::EMAIL]);
    expect(StaffAccount::refuseInProduction($connection, ['TINVAR_ENV' => 'production']))->toBeNull();
});

it('seeds without the staff account when asked, and production boots on it', function () {
    $connection = demoStaffDatabase(staff: false);

    expect($connection->select('SELECT 1 FROM customers WHERE email = ?', [StaffAccount::EMAIL]))->toBe([])
        ->and($connection->select('SELECT COUNT(*) AS n FROM staff')[0]['n'])->toBe(0)
        ->and(StaffAccount::refuseInProduction($connection, ['APP_ENV' => 'production']))->toBeNull();
});

it('leaves the staff account out of the Kubernetes demo seed', function () {
    $job = (string) file_get_contents(dirname(__DIR__, 4) . '/examples/shop/kubernetes/demo/seed.yaml');

    expect($job)->toContain('--staff=no');
});
