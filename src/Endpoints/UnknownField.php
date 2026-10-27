<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

/**
 * A Precognition request named a field no form request of the endpoint
 * declares. The client's input, not an author's bug: answered with a 422
 * that does not quote the name back.
 */
final class UnknownField extends \RuntimeException {}
