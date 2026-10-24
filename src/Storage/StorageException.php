<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/** A store that could not do what it was asked: a disk full, a directory it may not write. */
final class StorageException extends \RuntimeException {}
