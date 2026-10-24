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
     * @param array<string, class-string<\Tinvar\Orm\Model>> $models parameter => model class
     * @param array<string, class-string<\Tinvar\Validation\FormRequest>> $formRequests parameter => form request class
     * @param array<string, class-string<\Tinvar\Orm\Model>> $nestedModels field inside a form request => model class
     * @param string|null $subjectArgument the record this action acts on: the
     *        model named by its first #[Authorize]. Rules that exempt "the row
     *        being edited" read this, so it has to be a declared choice rather
     *        than whichever model happened to be first in a map.
     * @param array<string, true> $optionalModels model inputs that may be absent
     * @param list<class-string>          $middleware
     * @param int $classLevelChecks how many of $checks, at the front, the holder
     *        class declared. They run like the method's own but do not decide
     *        for it -- see Checks::undecided().
     * @param class-string<\Tinvar\Tenancy\TenantSource>|null $tenantFrom what its
     *        #[TenantFrom] names, on the method or the holder class
     * @param list<\Tinvar\Attributes\Panel> $panels every #[Panel] it declares, the
     *        holder's first
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
        public ?string $subjectArgument = null,
        public array $optionalModels = [],
        public int $classLevelChecks = 0,
        public ?string $tenantFrom = null,
        public array $panels = [],
    ) {}

    /**
     * Every model this action takes, by the name a check would use: its own
     * parameters, plus model-typed fields inside its form requests.
     *
     * @return array<string, class-string<\Tinvar\Orm\Model>>
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
