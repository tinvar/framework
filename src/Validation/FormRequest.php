<?php

declare(strict_types=1);

namespace Tinvar\Validation;

/**
 * Validated input for an action.
 *
 * Typed properties are the schema, as on models: the PHP type decides what a
 * value may be, and rule attributes add only what the type cannot express.
 * A form request only ever exists in a valid state -- the validator either
 * constructs one or throws.
 */
abstract readonly class FormRequest {}
