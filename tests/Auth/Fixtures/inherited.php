<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Fixtures\Inherited;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Model;
use Tinvar\Validation\FormRequest;

/** #[TenantScoped] written once, on a base, for the models extending it. */
#[TenantScoped]
abstract class TenantModel extends Model {}

#[Table('ledgers')]
final class Ledger extends TenantModel
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

/** And on a trait. */
#[TenantScoped]
trait BelongsToTenant {}

#[Table('invoices')]
final class Invoice extends Model
{
    use BelongsToTenant;

    #[Id] public private(set) string $id;
    #[Column] public string $number;
}

/** Bare ids naming the two. */
final readonly class InheritedIdsRequest extends FormRequest
{
    public function __construct(
        public string $ledgerId,
        public string $invoiceId,
    ) {}
}
