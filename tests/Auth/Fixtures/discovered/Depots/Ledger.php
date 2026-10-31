<?php

declare(strict_types=1);

/*
 * Named like a class, and declaring none: ApplicationClasses must find it
 * by name and never load it (review of ADR 0122).
 */

throw new LogicException('Ledger.php was loaded, and it declares no class.');
