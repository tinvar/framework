<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * A cache backend. Laravel-style drivers sit behind this.
 *
 * Tags are first-class because revalidation is: an action says which tags it
 * invalidated, and everything holding one has to go.
 */
interface Store
{
    /** @return array{hit: bool, value: mixed} */
    public function get(string $key): array;

    /**
     * @param list<string> $tags
     * @param int|null     $ttlSeconds null never expires on its own
     */
    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void;

    public function forget(string $key): void;

    /**
     * @param list<string> $tags
     * @return int how many entries went, where the store drops them one by
     *         one (ArrayStore). A store shared between workers moves each
     *         tag's version instead and visits no entry, so it cannot say,
     *         and returns 0 (ApcuStore, PdoStore). Nothing in the framework
     *         reads the count.
     */
    public function forgetTags(array $tags): int;

    public function flush(): void;
}
