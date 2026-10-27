<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * An export that read more rows than it declares it writes: stopped there,
 * and nothing stored.
 *
 * @internal
 */
final class TooManyRows extends \RuntimeException {}
