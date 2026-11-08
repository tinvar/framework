<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Page;
use Tinvar\Orm\Query;
use Tinvar\Orm\TenantBounds;

/**
 * One search of one model (ADR 0154), built from Search::for():
 *
 *     $page = $search->for(Product::class)
 *         ->query($request->query['q'] ?? '')
 *         ->filter(fn (Query $q): Query => $q->where('stock', '>', 0))
 *         ->authorize('view')
 *         ->paginate(page: 1, perPage: 24);
 *
 * Immutable: each method answers a new one. The index is asked for the
 * keys matching the words, best first, in the tenants the ORM's scope
 * holds a read of the model to -- the same answer a query of it reads by
 * (Orm::tenantsInScope()), failing closed with no tenant in force -- and
 * the models are then read again through the ORM by those keys, under
 * that scope, with `filter()`'s constraint, and each kept only where the
 * policy grants `authorize()`'s ability on it. What the index holds can
 * so never show a row the reader could not read: a key since deleted, or
 * one the constraint or the policy refuses, is dropped from the page.
 *
 * A page's total never counts a row the reader cannot see. With neither a
 * constraint nor an ability, it is the index's count of matches, which the
 * index already holds to the reader's tenants; a page may then hold fewer
 * only for a row deleted since it was indexed. With either, the index's
 * count would say how many matches the constraint or the policy hid -- "3
 * results" for a confidential word, showing none -- so the matches are
 * read back, best first, at most MAX_SCANNED of them, and the total is how
 * many of those passed: past MAX_SCANNED matches, a filtered search finds
 * and counts only among the best MAX_SCANNED.
 */
final readonly class SearchQuery
{
    /** The most results one page holds. */
    public const int MAX_PER_PAGE = 100;

    /** The most matches a filtered or authorized search reads back, to count what passes. */
    public const int MAX_SCANNED = 1000;

    /** Matches read back at a time while counting. */
    private const int SCAN_BATCH = 200;

    /**
     * @param (\Closure(Query<Model>): Query<Model>)|null $constraint
     */
    public function __construct(
        private Search $search,
        private Orm $orm,
        private SearchableModel $model,
        private SearchTerms $terms,
        private ?\Closure $constraint = null,
        private ?string $ability = null,
    ) {}

    /** What was typed: read as words alone (SearchTerms), whatever it holds. */
    public function query(string $text): self
    {
        return new self($this->search, $this->orm, $this->model, SearchTerms::parse($text), $this->constraint, $this->ability);
    }

    /**
     * A constraint on the models read back: a closure given the query by
     * their keys, answering it narrowed. It narrows what the index found;
     * it does not widen it.
     *
     * @param \Closure(Query<Model>): Query<Model> $constraint
     */
    public function filter(\Closure $constraint): self
    {
        return new self($this->search, $this->orm, $this->model, $this->terms, $constraint, $this->ability);
    }

    /**
     * Keeps each result only where the model's policy grants $ability on
     * it -- `view`, say -- through the application's Gate.
     */
    public function authorize(string $ability): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $ability) !== 1) {
            throw new \InvalidArgumentException("'{$ability}' is not an ability: a policy method's name.");
        }

        return new self($this->search, $this->orm, $this->model, $this->terms, $this->constraint, $ability);
    }

    /** The words searched for, as SearchTerms read them. */
    public function terms(): SearchTerms
    {
        return $this->terms;
    }

    /**
     * The best $limit results.
     *
     * @return list<Model>
     */
    public function get(int $limit = 20): array
    {
        return $this->paginate(1, $limit)->items;
    }

    /**
     * One page of results, best first, with how many the reader can see
     * (see the class's note on totals). A page past the last, and a search
     * with no words, is empty.
     *
     * @return Page<Model>
     * @throws SearchFailed
     */
    public function paginate(int $page = 1, int $perPage = 20): Page
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Pages count from 1.');
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('A page of search results holds between 1 and ' . self::MAX_PER_PAGE . '.');
        }
        if ($this->terms->isEmpty()) {
            return new Page([], $page, $perPage, 0);
        }
        // Throws NoTenantContext where a read of the model would.
        $tenants = $this->tenants();
        if ($page - 1 > intdiv(\PHP_INT_MAX, $perPage)) {
            return new Page([], $page, $perPage, 0);
        }

        if ($this->constraint === null && $this->ability === null) {
            $hits = $this->search->backend()->search($this->model, $this->terms, $tenants, $perPage, ($page - 1) * $perPage);

            return new Page($this->readBack($hits->ids), $page, $perPage, $hits->total);
        }

        // Filtered: counted from what passes, never from the index.
        $visible = [];
        $scanned = 0;
        while ($scanned < self::MAX_SCANNED) {
            $hits = $this->search->backend()->search($this->model, $this->terms, $tenants, min(self::SCAN_BATCH, self::MAX_SCANNED - $scanned), $scanned);
            if ($hits->ids === []) {
                break;
            }
            array_push($visible, ...$this->readBack($hits->ids));
            $scanned += \count($hits->ids);
            if ($scanned >= $hits->total) {
                break;
            }
        }

        return new Page(\array_slice($visible, ($page - 1) * $perPage, $perPage), $page, $perPage, \count($visible));
    }

    /**
     * The keys of the best $limit matches, best first, from the index alone,
     * in the tenants the ORM's scope holds a read of the model to: for a
     * caller that reads the rows again through the ORM itself, as an admin
     * resource's list does, with its own filters, order and page. A key
     * the index still holds for a row since deleted names nothing that
     * read finds. The constraint and the ability are not applied here.
     *
     * @return list<string>
     * @throws SearchFailed
     */
    public function keys(int $limit = self::MAX_SCANNED): array
    {
        if ($limit < 1 || $limit > self::MAX_SCANNED) {
            throw new \InvalidArgumentException('A search reads between 1 and ' . self::MAX_SCANNED . ' keys.');
        }
        if ($this->terms->isEmpty()) {
            return [];
        }
        // Throws NoTenantContext where a read of the model would.
        $tenants = $this->tenants();

        $keys = [];
        while (\count($keys) < $limit) {
            $hits = $this->search->backend()->search($this->model, $this->terms, $tenants, min(self::SCAN_BATCH, $limit - \count($keys)), \count($keys));
            array_push($keys, ...$hits->ids);
            if ($hits->ids === [] || \count($keys) >= $hits->total) {
                break;
            }
        }

        return $keys;
    }

    /**
     * The models for $ids, read through the ORM under the scope in force,
     * in the index's order, with the constraint and the policy applied.
     *
     * @param list<string> $ids
     * @return list<Model>
     */
    private function readBack(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $meta = $this->model->meta();
        $query = $this->orm->query($this->model->class)->whereIn($meta->idProperty, $ids);
        if ($this->constraint !== null) {
            $query = ($this->constraint)($query);
            if ($query->model() !== $this->model->class) {
                throw new \LogicException('A search filter answers the query it was given, narrowed: a Query of ' . $this->model->class . '.');
            }
        }

        $byKey = [];
        foreach ($query->fetch() as $model) {
            $byKey[$meta->keyOf($model)] = $model;
        }

        $results = [];
        foreach ($ids as $id) {
            $model = $byKey[$id] ?? null;
            if ($model !== null && ($this->ability === null || $this->search->allows($this->ability, $model))) {
                $results[] = $model;
            }
        }

        return $results;
    }

    /**
     * The tenants the index is asked in: the ORM's scope's, '' for a model
     * that is not #[TenantScoped], null across tenants. Refused past
     * TenantBounds::MAX_LISTED (ADR 0196): a backend asks by tenant -- an
     * IN list, an index each -- and a search of part of a subtree would be
     * a silently wrong answer.
     *
     * @return list<string>|null
     * @throws \LengthException for a subtree of more tenants than a list holds
     */
    private function tenants(): ?array
    {
        // Throws NoTenantContext where a read of the model would.
        $tenants = $this->model->tenantScoped() ? $this->orm->tenantsInScope($this->model->meta()) : [''];
        if ($tenants !== null && \count($tenants) > TenantBounds::MAX_LISTED) {
            throw new \LengthException(\sprintf(
                'A search reads at most %d tenants, and the subtree in force holds %d: search a page of it at a time, with withinSubtreePages().',
                TenantBounds::MAX_LISTED,
                \count($tenants),
            ));
        }

        return $tenants;
    }
}
