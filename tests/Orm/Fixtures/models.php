<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Attributes\BelongsTo;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\HasMany;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Table;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Model;

trait HasUnits
{
    /** @var Collection<Unit> */
    #[HasMany(Unit::class, orderBy: 'position')]
    public Collection $units;
}

#[Table('sites')]
final class Site extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;
}

#[Table('racks')]
final class Rack extends Model
{
    use HasUnits;

    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $heightU;

    #[BelongsTo] public Site $site;

    public string $label {
        get => "{$this->site->code}-{$this->name}";
    }
}

#[Table('units')]
final class Unit extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public int $position;
    #[Column] public string $name;
}
