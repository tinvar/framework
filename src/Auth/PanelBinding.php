<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Panel;

/**
 * The panel an entry point resolved to, and everything a membership has to
 * satisfy to enter it: every tenant kind and permission its declarations and
 * the panel's definition name, together.
 *
 * Together because a panel only narrows. A page's #[Panel] that names a
 * permission does not replace its route group's, nor the definition's: each
 * was written by someone restricting something, and keeping only the nearest
 * would undo the others.
 */
final readonly class PanelBinding
{
    /**
     * @param list<string> $kinds tenant kinds the membership must be, by name
     * @param list<string> $permissions permissions it must hold, all of them
     */
    public function __construct(
        public string $name,
        public array $kinds = [],
        public array $permissions = [],
    ) {}

    /**
     * One binding from the declarations of one entry point -- a route chain's
     * pages and layouts, an action's method and class -- or null for none.
     *
     * @param list<Panel> $declared
     * @throws \LogicException when they name different panels: an entry point is in one
     */
    public static function of(array $declared): ?self
    {
        if ($declared === []) {
            return null;
        }

        $name = $declared[0]->name;
        $kinds = [];
        $permissions = [];
        foreach ($declared as $panel) {
            if ($panel->name !== $name) {
                throw new \LogicException(
                    "Declared in both panel '{$name}' and panel '{$panel->name}'. An entry point is in one panel.",
                );
            }
            if ($panel->requires !== null) {
                $kinds[] = Panel::kindName($panel->requires);
            }
            if ($panel->permission !== null) {
                $permissions[] = $panel->permission;
            }
        }

        return new self($name, array_values(array_unique($kinds)), array_values(array_unique($permissions)));
    }

    /** The same binding with what the panel's definition requires of everyone in it. */
    public function within(PanelDefinition $definition): self
    {
        $kinds = $definition->requires === null ? $this->kinds : [...$this->kinds, Panel::kindName($definition->requires)];
        $permissions = $definition->permission === null ? $this->permissions : [...$this->permissions, $definition->permission];

        return new self($this->name, array_values(array_unique($kinds)), array_values(array_unique($permissions)));
    }

    /** Whether entering needs anything of a membership beyond holding one. */
    public function narrows(): bool
    {
        return $this->kinds !== [] || $this->permissions !== [];
    }
}
