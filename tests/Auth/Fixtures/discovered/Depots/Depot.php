<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\Discovered\Depots;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Model;

/**
 * A tenant-scoped model in a namespace of its own: on no list, and beside
 * no model an entry point takes. Loaded by the test's autoloader alone, as
 * an application's composer.json would load it (ADR 0122).
 */
#[Table('depots'), TenantScoped]
final class Depot extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}
