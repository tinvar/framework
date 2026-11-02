<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\Secret;

/**
 * Meilisearch's settings (ADR 0154): TINVAR_MEILISEARCH_URL, the origin it
 * answers at; TINVAR_MEILISEARCH_KEY -- or _KEY_FILE -- an API key with the
 * search, documents and indexes actions, never the master key; and
 * TINVAR_MEILISEARCH_PREFIX, put before every index's name, for several
 * applications or environments on one instance.
 *
 * Read only where TINVAR_SEARCH_BACKEND names meilisearch. Where production
 * or staging says so, the URL is https and the key is set, or the boot
 * stops naming the variable (SearchBackends).
 */
#[Config(prefix: 'TINVAR_MEILISEARCH_')]
final readonly class MeilisearchConfig
{
    public function __construct(
        public ?string $url = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $key = null,
        public string $prefix = '',
    ) {}
}
