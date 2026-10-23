<?php

declare(strict_types=1);

namespace Tinvar\Validation;

/**
 * Internal: a value could not become its declared type.
 *
 * Caught by the validator and turned into a field error, so one bad field does
 * not stop the others from being checked.
 */
final class CoercionFailed extends \RuntimeException {}
