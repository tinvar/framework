<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Cors;
use Tinvar\Attributes\Throttle;
use Tinvar\Routing\CompiledRoute;

/** One #[Get], #[Post] or #[Endpoint] method, reflected once. */
final readonly class CompiledEndpoint
{
    /** It answers with the Response its body returns. */
    public const string RETURNS_RESPONSE = 'response';

    /** It answers with a Data object, sent as JSON through the encoder. */
    public const string RETURNS_DATA = 'data';

    /** It answers with nothing: a 204. */
    public const string RETURNS_NOTHING = 'nothing';

    /**
     * @param class-string $class
     * @param non-empty-list<string> $methods what it answers, HEAD apart: GET brings it
     * @param list<string> $segments 'orders' or '{order}', as a page's are
     * @param list<string> $parameters the path's parameter names, in order
     * @param list<Authorize> $checks the holder's first, then the method's
     * @param int $classLevelChecks how many of $checks, at the front, the holder
     *        declared: they run, and decide nothing for the method (Checks::undecided())
     * @param array<string, class-string<\Tinvar\Orm\Model>> $models path parameter => model
     * @param array<string, string> $scalars path parameter => its declared type, a
     *        builtin or a backed enum
     * @param array<string, class-string<\Tinvar\Validation\FormRequest>> $formRequests parameter => form request
     * @param array<string, class-string<\Tinvar\Orm\Model>> $nestedModels form-request field => model
     * @param array<string, true> $optionalModels form-request model fields that may be left out
     * @param list<class-string> $middleware its #[Middleware], the holder's first
     * @param class-string|null $tenantFrom what its #[TenantFrom] names
     * @param list<\Tinvar\Attributes\Panel> $panels every #[Panel] it declares, the holder's first
     * @param bool $sessionless #[WithoutSession]: no global stack, so no session and no CSRF token
     * @param string $returns one of the RETURNS_* constants
     * @param class-string<\Tinvar\Data\Data>|null $data the Data class it returns, for RETURNS_DATA
     * @param bool $publicAccess #[PublicAccess]: anyone may call it, so what it answers may be
     *        cached as it says; anything else is always `private`
     * @param Throttle|null $throttle its own bucket, spent beside `throttle:endpoints` (ADR 0074)
     * @param Cors|null $cors the other origins that may call it and read its answer (ADR 0074)
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $method,
        public array $methods,
        public string $template,
        public array $segments,
        public array $parameters,
        public array $checks,
        public int $classLevelChecks,
        public array $models,
        public array $scalars,
        public array $formRequests,
        public array $nestedModels,
        public array $optionalModels,
        public array $middleware,
        public bool $exempt,
        public ?string $tenantFrom,
        public array $panels,
        public bool $sessionless,
        public string $returns,
        public ?string $data = null,
        public bool $publicAccess = false,
        public ?Throttle $throttle = null,
        public ?Cors $cors = null,
    ) {}

    /**
     * Every model it takes, by the name a check uses: the path's, then the
     * form requests' fields.
     *
     * @return array<string, class-string<\Tinvar\Orm\Model>>
     */
    public function allModels(): array
    {
        return [...$this->models, ...$this->nestedModels];
    }

    /** Whether it answers $method; HEAD with GET. */
    public function answers(string $method): bool
    {
        return \in_array($method === 'HEAD' ? 'GET' : $method, $this->methods, true);
    }

    /**
     * The methods to name in a 405's Allow: what it declares, and HEAD
     * beside a GET.
     *
     * @return list<string>
     */
    public function allowed(): array
    {
        return \in_array('GET', $this->methods, true) ? [...$this->methods, 'HEAD'] : $this->methods;
    }

    /**
     * Whether a request's method is one that may change nothing: GET and
     * HEAD carry no CSRF token, so their body, and any stream it returns,
     * run read-only. Decided by the request, never by what the endpoint
     * declares -- one answering GET and POST runs a GET read-only and holds
     * a POST to the token, as VerifyCsrfToken, which reads the request too,
     * does.
     */
    public static function isSafeMethod(string $method): bool
    {
        return $method === 'GET' || $method === 'HEAD';
    }

    public function where(): string
    {
        return "{$this->class}::{$this->method}()";
    }

    /**
     * The template with every parameter's name dropped, as CompiledRoute's:
     * the paths it matches depend on where its parameters sit, never on
     * what they are called.
     */
    public function shape(): string
    {
        return '/' . implode('/', array_map(
            static fn (string $segment): string => CompiledRoute::isParameter($segment) ? '{}' : $segment,
            $this->segments,
        ));
    }

    /**
     * Matches a normalized request path, returning its parameters, each
     * decoded as one segment; null for a path it does not match.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $parts = CompiledRoute::split($path);
        if (\count($parts) !== \count($this->segments)) {
            return null;
        }

        $parameters = [];
        foreach ($this->segments as $index => $segment) {
            $part = $parts[$index];
            if (CompiledRoute::isParameter($segment)) {
                if ($part === '') {
                    return null;
                }
                $parameters[trim($segment, '{}')] = rawurldecode($part);
                continue;
            }
            if ($segment !== $part) {
                return null;
            }
        }

        return $parameters;
    }

    /**
     * Whether some path is matched by both these segments and $other's:
     * the same depth, and at every position the same literal or a parameter
     * on at least one side.
     *
     * @param list<string> $other
     */
    public function overlaps(array $other): bool
    {
        if (\count($other) !== \count($this->segments)) {
            return false;
        }

        foreach ($this->segments as $index => $segment) {
            $theirs = $other[$index];
            if (CompiledRoute::isParameter($segment) || CompiledRoute::isParameter($theirs)) {
                continue;
            }
            if ($segment !== $theirs) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ranks this endpoint against another for one path, as pages are
     * ranked: the leftmost literal first, so `/orders/export` is asked
     * before `/orders/{order}` whatever order they registered in.
     */
    public function beats(self $other): int
    {
        foreach ($this->segments as $index => $segment) {
            $mine = CompiledRoute::isParameter($segment);
            $theirs = CompiledRoute::isParameter($other->segments[$index] ?? '');
            if ($mine !== $theirs) {
                return $mine ? 1 : -1;
            }
        }

        return \count($other->segments) <=> \count($this->segments);
    }
}
