<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Attributes\Authorize;

/** One #[Action] method, reflected once. */
final readonly class CompiledAction
{
    /**
     * @param class-string                $class
     * @param list<Authorize>             $checks
     * @param array<string, class-string> $models      parameter => model class
     * @param array<string, class-string> $formRequests parameter => form request class
     * @param array<string, class-string> $nestedModels field inside a form request => model class
     * @param list<class-string>          $middleware
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $method,
        public array $checks,
        public array $models,
        public array $formRequests,
        public array $nestedModels,
        public array $middleware,
        public bool $exempt,
    ) {}

    /**
     * Every model this action takes, by the name a check would use: its own
     * parameters, plus model-typed fields inside its form requests.
     *
     * @return array<string, class-string>
     */
    public function allModels(): array
    {
        return [...$this->models, ...$this->nestedModels];
    }

    public function where(): string
    {
        return "{$this->class}::{$this->method}()";
    }
}
