<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * The index could not be read or written (ADR 0152): its table is missing,
 * or the remote backend refused, failed or could not be reached. The
 * message says what to do; it never holds a key or a query's words.
 */
final class SearchFailed extends \RuntimeException {}
