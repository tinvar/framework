<?php

declare(strict_types=1);

/**
 * What the query builder's identifier check does and does not promise.
 *
 * It keeps anything outside the compiled metadata out of SQL. It does not
 * know which mapped columns a page exposes, so it accepts a sort by one no
 * Data class shows -- and a sort is an oracle for that column's value. These
 * pin the contract the comments on Orm::identifier() and
 * ModelMeta::knownIdentifiers() now state: the allowlist is the caller's.
 */

namespace Tinvar\Tests\Orm\IdentifierContract;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

#[Table('accounts')]
final class Account extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public string $apiToken;
}

final readonly class AccountListRequest extends FormRequest
{
    public function __construct(#[In('name')] public string $sort = 'name') {}
}

function accounts(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE accounts (id TEXT PRIMARY KEY, name TEXT NOT NULL, api_token TEXT NOT NULL)');
    foreach ([['victim', 'm-secret'], ['probe-a', 'a-known'], ['probe-z', 'z-known']] as [$name, $token]) {
        $pdo->prepare('INSERT INTO accounts VALUES (?, ?, ?)')->execute([Uuid::v7(), $name, $token]);
    }

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

/** @param list<Account> $accounts @return list<string> */
function names(array $accounts): array
{
    return array_map(static fn (Account $a): string => $a->name, $accounts);
}

it('sorts by any mapped column, one no page shows included', function () {
    expect(names(accounts()->all(Account::class, 'api_token')))->toBe(['probe-a', 'victim', 'probe-z']);
});

it('refuses a column the model does not map, or a malformed identifier', function (string $column) {
    expect(fn () => accounts()->all(Account::class, $column))->toThrow(MappingException::class);
})->with(['password', 'name; DROP TABLE accounts', 'name DESC', '"api_token"']);

it('leaves the allowlist to the caller, as an #[In] list on the sort field', function () {
    $validator = new Validator();

    expect($validator->validate(AccountListRequest::class, ['sort' => 'name'])->sort)->toBe('name')
        ->and(fn () => $validator->validate(AccountListRequest::class, ['sort' => 'api_token']))
        ->toThrow(ValidationException::class);
});
