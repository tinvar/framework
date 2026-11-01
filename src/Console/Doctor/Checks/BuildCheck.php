<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Discovery\DiscoveryFailed;
use Tinvar\Support\BuildProfile;

/**
 * The build, and discovery's manifest in it (ADR 0146): on a server, a
 * build made for the profile it serves (BuildProfile::assertBuild(), as
 * the front controller refuses one), its fragment manifest, and a
 * discovery manifest listing exactly the classes a scan finds -- what
 * production boots from without a scan. On a developer's machine, where
 * `bin/dev` renders from source, a build is optional; one there is still
 * held to being fresh.
 */
final readonly class BuildCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'The build and discovery';
    }

    public function run(DoctorContext $context): array
    {
        $directory = $context->buildDirectory !== '' ? $context->buildDirectory : "{$context->root}/dist";
        $production = $context->production();
        $built = is_file("{$directory}/" . BuildProfile::BUILD_FILE);

        if (!$built) {
            return $production === null
                ? [Finding::passed("No build in {$directory}: `bin/dev` renders from source; `{$context->console} build` makes one to serve.")]
                : [Finding::problem("No build in {$directory}, and {$production}: there is nothing to serve.", "`{$context->console} build` (the image's Dockerfile runs it), then deploy what it wrote.")];
        }

        $findings = [];
        $profile = strtolower(trim($context->env[BuildProfile::ENV] ?? '')) ?: BuildProfile::PRODUCTION;
        if (\in_array($profile, BuildProfile::PROFILES, true)) {
            try {
                BuildProfile::assertBuild($profile, $directory);
            } catch (\RuntimeException $e) {
                $findings[] = ($production !== null ? Finding::problem(...) : Finding::warning(...))($e->getMessage(), "`{$context->console} build --profile={$profile}`.");
            }
        }
        if (!is_file("{$directory}/fragments.json")) {
            $findings[] = Finding::problem("{$directory}/fragments.json is missing: no render may defer a boundary or call a query.", "`{$context->console} build:fragments` (or `{$context->console} build`, which writes it).");
        }

        if ($context->discovery !== null) {
            if (!is_file($context->discovery->manifest)) {
                $findings[] = ($production !== null ? Finding::problem(...) : Finding::warning(...))(
                    "{$context->discovery->manifest} is missing: production boots from it, without a scan.",
                    "`{$context->console} discover` (or `{$context->console} build`, which writes it).",
                );
            } else {
                try {
                    foreach ($context->discovery->stale() as $difference) {
                        $findings[] = Finding::problem("Discovery's manifest is stale: {$difference}.", "`{$context->console} discover` writes it again, as `{$context->console} build` does.");
                    }
                } catch (DiscoveryFailed $e) {
                    $findings[] = Finding::problem("Discovery refused the app's classes:\n" . implode("\n", $e->problems), "Fix each class it names; `{$context->console} check` names them too.");
                }
            }
        }

        return $findings === [] ? [Finding::passed("The build in {$directory} is made for the {$profile} profile, with its fragment manifest and a current discovery manifest.")] : $findings;
    }
}
