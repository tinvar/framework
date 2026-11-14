<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Dev\DevToolbar;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * What the production profile refuses, asked before the server is: dev
 * mode and the dev toolbar wherever TINVAR_ENV does not say local,
 * development or testing (DevMode, DevToolbar, ADR 0213), a profile the environment does not allow
 * (BuildProfile::resolve()), a browser test run's variables, and loopback
 * let into the probes where the renderers share it
 * (TINVAR_PROBE_TRUST_LOOPBACK). Each is the framework's own refusal,
 * said here with its fix.
 */
final readonly class ProductionCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Production refusals';
    }

    public function run(DoctorContext $context): array
    {
        $env = $context->env;
        $findings = [];

        try {
            $dev = DevMode::fromEnvironment($env);
        } catch (\RuntimeException $e) {
            $findings[] = Finding::problem($e->getMessage(), 'Unset TINVAR_DEV on the server; `php tinvar serve --dev` is the only thing that sets it.');
            $dev = false;
        }
        // Asked only where it would refuse: on a developer's machine it
        // reads the running toolbar's key, which is no business of this.
        if (DevMode::notDevelopment($env) !== null) {
            try {
                DevToolbar::fromEnvironment(static fn (): string => '', $env, $dev);
            } catch (\RuntimeException $e) {
                $findings[] = Finding::problem($e->getMessage(), 'Unset ' . DevToolbar::ENV . ' on the server.');
            }
        }
        try {
            $profile = BuildProfile::resolve($env[BuildProfile::ENV] ?? '', $dev, $env);
        } catch (\RuntimeException $e) {
            $findings[] = Finding::problem($e->getMessage(), 'Unset the variable it names: a production server serves the production profile, and a staging one sets TINVAR_ENV=staging beside TINVAR_PROFILE=staging.');
            $profile = null;
        }

        $production = $context->production();
        if ($production !== null) {
            // The profile's own refusal above says TINVAR_TEST_RUN; the image
            // refuses this one as well.
            if (($env[BuildProfile::BUILD_DIR_ENV] ?? '') !== '') {
                $findings[] = Finding::problem(BuildProfile::BUILD_DIR_ENV . " is set, and {$production}: a server serves its own build, dist/, and the image refuses to start with it.", 'Unset ' . BuildProfile::BUILD_DIR_ENV . '.');
            }
            if (($env['TINVAR_PROBE_TRUST_LOOPBACK'] ?? '') === '1') {
                $findings[] = Finding::problem(
                    "TINVAR_PROBE_TRUST_LOOPBACK=1, and {$production}: the renderers -- and a TLS terminator on the same machine -- connect from loopback, so /_health, /_ready and /_metrics would be anyone's who reaches them through it.",
                    'Set TINVAR_PROBE_TRUST_LOOPBACK=0, and send the probe token (`Authorization: Bearer ...`) from the monitor.',
                );
            }
        }

        if ($findings === []) {
            $findings[] = Finding::passed($production === null
                ? 'Not production: dev mode ' . ($dev ? 'is on' : 'is off') . ', and nothing the production profile refuses is set.'
                : "Serving the {$profile} profile, with nothing it refuses set.");
        }

        return $findings;
    }
}
