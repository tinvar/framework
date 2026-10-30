<?php

declare(strict_types=1);

namespace Tinvar\Tests\Calls\Fixtures;

use Tinvar\Services\Service;

/*
 * The service the service calls' tests sign in as, apart from calls.php so
 * the calls' and the tools' own runs never load the services module (ADR
 * 0114).
 */

/** The warehouse's stock system, signed in by its own credential. */
final class Warehouse implements Service
{
    public function __construct(private readonly string $name = 'warehouse') {}

    public function authIdentifier(): string
    {
        return "service:{$this->name}";
    }

    public function serviceName(): string
    {
        return $this->name;
    }
}
