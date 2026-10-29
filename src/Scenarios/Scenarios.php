<?php

declare(strict_types=1);

namespace Tinvar\Scenarios;

use Tinvar\Config\FrameworkConfig;
use Tinvar\Attributes\Scenario;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;
use Tinvar\Support\KnownAttributes;

/**
 * An application's `#[Scenario]` classes, by name, and the one way to seed
 * one (ADR 0090): under a profile that allows scenario users -- staging or
 * test -- and beside no production marker. Production never holds an
 * account a load test knows the password of, or one a browser test may be
 * signed in as.
 */
final readonly class Scenarios
{
    /** @param array<string, SeedsScenario> $scenarios */
    private function __construct(private array $scenarios) {}

    /**
     * @param iterable<SeedsScenario> $scenarios each carrying #[Scenario]
     * @throws \LogicException for a class without the attribute, or two with one name
     */
    public static function of(iterable $scenarios): self
    {
        $named = [];
        foreach ($scenarios as $scenario) {
            $class = new \ReflectionClass($scenario);
            KnownAttributes::check($class);
            $attributes = $class->getAttributes(Scenario::class);
            if (\count($attributes) !== 1) {
                throw new \LogicException("{$class->getName()} seeds a scenario and says no #[Scenario] name.");
            }
            $name = $attributes[0]->newInstance()->name;
            if (isset($named[$name])) {
                throw new \LogicException("Two scenarios are called '{$name}'.");
            }
            $named[$name] = $scenario;
        }

        return new self($named);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->scenarios);
    }

    /**
     * Seeds the scenario called $name.
     *
     * @param array<string, mixed> $input
     * @param array<string, string>|null $env the environment the profile is read from; the process's own by default
     * @throws \LogicException where this profile, or a production marker, refuses scenario users
     * @throws \InvalidArgumentException for a scenario there is none of, or input it refuses
     */
    public function seed(string $name, array $input, ?array $env = null): Seeded
    {
        $profile = $env === null ? FrameworkConfig::load()->buildProfile() : BuildProfile::fromEnvironment($env);
        $env ??= array_filter(getenv(), \is_string(...));
        if (!BuildProfile::allowsScenarios($profile)) {
            throw new \LogicException(
                "Seeding a scenario is refused under the {$profile} profile: scenario users are seeded into staging "
                . 'and test runs alone.',
            );
        }
        // Staging's own check already refuses TINVAR_ENV and APP_ENV saying
        // production; NODE_ENV is held here too, as for every other seeding.
        $marker = DevMode::productionMarker($env);
        if ($marker !== null && $profile !== BuildProfile::STAGING) {
            throw new \LogicException("Seeding a scenario is refused: {$marker}=production.");
        }

        $scenario = $this->scenarios[$name] ?? throw new \InvalidArgumentException(
            "There is no scenario '{$name}': there is " . ($this->scenarios === [] ? 'none' : implode(', ', $this->names())) . '.',
        );

        return $scenario->seed($input);
    }
}
