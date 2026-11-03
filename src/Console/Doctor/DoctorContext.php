<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Discovery\Discovery;
use Tinvar\Discovery\DiscoveryFailed;
use Tinvar\Orm\Connection;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Routing\Domains;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * What every doctor check reads (ADR 0157): one application, and the
 * environment it would run in -- handed in, never read from the process,
 * so a test gives each check the environment it means.
 */
final readonly class DoctorContext
{
    /**
     * @param string $root the application: its composer.json, package.json, Caddyfile and build
     * @param array<string, string> $env the environment the application would run in
     * @param string $console how a fix names the application's CLI: `bin/tinvar`
     * @param (\Closure(): DiscoveredClasses)|null $classes what the application registers; null for none
     * @param (\Closure(bool): Connection)|null $connect opens the application's database, told whether to make a SQLite file
     * @param string|null $migrations the migrations' directory
     * @param string|null $keyFile where the app key is generated when TINVAR_APP_KEY gives none
     * @param string|null $probeTokenFile where the probe token is generated when TINVAR_PROBE_TOKEN gives none
     * @param Discovery|null $discovery the application's discovery, and its manifest in the build
     * @param (\Closure(): ?Domains)|null $domains the hosts the application's Kernel configures (ADR 0149)
     * @param (\Closure(): int)|null $migrate runs the pending migrations as `migrate` does, for --fix
     * @param string $buildDirectory the application's build
     * @param string|null $projectRoot where composer.json and the lockfiles are, when not the application's root
     * @param (\Closure(): string)|null $database the SQLite file the application opens: its console's
     * @param (\Closure(): ?PanelRegistry)|null $panels the panels the application's Kernel configures, whose hosts hold what is in them
     */
    public function __construct(
        public string $root,
        public array $env,
        public string $console = 'bin/tinvar',
        public ?\Closure $classes = null,
        public ?\Closure $connect = null,
        public ?string $migrations = null,
        public ?string $keyFile = null,
        public ?string $probeTokenFile = null,
        public ?Discovery $discovery = null,
        public ?\Closure $domains = null,
        public ?\Closure $migrate = null,
        public string $buildDirectory = '',
        public ?string $projectRoot = null,
        public ?\Closure $panels = null,
        public ?\Closure $database = null,
    ) {}

    /**
     * What says this environment is a server's -- production's, or
     * staging's, production's build -- as `TINVAR_ENV=production`, or null
     * on a developer's machine: the markers dev mode is refused beside
     * (DevMode), TINVAR_ENV or APP_ENV saying staging, and TINVAR_PROFILE
     * naming either. A fix that is safe only on a developer's machine is
     * offered only where this is null.
     */
    public function production(): ?string
    {
        $marker = DevMode::productionMarker($this->env);
        if ($marker !== null) {
            return "{$marker}=production";
        }
        foreach (['TINVAR_ENV', 'APP_ENV', BuildProfile::ENV] as $variable) {
            $value = strtolower(trim($this->env[$variable] ?? ''));
            if (\in_array($value, [BuildProfile::PRODUCTION, BuildProfile::STAGING], true)) {
                return "{$variable}={$value}";
            }
        }

        return null;
    }

    /**
     * What the application registers, scanned afresh as `tinvar check`
     * scans it -- and, on a server whose build has no discovery manifest
     * yet, scanned from the sources, so the config and the hosts are still
     * checked while the build check reports the manifest. Null where the
     * application hands in none.
     *
     * @throws DiscoveryFailed where the scan itself is refused
     */
    public function classes(): ?DiscoveredClasses
    {
        if ($this->classes === null) {
            return null;
        }
        try {
            return Discovery::freshly($this->classes);
        } catch (DiscoveryFailed $e) {
            if ($this->discovery === null || is_file($this->discovery->manifest)) {
                throw $e;
            }

            return $this->discovery->scan();
        }
    }

    /** A variable's value, null where it is unset or empty. */
    public function variable(string $name): ?string
    {
        $value = $this->env[$name] ?? '';

        return $value === '' ? null : $value;
    }

    /** Where composer.json and the lockfiles are. */
    public function projectRoot(): string
    {
        return $this->projectRoot ?? $this->root;
    }
}
