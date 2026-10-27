<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * Thrown inside an import's transaction once its rows are read, when any
 * was refused: what rolls back the rows that were applied.
 *
 * @internal
 */
final class RowsRefused extends \RuntimeException {}
