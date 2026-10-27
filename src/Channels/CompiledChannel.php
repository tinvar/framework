<?php

declare(strict_types=1);

namespace Tinvar\Channels;

use Tinvar\Attributes\Authorize;

/** One #[Channel] class, reflected once. */
final readonly class CompiledChannel
{
    /**
     * @param class-string $class
     * @param list<string> $parameters the pattern's parameter names, in order
     * @param array<string, class-string<\Tinvar\Orm\Model>> $models parameter => model
     * @param array<string, string> $scalars parameter => its declared type, a builtin or a backed enum
     * @param list<Authorize> $checks every #[Authorize] it declares
     * @param list<class-string> $middleware its #[Middleware]
     * @param class-string|null $tenantFrom what its #[TenantFrom] names
     * @param list<\Tinvar\Attributes\Panel> $panels every #[Panel] it declares
     * @param class-string<\Tinvar\Data\Data> $message the Data class each message is
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $pattern,
        public array $parameters,
        public array $models,
        public array $scalars,
        public array $checks,
        public bool $exempt,
        public array $middleware,
        public ?string $tenantFrom,
        public array $panels,
        public bool $publicAccess,
        public string $message,
    ) {}

    public function where(): string
    {
        return "{$this->class} (channel '{$this->name}')";
    }
}
