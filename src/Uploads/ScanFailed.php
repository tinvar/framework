<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/** A file could not be scanned: the scanner was not reached, timed out, or answered with an error. */
final class ScanFailed extends \RuntimeException {}
