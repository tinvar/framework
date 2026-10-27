<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Actions\TagInvalidation;

/**
 * A route that is not a page: a JSON API, a download, a callback.
 *
 *     #[Endpoint('/orders/{order}', methods: ['PUT', 'PATCH']), Authorize('update', 'order')]
 *     public function update(Order $order, UpdateOrderRequest $request): OrderData { ... }
 *
 * On a method of any class the application lists, as an #[Action] is, and
 * run as one is: the class's and the method's tenant, panel and its stack,
 * #[Middleware], then the path's models resolved scoped to the tenant,
 * every #[Authorize], the form requests validated, and the body. It answers
 * with a Response, a Data object -- sent as JSON through the encoder, which
 * refuses anything else -- or nothing, a 204. See Tinvar\Endpoints and ADR
 * 0067.
 *
 * #[Get] and #[Post] are this, for one method each.
 *
 * GET answers HEAD too. OPTIONS cannot be declared: it is answered as a CORS
 * preflight for an endpoint that opts in with #[Cors] (ADR 0074), and is a
 * 405 for everything else, as for a page. A method nobody declared for a path is a 405
 * naming the ones that would do, and a `_method` field or an
 * X-HTTP-Method-Override header is read by nothing -- the method a request
 * arrives with is the method it is.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Endpoint
{
    /** What an endpoint may declare. HEAD comes with GET; OPTIONS, TRACE and CONNECT never. */
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var non-empty-list<string> */
    public array $methods;

    /** @var list<string> */
    public array $revalidates;

    /**
     * @param list<string> $methods
     * @param string|null $name what it is counted, logged and called from
     *        TypeScript by; the class's short name and the method's otherwise
     * @param list<string> $revalidates the tags a call that may write invalidates once
     *        its body has returned, as an action's result names them (ADR 0075): the
     *        cached queries forgotten, every client listening told, every once-shared
     *        hold dropped, every static page showing them taken out of service. Not
     *        on a GET or a HEAD, which write nothing; an endpoint answering only GET
     *        that declares any is refused
     */
    public function __construct(
        public string $path,
        array $methods = ['GET'],
        public ?string $name = null,
        array $revalidates = [],
    ) {
        $normalized = [];
        foreach ($methods as $method) {
            $upper = strtoupper($method);
            if (!\in_array($upper, self::METHODS, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    "An endpoint at '%s' declares the method '%s'. It may declare %s: HEAD is answered with GET, and OPTIONS is never.",
                    $path,
                    $method,
                    implode(', ', self::METHODS),
                ));
            }
            if (!\in_array($upper, $normalized, true)) {
                $normalized[] = $upper;
            }
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException("An endpoint at '{$path}' declares no method.");
        }
        $this->methods = $normalized;

        $this->revalidates = TagInvalidation::declared("An endpoint at '{$path}'", $revalidates);
        if ($this->revalidates !== [] && $normalized === ['GET']) {
            throw new \InvalidArgumentException(
                "An endpoint at '{$path}' revalidates tags and answers only GET, which writes nothing: a GET's body "
                . 'runs read-only. Declare them on the method that writes.',
            );
        }
    }

    /**
     * The route a method declares -- by #[Get], #[Post] or #[Endpoint] --
     * as an #[Endpoint]; null for none. Two on one method are refused: one
     * method answers at one path.
     */
    public static function declaredOn(\ReflectionMethod $method): ?self
    {
        $found = [];
        foreach ($method->getAttributes(Get::class) as $attribute) {
            $get = $attribute->newInstance();
            $found[] = new self($get->path, ['GET'], $get->name);
        }
        foreach ($method->getAttributes(Post::class) as $attribute) {
            $post = $attribute->newInstance();
            $found[] = new self($post->path, ['POST'], $post->name, $post->revalidates);
        }
        foreach ($method->getAttributes(self::class) as $attribute) {
            $found[] = $attribute->newInstance();
        }

        if (\count($found) > 1) {
            throw new \InvalidArgumentException(
                "{$method->class}::{$method->getName()}() declares more than one route. One method answers at one "
                . 'path: list its methods in one #[Endpoint(methods: [...])].',
            );
        }

        return $found[0] ?? null;
    }

    /** Whether a method declares a route at all, by any of the three. */
    public static function marks(\ReflectionMethod $method): bool
    {
        return $method->getAttributes(Get::class) !== []
            || $method->getAttributes(Post::class) !== []
            || $method->getAttributes(self::class) !== [];
    }
}
