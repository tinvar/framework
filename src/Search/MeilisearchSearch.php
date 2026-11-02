<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Config\Secret;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;

/**
 * The index in Meilisearch (ADR 0154), reached through the application's
 * one OutboundHttp: held to the origin TINVAR_MEILISEARCH_URL names, at
 * every hop, by Destinations, and to the application's #[OutboundPolicy]
 * like any request -- a Meilisearch on a private network is one address
 * the policy allows, never every private address. No redirect is followed.
 *
 * One index per model and tenant, never one index with a tenant filter:
 * `{prefix}{table}` for a model that is not #[TenantScoped], and
 * `{prefix}{table}_t` and the first 32 hex digits of the tenant's SHA-256
 * for each tenant of one that is. A search names the index of the tenant
 * in force -- chosen from the ORM's scope, never from the request -- and
 * so cannot match another tenant's documents whatever it holds: there is
 * no filter string to build, forget or get wrong. A tenant's documents go
 * with its index, one DELETE. What the visitor typed reaches Meilisearch
 * as SearchTerms' words, in the JSON body's `q`, never in a filter.
 *
 * The API key is a Secret, sent as a bearer token and cut out of every
 * error. Each index is told, once per process, which fields it searches,
 * the model's highest weighted first: Meilisearch ranks by that order.
 */
final class MeilisearchSearch implements SearchBackend
{
    /** Seconds a request is given. */
    public const float TIMEOUT = 10.0;

    /** The most of a response read: a page of keys, or an error. */
    public const int MAX_RESPONSE_BYTES = 1_048_576;

    /** The most documents sent in one request. */
    public const int BATCH = 1000;

    private readonly string $origin;

    private readonly Destinations $destinations;

    /** @var array<string, true> the indexes told their settings in this process */
    private array $configured = [];

    public function __construct(
        private readonly OutboundHttp $http,
        string $url,
        #[\SensitiveParameter]
        private readonly ?Secret $key = null,
        private readonly string $prefix = '',
    ) {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('TINVAR_MEILISEARCH_URL is the origin Meilisearch answers at, such as https://search.internal:7700: a scheme, a host and a port, with no path, credentials or query.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{0,64}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException('TINVAR_MEILISEARCH_PREFIX is at most 64 letters, digits, hyphens and underscores.');
        }
        $this->origin = rtrim($url, '/');
        $this->destinations = Destinations::originOf($this->origin);
    }

    public function remote(): bool
    {
        return true;
    }

    /** The index $model's documents in $tenant are kept in. */
    public function indexFor(SearchableModel $model, string $tenant): string
    {
        return $this->prefix . $model->table . ($tenant === '' ? '' : '_t' . substr(hash('sha256', $tenant), 0, 32));
    }

    public function upsert(SearchableModel $model, string $tenant, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $index = $this->indexFor($model, $tenant);
        $this->configure($model, $index);
        foreach (array_chunk($documents, self::BATCH) as $batch) {
            $body = array_map(static fn (SearchDocument $document): array => [
                'id' => $document->id,
                ...array_combine(SearchableModel::CLASSES, $document->classes),
            ], $batch);
            $this->send('POST', '/indexes/' . rawurlencode($index) . '/documents?primaryKey=id', $body);
        }
    }

    public function remove(SearchableModel $model, ?array $tenants, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $indexes = $tenants === null
            ? $this->indexesOf($model)
            : array_map(fn (string $tenant): string => $this->indexFor($model, $tenant), $tenants);
        foreach ($indexes as $index) {
            foreach (array_chunk($ids, self::BATCH) as $batch) {
                $this->send('POST', '/indexes/' . rawurlencode($index) . '/documents/delete-batch', $batch, missingIsEmpty: true);
            }
        }
    }

    public function search(SearchableModel $model, SearchTerms $terms, ?array $tenants, int $limit, int $offset): SearchHits
    {
        if ($terms->isEmpty()) {
            return SearchHits::none();
        }
        $indexes = $tenants === null
            ? $this->indexesOf($model)
            : array_map(fn (string $tenant): string => $this->indexFor($model, $tenant), $tenants);
        if ($indexes === []) {
            return SearchHits::none();
        }

        // One index: one search, paged by Meilisearch. Several -- a subtree,
        // or across tenants -- one federated multi-search over them, ranked
        // together, paged once.
        if (\count($indexes) === 1) {
            $answer = $this->send('POST', '/indexes/' . rawurlencode($indexes[0]) . '/search', [
                'q' => $terms->text(),
                'limit' => $limit,
                'offset' => $offset,
                'attributesToRetrieve' => ['id'],
            ], missingIsEmpty: true);
        } else {
            $answer = $this->send('POST', '/multi-search', [
                'federation' => ['limit' => $limit, 'offset' => $offset],
                'queries' => array_map(static fn (string $index): array => [
                    'indexUid' => $index,
                    'q' => $terms->text(),
                    'attributesToRetrieve' => ['id'],
                ], $indexes),
            ], missingIsEmpty: true);
        }

        $ids = [];
        foreach (\is_array($answer['hits'] ?? null) ? $answer['hits'] : [] as $hit) {
            if (\is_array($hit) && (\is_string($hit['id'] ?? null) || \is_int($hit['id'] ?? null))) {
                $ids[] = (string) $hit['id'];
            }
        }
        $total = $answer['estimatedTotalHits'] ?? $answer['totalHits'] ?? \count($ids);

        return new SearchHits($ids, \is_int($total) ? $total : \count($ids));
    }

    public function flush(SearchableModel $model, ?string $tenant): void
    {
        $indexes = $tenant === null ? $this->indexesOf($model) : [$this->indexFor($model, $tenant)];
        foreach ($indexes as $index) {
            $this->send('DELETE', '/indexes/' . rawurlencode($index), null, missingIsEmpty: true);
            unset($this->configured[$index]);
        }
    }

    /**
     * Every index of $model's there is: the untenanted one and each
     * tenant's, by name.
     *
     * @return list<string>
     */
    private function indexesOf(SearchableModel $model): array
    {
        $base = $this->prefix . $model->table;
        $found = [];
        for ($offset = 0; ; $offset += 1000) {
            $answer = $this->send('GET', "/indexes?limit=1000&offset={$offset}", null);
            $results = \is_array($answer['results'] ?? null) ? $answer['results'] : [];
            foreach ($results as $index) {
                $uid = \is_array($index) && \is_string($index['uid'] ?? null) ? $index['uid'] : null;
                if ($uid !== null && ($uid === $base || preg_match('/^' . preg_quote($base, '/') . '_t[0-9a-f]{32}\z/', $uid) === 1)) {
                    $found[] = $uid;
                }
            }
            if (\count($results) < 1000) {
                return $found;
            }
        }
    }

    /** Tells $index which fields it searches, highest weighted first, once per process. */
    private function configure(SearchableModel $model, string $index): void
    {
        if (isset($this->configured[$index])) {
            return;
        }
        $order = [];
        foreach (SearchableModel::CLASSES as $i => $class) {
            if ($model->weights[$i] > 0) {
                $order[] = $class;
            }
        }
        $this->send('PATCH', '/indexes/' . rawurlencode($index) . '/settings', [
            'searchableAttributes' => $order,
            'displayedAttributes' => ['id'],
        ]);
        $this->configured[$index] = true;
    }

    /**
     * One request, its JSON answer; a 404 for an index there is not, an
     * empty answer where $missingIsEmpty says.
     *
     * @param array<array-key, mixed>|null $body
     * @return array<array-key, mixed>
     * @throws SearchFailed
     */
    private function send(string $method, string $path, ?array $body, bool $missingIsEmpty = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $key = $this->key?->reveal();
        if ($key !== null && $key !== '') {
            $headers['Authorization'] = "Bearer {$key}";
        }

        try {
            $response = $this->http->send(new OutboundRequest(
                $method,
                $this->origin . $path,
                $headers,
                $body === null ? '' : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                timeout: self::TIMEOUT,
                maxResponseBytes: self::MAX_RESPONSE_BYTES,
                destinations: $this->destinations,
            ));
        } catch (OutboundRefused $e) {
            throw new SearchFailed('Meilisearch at ' . $this->origin . ' was refused before anything was sent: ' . $this->scrub($e->getMessage())
                . ' Allow its address in the application\'s #[OutboundPolicy], or correct TINVAR_MEILISEARCH_URL.', 0, $e);
        } catch (OutboundFailed $e) {
            throw new SearchFailed('Meilisearch at ' . $this->origin . ' did not answer: ' . $this->scrub($e->getMessage()), 0, $e);
        }

        if ($response->status === 404 && $missingIsEmpty) {
            return [];
        }
        if (!$response->successful()) {
            throw new SearchFailed(self::failure($response, $this->origin, $this->scrub(...)));
        }
        if ($response->body === '') {
            return [];
        }
        try {
            $decoded = json_decode($response->body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SearchFailed("Meilisearch at {$this->origin} answered {$method} with something other than JSON.");
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param \Closure(string): string $scrub */
    private static function failure(OutboundResponse $response, string $origin, \Closure $scrub): string
    {
        $reason = '';
        $decoded = json_decode($response->body, true);
        if (\is_array($decoded) && \is_string($decoded['message'] ?? null)) {
            $reason = ': ' . mb_substr(str_replace(["\r", "\n"], ' ', $decoded['message']), 0, 300);
        }
        $advice = \in_array($response->status, [401, 403], true)
            ? ' Check TINVAR_MEILISEARCH_KEY: it is an API key with the search, documents and indexes actions.'
            : '';

        return $scrub("Meilisearch at {$origin} answered {$response->status}{$reason}.{$advice}");
    }

    /** $text with the API key cut out of it, wherever it was quoted. */
    private function scrub(string $text): string
    {
        $key = $this->key?->reveal();

        return $key === null || $key === '' ? $text : str_replace($key, Secret::REDACTED, $text);
    }
}
