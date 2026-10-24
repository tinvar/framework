<?php

declare(strict_types=1);

namespace Tinvar\Testing;

/** Thrown inside TestDatabase::rollback()'s transaction so that it is never committed. */
final class RolledBack extends \RuntimeException {}
