<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Orm\Connection;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Support\DevMode;

/**
 * The backend the environment names (ADR 0154): TINVAR_SEARCH_BACKEND
 * (SearchConfig), and Meilisearch's own variables where it names that.
 *
 *     $backend = new SearchBackends($outbound)->backend($connection);
 *
 * With nothing set, the application's own database, which needs nothing
 * more: it works in development as it does in production. Meilisearch
 * named without its URL stops the boot, naming the variable; and where
 * production or staging says so, so does a URL that is not https or a
 * missing key -- an index anyone on the network can read and write is
 * not a default.
 */
final readonly class SearchBackends
{
    public function __construct(private OutboundHttp $http) {}

    /**
     * @param array<string, string>|null $env a test's environment; the process's when null
     * @throws ConfigException
     */
    public function backend(Connection $connection, ?array $env = null): SearchBackend
    {
        $config = Configs::load(SearchConfig::class, $env);
        if ($config->backend === SearchDriver::Database) {
            return DatabaseSearch::for($connection);
        }

        $meilisearch = Configs::load(MeilisearchConfig::class, $env);
        if ($meilisearch->url === null || $meilisearch->url === '') {
            throw new ConfigException(['TINVAR_MEILISEARCH_URL is required where TINVAR_SEARCH_BACKEND=meilisearch: name the origin Meilisearch answers at, such as https://search.internal:7700.']);
        }
        $marker = DevMode::notDevelopment($env);
        if ($marker !== null) {
            $problems = [];
            if (!str_starts_with(strtolower($meilisearch->url), 'https://')) {
                $problems[] = "TINVAR_MEILISEARCH_URL must be https where {$marker}: the API key and every document travel in it. Put TLS in front of Meilisearch.";
            }
            if ($meilisearch->key === null || $meilisearch->key->length() === 0) {
                $problems[] = "TINVAR_MEILISEARCH_KEY is required where {$marker}: give Meilisearch a master key, and the application an API key with the search, documents and indexes actions.";
            }
            if ($problems !== []) {
                throw new ConfigException($problems);
            }
        }

        try {
            return new MeilisearchSearch($this->http, $meilisearch->url, $meilisearch->key, $meilisearch->prefix);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException([$e->getMessage()]);
        }
    }

}
