<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application's panels, by name, and the namespaces that belong to them.
 *
 * A #[Panel] naming a panel that is not here is refused when the Kernel is
 * built, by the entry checks: a typo in a panel's name would otherwise
 * be a page with no guard, no membership check and no MFA, that looked as if
 * it had all three.
 */
final class PanelRegistry
{
    /** @var array<string, PanelDefinition> */
    private array $panels = [];

    /** @var array<string, string> namespace prefix => panel, longest first */
    private array $namespaces = [];

    public function __construct(PanelDefinition ...$panels)
    {
        foreach ($panels as $panel) {
            if (isset($this->panels[$panel->name])) {
                throw new \InvalidArgumentException("Two panels are named '{$panel->name}'.");
            }
            $this->panels[$panel->name] = $panel;

            foreach ($panel->namespaces as $namespace) {
                if (isset($this->namespaces[$namespace])) {
                    throw new \InvalidArgumentException(
                        "{$namespace} is in two panels, '{$this->namespaces[$namespace]}' and '{$panel->name}'.",
                    );
                }
                $this->namespaces[$namespace] = $panel->name;
            }
        }

        // The most specific prefix first, so `App\Admin\Billing\` can be a
        // panel of its own inside `App\Admin\`.
        uksort($this->namespaces, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
    }

    public function has(string $name): bool
    {
        return isset($this->panels[$name]);
    }

    public function definition(string $name): ?PanelDefinition
    {
        return $this->panels[$name] ?? null;
    }

    /** @return list<PanelDefinition> */
    public function all(): array
    {
        return array_values($this->panels);
    }

    /**
     * The panel a class is in by its namespace, or null.
     *
     * @param class-string $class
     */
    public function panelOf(string $class): ?string
    {
        foreach ($this->namespaces as $namespace => $panel) {
            if (str_starts_with($class, $namespace)) {
                return $panel;
            }
        }

        return null;
    }
}
