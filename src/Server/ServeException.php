<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * Why `tinvar serve` could not start: a bad argument, a port in use, a
 * missing binary or build. The message is for the person at the terminal
 * and says what to do about it.
 */
final class ServeException extends \RuntimeException {}
