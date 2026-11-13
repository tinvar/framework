<?php

declare(strict_types=1);

/**
 * The supply chain, checked with nothing fetched.
 *
 * "Packages are enabled only from an explicit allowlist in typed config,
 * with a lockfile. An undeclared package with framework metadata fails the
 * build." "Dependencies are pinned by integrity hash. Downstream app test
 * suites ... run in framework CI only on trusted branches, never on pull
 * requests from forks" (tinvar-spec.md).
 */

namespace Tinvar\Tests\Packages\SupplyChain;

use Symfony\Component\Yaml\Yaml;
use Tinvar\Packages\AllowedPackage;
use Tinvar\Packages\Ecosystem;
use Tinvar\Packages\Lockfiles;
use Tinvar\Packages\PackageLock;
use Tinvar\Packages\PackagePolicy;
use Tinvar\Packages\SupplyChain;
use Tinvar\Packages\TreeHash;
use Tinvar\Tests\Packages\Fixtures\Workspace;

require_once __DIR__ . '/Fixtures/Workspace.php';

beforeEach(function () {
    $this->workspace = new Workspace();
});

afterEach(function () {
    $this->workspace->remove();
});

/** @return list<string> */
function violations(Workspace $workspace, ?PackagePolicy $policy = null): array
{
    return (new SupplyChain($workspace->write()->root, $policy ?? new PackagePolicy()))->check();
}

function billing(): PackagePolicy
{
    return new PackagePolicy(packages: [new AllowedPackage('acme/billing', Ecosystem::Composer)]);
}

function lockFor(Workspace $workspace, PackagePolicy $policy): void
{
    $lockfiles = new Lockfiles($workspace->root);
    PackageLock::of($policy, [...$lockfiles->composer(), ...$lockfiles->npm()])->write($workspace->root);
}

it('passes a tree that is pinned, in step and declares nothing', function () {
    expect(violations($this->workspace))->toBe([]);
});

it('passes the repository itself under the shop policy', function () {
    $root = \dirname(__DIR__, 4);

    expect((new SupplyChain($root, \Shop\Kernel::packages()))->check())->toBe([]);
})->skip(fn () => !is_dir(\dirname(__DIR__, 4) . '/node_modules'), 'needs npm ci');

describe('framework packages', function () {
    it('refuses a Composer package with framework metadata the policy does not name', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => ['codemods' => []]]]);

        expect(violations($this->workspace))
            ->toBe(['composer.lock acme/billing carries framework metadata and is not in PackagePolicy::$packages.']);
    });

    // The lock recorded none, the files installed under vendor/ do: either
    // is enough for something to treat it as Tinvar's.
    it('refuses one whose installed composer.json alone carries it', function () {
        $this->workspace->composer('acme/billing');
        $this->workspace->write();
        $this->workspace->json('vendor/acme/billing/composer.json', ['name' => 'acme/billing', 'extra' => ['tinvar' => true]]);

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())
            ->toBe(['composer.lock acme/billing carries framework metadata and is not in PackagePolicy::$packages.']);
    });

    it('refuses an npm package with framework metadata the policy does not name', function () {
        $this->workspace->npm('@acme/widgets', installed: ['tinvar' => ['codemods' => []]]);

        expect(violations($this->workspace))
            ->toBe(['package-lock.json node_modules/@acme/widgets carries framework metadata and is not in PackagePolicy::$packages.']);
    });

    it('refuses an allowed package that tinvar.lock does not pin', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => []]]);

        expect(violations($this->workspace, billing()))
            ->toBe(['acme/billing is not in tinvar.lock: review it, then run tinvar packages:lock.']);
    });

    it('passes an allowed package once it is locked', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => []]]);
        lockFor($this->workspace->write(), billing());

        expect(violations($this->workspace, billing()))->toBe([]);
    });

    it('refuses an allowed package whose installed files changed since it was locked', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => []]]);
        lockFor($this->workspace->write(), billing());
        $this->workspace->file('vendor/acme/billing/src/Thing.php', "<?php\nexec('curl evil');\n");

        expect((new SupplyChain($this->workspace->root, billing()))->check())->toBe([
            'acme/billing is not what tinvar.lock pins (its installed files changed): review the change, then run tinvar packages:lock.',
        ]);
    });

    it('refuses an allowed package moved to another version or commit', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => []]]);
        lockFor($this->workspace->write(), billing());

        $other = str_repeat('b', 40);
        array_pop($this->workspace->composerPackages);
        $this->workspace->composer('acme/billing', [
            'version' => '1.1.0',
            'extra' => ['tinvar' => []],
            'dist' => ['type' => 'zip', 'url' => "https://api.github.com/repos/acme/billing/zipball/{$other}", 'reference' => $other],
        ]);

        expect(violations($this->workspace, billing()))->toBe([
            'acme/billing is not what tinvar.lock pins (version 1.0.0 is now 1.1.0, its lockfile pin changed): review the change, then run tinvar packages:lock.',
        ]);
    });

    it('refuses an allowed package that is not installed', function () {
        expect(violations($this->workspace, billing()))
            ->toBe(['acme/billing is allowed but not in the composer lockfile.']);
    });

    it('refuses a lock entry for a package the policy no longer allows', function () {
        $this->workspace->composer('acme/billing', ['extra' => ['tinvar' => []]]);
        lockFor($this->workspace->write(), billing());
        array_pop($this->workspace->composerPackages);

        expect(violations($this->workspace))->toBe([
            'tinvar.lock pins composer:acme/billing, which PackagePolicy::$packages does not allow: run tinvar packages:lock.',
        ]);
    });

    it('hashes a link as its target, without following it', function () {
        $this->workspace->file('pkg/a.php', 'a');
        $before = TreeHash::of($this->workspace->root . '/pkg');
        symlink('/etc/hosts', $this->workspace->root . '/pkg/link');
        $linked = TreeHash::of($this->workspace->root . '/pkg');
        unlink($this->workspace->root . '/pkg/link');
        symlink('/etc/passwd', $this->workspace->root . '/pkg/link');

        expect($linked)->not->toBe($before)
            ->and(TreeHash::of($this->workspace->root . '/pkg'))->not->toBe($linked);
    });
});

describe('pins', function () {
    it('refuses an npm package with no sha512 integrity', function () {
        $this->workspace->npm('old', ['integrity' => 'sha1-abc=']);
        $this->workspace->npm('none', ['integrity' => null]);

        expect(violations($this->workspace))->toBe([
            'package-lock.json node_modules/old has no sha512 integrity.',
            'package-lock.json node_modules/none has no sha512 integrity.',
        ]);
    });

    it('refuses an npm package from a host the policy does not name, or over http', function () {
        $this->workspace->npm('elsewhere', ['resolved' => 'https://registry.npmjs.org.evil.example/elsewhere.tgz']);
        $this->workspace->npm('plain', ['resolved' => 'http://registry.npmjs.org/plain/-/plain-1.3.0.tgz']);

        expect(violations($this->workspace))->toBe([
            'package-lock.json node_modules/elsewhere resolves from https://registry.npmjs.org.evil.example/elsewhere.tgz, which PackagePolicy::$registries does not allow.',
            'package-lock.json node_modules/plain resolves from http://registry.npmjs.org/plain/-/plain-1.3.0.tgz, which PackagePolicy::$registries does not allow.',
        ]);
    });

    it('accepts a host the policy names', function () {
        $this->workspace->npm('kit', ['resolved' => 'https://design.example/packages/kit-1.0.0.tgz']);

        expect(violations($this->workspace, new PackagePolicy(registries: [PackagePolicy::NPM_REGISTRY, 'https://design.example/packages/'])))
            ->toBe([]);
    });

    it('accepts a tarball inside the project, held to its sha512, and refuses one anywhere else', function () {
        $this->workspace->npm('@acme/packed', ['resolved' => 'file:.packages/acme-packed-0.4.0.tgz']);
        $this->workspace->npm('outside', ['resolved' => 'file:../elsewhere/outside-1.0.0.tgz']);
        $this->workspace->npm('absolute', ['resolved' => 'file:/tmp/absolute-1.0.0.tgz']);
        $this->workspace->npm('unpinned', ['resolved' => 'file:.packages/unpinned-1.0.0.tgz', 'integrity' => null]);

        expect(violations($this->workspace))->toBe([
            'package-lock.json node_modules/outside installs file:../elsewhere/outside-1.0.0.tgz, which is no tarball inside the project.',
            'package-lock.json node_modules/absolute installs file:/tmp/absolute-1.0.0.tgz, which is no tarball inside the project.',
            'package-lock.json node_modules/unpinned has no sha512 integrity.',
        ]);
    });

    // ADR 0138: a package no registry serves yet -- @tinvar/*, whose scope
    // nobody has claimed -- is whatever anyone published under its name
    // there. It comes from inside the project or not at all.
    it('refuses an unpublished package resolved from a registry, or left for npm to fetch', function () {
        $policy = new PackagePolicy(unpublished: ['@tinvar/*', 'solo']);
        $this->workspace->packageJson['dependencies'] += ['@tinvar/react' => '0.4.x', '@tinvar/renderer' => 'file:.packages/tinvar-renderer-0.4.0.tgz', 'solo' => '1.0.0', '@tinvar/link' => '*', '@other/kit' => '1.0.0'];
        $this->workspace->npm('@tinvar/renderer', ['resolved' => 'file:.packages/tinvar-renderer-0.4.0.tgz']);
        $this->workspace->npm('solo');
        $this->workspace->npm('@tinvar/link', ['resolved' => 'packages/link', 'link' => true, 'integrity' => null]);
        $this->workspace->npm('@other/kit');

        expect(violations($this->workspace, $policy))->toBe([
            'package.json depends on @tinvar/react, which PackagePolicy::$unpublished says no registry serves yet, and package-lock.json does not install it: '
                . 'npm would fetch it from the registry, where its name is anyone\'s. Put its `npm pack` tarball inside the project and depend on it as file:, then npm install --ignore-scripts.',
            'package-lock.json node_modules/solo resolves from https://registry.npmjs.org/solo/-/solo-1.3.0.tgz, and PackagePolicy::$unpublished says no registry serves solo yet: '
                . 'whatever is published under its name is anyone\'s. Install it from a tarball inside the project (file:) or a workspace.',
        ]);
    });

    it('takes a name or a whole scope as unpublished, and nothing looser', function (string $entry) {
        new PackagePolicy(unpublished: [$entry]);
    })->with(['*', '@tinvar', '@tinvar/', 'Tinvar', '@tinvar/re*'])->throws(\InvalidArgumentException::class, 'is not an npm package name or @scope/*');

    it('refuses a Composer package pinned to a tag rather than a commit', function () {
        $this->workspace->composer('acme/tagged', [
            'source' => ['type' => 'git', 'url' => 'https://github.com/acme/tagged.git', 'reference' => 'v1.0.0'],
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/tagged/zipball/v1.0.0', 'reference' => 'v1.0.0', 'shasum' => ''],
        ]);

        expect(violations($this->workspace))->toBe(['composer.lock acme/tagged is not pinned to a commit or checksum.']);
    });

    // Composer fetches a dist URL as written, but for the GitHub archive
    // URLs it rewrites to the dist's reference and a `%reference%`
    // placeholder, and checks the bytes only against a shasum. A release
    // asset on github.com, an allowed host, named beside a full commit,
    // passed as pinned while whoever owns the release could replace it.
    it('refuses a Composer archive its commit does not pin, without a shasum', function () {
        $this->workspace->composer('acme/released', [
            'dist' => ['type' => 'zip', 'url' => 'https://github.com/acme/released/releases/download/v1.0.0/released.zip', 'reference' => Workspace::COMMIT, 'shasum' => ''],
        ]);
        $this->workspace->composer('acme/mirrored', [
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/acme/mirrored/zipball/' . Workspace::COMMIT,
                'reference' => Workspace::COMMIT,
                'shasum' => '',
                'mirrors' => [['url' => 'https://github.com/acme/mirrored/raw/main/mirrored.zip', 'preferred' => true]],
            ],
        ]);
        $this->workspace->composer('acme/archived', [
            'dist' => ['type' => 'zip', 'url' => 'https://github.com/acme/archived/archive/v1.0.0.zip', 'reference' => Workspace::COMMIT, 'shasum' => ''],
        ]);
        $this->workspace->composer('acme/checked', [
            'dist' => ['type' => 'zip', 'url' => 'https://github.com/acme/checked/releases/download/v1.0.0/checked.zip', 'reference' => Workspace::COMMIT, 'shasum' => str_repeat('a', 40)],
        ]);

        expect(violations($this->workspace))->toBe([
            'composer.lock acme/released downloads https://github.com/acme/released/releases/download/v1.0.0/released.zip as written, which its commit does not pin: give the dist its shasum, or a URL Composer fetches by the commit.',
            'composer.lock acme/mirrored downloads https://github.com/acme/mirrored/raw/main/mirrored.zip as written, which its commit does not pin: give the dist its shasum, or a URL Composer fetches by the commit.',
        ]);
    });

    it('refuses a Composer package from a host the policy does not name', function () {
        $this->workspace->composer('acme/mirror', [
            'dist' => ['type' => 'zip', 'url' => 'https://mirror.example/acme/mirror.zip', 'reference' => Workspace::COMMIT, 'shasum' => str_repeat('a', 40)],
        ]);

        expect(violations($this->workspace))->toBe([
            'composer.lock acme/mirror downloads from https://mirror.example/acme/mirror.zip, which PackagePolicy::$composerSources does not allow.',
        ]);
    });

    // Composer falls back to the source when a dist fails, uses it outright
    // under --prefer-source, and tries a preferred mirror before either: each
    // is a place the package can come from.
    it('refuses a Composer package with an allowed dist and a source or mirror elsewhere', function () {
        $this->workspace->composer('acme/split', [
            'source' => ['type' => 'git', 'url' => 'https://git.evil.example/acme/split.git', 'reference' => Workspace::COMMIT],
        ]);
        $this->workspace->composer('acme/plain', [
            'source' => ['type' => 'git', 'url' => 'http://github.com/acme/plain.git', 'reference' => Workspace::COMMIT],
        ]);
        $this->workspace->composer('acme/ssh', [
            'source' => ['type' => 'git', 'url' => 'git@github.com:acme/ssh.git', 'reference' => Workspace::COMMIT],
        ]);
        $this->workspace->composer('acme/mirrored', [
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/acme/mirrored/zipball/' . Workspace::COMMIT,
                'reference' => Workspace::COMMIT,
                'shasum' => '',
                'mirrors' => [['url' => 'https://mirror.evil.example/%package%/%reference%.%type%', 'preferred' => true]],
            ],
        ]);
        $this->workspace->composer('acme/linked', [
            'dist' => ['type' => 'path', 'url' => 'packages/linked', 'reference' => null],
            'source' => ['type' => 'git', 'url' => 'https://git.evil.example/acme/linked.git', 'reference' => Workspace::COMMIT],
        ]);

        expect(violations($this->workspace))->toBe([
            'composer.lock acme/split may also download from https://git.evil.example/acme/split.git, which PackagePolicy::$composerSources does not allow.',
            'composer.lock acme/plain may also download from http://github.com/acme/plain.git, which PackagePolicy::$composerSources does not allow.',
            'composer.lock acme/ssh may also download from git@github.com:acme/ssh.git, which PackagePolicy::$composerSources does not allow.',
            'composer.lock acme/mirrored may also download from https://mirror.evil.example/%package%/%reference%.%type%, which PackagePolicy::$composerSources does not allow.',
            'composer.lock acme/linked may also download from https://git.evil.example/acme/linked.git, which PackagePolicy::$composerSources does not allow.',
        ]);
    });

    it('refuses a Composer download URL that is not a string', function () {
        $this->workspace->composer('acme/odd', [
            'source' => ['type' => 'git', 'url' => ['https://github.com/acme/odd.git'], 'reference' => Workspace::COMMIT],
        ]);

        expect(violations($this->workspace))->toBe([
            'composer.lock acme/odd may also download from ["https:\/\/github.com\/acme\/odd.git"], which PackagePolicy::$composerSources does not allow.',
        ]);
    });

    it('refuses a path package outside the project', function () {
        $this->workspace->composer('acme/local', ['dist' => ['type' => 'path', 'url' => '../elsewhere', 'reference' => null]]);
        $this->workspace->composer('acme/inside', ['dist' => ['type' => 'path', 'url' => 'packages/inside', 'reference' => null]]);

        expect(violations($this->workspace))->toBe(['composer.lock acme/local is a path package outside the project.']);
    });

    it('refuses composer.lock out of step with composer.json', function () {
        $this->workspace->write();
        $this->workspace->composerJson['require']['acme/extra'] = '^2.0';
        $this->workspace->json('composer.json', $this->workspace->composerJson);

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())
            ->toBe(['composer.lock is out of date with composer.json: run composer update --lock, and review what it changes.']);
    });

    it('refuses package-lock.json out of step with package.json', function () {
        $this->workspace->write();
        $this->workspace->packageJson['dependencies']['left-pad'] = '^1.3.0';
        $this->workspace->json('package.json', $this->workspace->packageJson);

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())
            ->toBe(['package-lock.json is out of date with package.json (dependencies): run npm install, and review what it changes.']);
    });

    it('refuses a version 1 package lock, which records no install scripts', function () {
        $this->workspace->write();
        $this->workspace->json('package-lock.json', ['lockfileVersion' => 1, 'dependencies' => []]);

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())
            ->toBe(['package-lock.json must be lockfileVersion 2 or later; run npm install with npm 7 or later.']);
    });

    it('refuses a missing lockfile', function () {
        $this->workspace->write();
        unlink($this->workspace->root . '/composer.lock');

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())->toBe(['composer.lock is missing.']);
    });
});

describe('install scripts and plugins', function () {
    it('refuses an npm package that runs an install script the policy does not allow', function () {
        $this->workspace->npm('native', ['hasInstallScript' => true]);

        expect(violations($this->workspace))->toBe([
            'package-lock.json node_modules/native runs a script when installed, and PackagePolicy::$installScripts does not allow it.',
        ])->and(violations($this->workspace, new PackagePolicy(installScripts: ['native' => 'Builds its binding.'])))->toBe([]);
    });

    // The allowlist above is read by `tinvar check`, which CI and an image
    // build run after their install: an npm install that runs lifecycle
    // scripts has run every one of them before anything held them to it.
    it('refuses an npm install in a workflow or a Dockerfile that runs install scripts', function () {
        $digest = '@sha256:' . str_repeat('ab', 32);
        $this->workspace->workflows['ci.yml'] = <<<'YAML'
        on: [push]
        jobs:
          test:
            steps:
              - run: composer install && npm ci --no-audit --no-fund
              - run: npm ci --ignore-scripts --no-audit
              - run: |
                  npm install --ignore-scripts=false
                  npm run build && npm i -D left-pad
              - run: npm rebuild fsevents && npm run lint
        YAML;
        $this->workspace->file('Dockerfile', "FROM debian{$digest}\nRUN composer install \\\n    && npm ci --omit=dev\nRUN npm ci --ignore-scripts && npm prune --omit=dev\nRUN <<EOF\nnpm update\nEOF\n");

        $because = 'which runs every package\'s install scripts before tinvar check holds them to PackagePolicy::$installScripts: add --ignore-scripts.';
        expect(violations($this->workspace))->toBe([
            ".github/workflows/ci.yml jobs.test.steps[0] runs `npm ci --no-audit --no-fund`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[2] runs `npm install --ignore-scripts=false`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[2] runs `npm i -D left-pad`, {$because}",
            "Dockerfile runs `npm ci --omit=dev`, {$because}",
            "Dockerfile runs `npm update`, {$because}",
        ]);
    });

    // The audit of 2026-11-13: an option's value before the command, a
    // line continued, npm quoted or named by its path, all read as no
    // install (ADR 0218).
    it('finds an npm install however its command line is spelled', function () {
        $this->workspace->workflows['ci.yml'] = <<<'YAML'
        on: [push]
        jobs:
          test:
            steps:
              - run: npm --prefix web ci
              - run: |
                  npm \
                    install
              - run: '"npm" ci && n''p''m i left-pad'
              - run: /usr/local/bin/npm --workspace=web --loglevel warn ci
              - run: npm --prefix web ci --ignore-scripts && npm --prefix web run install && npm run ci
        YAML;

        $because = 'which runs every package\'s install scripts before tinvar check holds them to PackagePolicy::$installScripts: add --ignore-scripts.';
        expect(violations($this->workspace))->toBe([
            ".github/workflows/ci.yml jobs.test.steps[0] runs `npm --prefix web ci`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[1] runs `npm install`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[2] runs `\"npm\" ci`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[2] runs `n'p'm i left-pad`, {$because}",
            ".github/workflows/ci.yml jobs.test.steps[3] runs `/usr/local/bin/npm --workspace=web --loglevel warn ci`, {$because}",
        ]);
    });

    it('asks why an install script is allowed', function () {
        new PackagePolicy(installScripts: ['native' => ' ']);
    })->throws(\InvalidArgumentException::class, 'Say why native may run an install script');

    it('refuses a Composer plugin allow-plugins does not name, and allowing them all', function () {
        $this->workspace->composer('acme/plugin', ['type' => 'composer-plugin']);
        expect(violations($this->workspace))->toBe(['acme/plugin is a Composer plugin, and config.allow-plugins does not name it.']);

        $this->workspace->composerJson['config']['allow-plugins'] = true;
        expect(violations($this->workspace))->toBe([
            'composer.json allows every Composer plugin: name each one under config.allow-plugins.',
            'acme/plugin is a Composer plugin, and config.allow-plugins does not name it.',
        ]);

        $this->workspace->composerJson['config']['allow-plugins'] = ['acme/*' => true];
        expect(violations($this->workspace))->toBe([
            "composer.json allows plugins by the pattern 'acme/*': name each one.",
            'acme/plugin is a Composer plugin, and config.allow-plugins does not name it.',
        ]);

        $this->workspace->composerJson['config']['allow-plugins'] = ['acme/plugin' => false];
        expect(violations($this->workspace))->toBe([]);
    });

    // Composer 1's plugin type, which Composer 2 still loads as a plugin:
    // only composer-plugin was read as one, so a package of this type
    // passed with allow-plugins naming nothing.
    it('refuses a composer-installer allow-plugins does not name', function () {
        $this->workspace->composer('acme/installer', ['type' => 'composer-installer', 'extra' => ['class' => 'Acme\\Installer']]);

        expect(violations($this->workspace))->toBe(['acme/installer is a Composer plugin, and config.allow-plugins does not name it.']);
    });

    it('refuses turning off https for Composer', function () {
        $this->workspace->composerJson['config']['secure-http'] = false;

        expect(violations($this->workspace))
            ->toBe(['composer.json turns off secure-http or TLS: packages must download over https.']);
    });

    // Composer's own ignore lists move an advisory out of what
    // `tinvar audit` reads as failing, with no reason and no date: an
    // exception goes in audit-exceptions.json (ADR 0211).
    it('refuses Composer config that ignores advisories, abandoned packages or malware in its audit', function (array $config, string $key) {
        $this->workspace->composerJson['config'] = [...$this->workspace->composerJson['config'] ?? [], ...$config];

        expect(violations($this->workspace))->toBe([
            "composer.json sets config.{$key}, which hides what composer audit finds from `tinvar audit`: excuse an advisory in audit-exceptions.json, reviewed and dated.",
        ]);
    })->with([
        'audit.ignore' => [['audit' => ['ignore' => ['GHSA-wxmh-65f7-jcvw']]], 'audit.ignore'],
        'audit.ignore by package' => [['audit' => ['ignore' => ['guzzlehttp/psr7' => 'reviewed']]], 'audit.ignore'],
        'audit.ignore-severity' => [['audit' => ['ignore-severity' => ['low']]], 'audit.ignore-severity'],
        'audit.ignore-abandoned' => [['audit' => ['ignore-abandoned' => ['acme/old']]], 'audit.ignore-abandoned'],
        'audit.ignore-unreachable' => [['audit' => ['ignore-unreachable' => true]], 'audit.ignore-unreachable'],
        'audit.abandoned' => [['audit' => ['abandoned' => 'ignore']], 'audit.abandoned'],
        'policy off' => [['policy' => false], 'policy'],
        'policy.advisories off' => [['policy' => ['advisories' => false]], 'policy.advisories'],
        'policy.advisories.ignore-id' => [['policy' => ['advisories' => ['ignore-id' => ['PKSA-vznr-tgp9-fd7d']]]], 'policy.advisories.ignore-id'],
        'policy.advisories.ignore' => [['policy' => ['advisories' => ['ignore' => ['guzzlehttp/psr7']]]], 'policy.advisories.ignore'],
        'policy.advisories.ignore-severity' => [['policy' => ['advisories' => ['ignore-severity' => ['medium']]]], 'policy.advisories.ignore-severity'],
        'policy.advisories.audit' => [['policy' => ['advisories' => ['audit' => 'report']]], 'policy.advisories.audit'],
        'policy.malware.ignore' => [['policy' => ['malware' => ['ignore' => ['acme/x']]]], 'policy.malware.ignore'],
        'policy.abandoned.audit' => [['policy' => ['abandoned' => ['audit' => 'ignore']]], 'policy.abandoned.audit'],
        'policy.ignore-unreachable' => [['policy' => ['ignore-unreachable' => ['audit']]], 'policy.ignore-unreachable'],
    ]);

    it('lets Composer config fail an abandoned package, or block an advisory on update, as it does unset', function () {
        $this->workspace->composerJson['config']['audit'] = ['abandoned' => 'fail', 'block-insecure' => true];
        $this->workspace->composerJson['config']['policy'] = ['advisories' => ['block' => true, 'audit' => 'fail'], 'abandoned' => ['audit' => 'fail'], 'ignore-unreachable' => ['install', 'update']];

        expect(violations($this->workspace))->toBe([]);
    });

    // Composer reads a lock entry's transport-options and applies them to
    // that package's download, so a line in composer.lock turned TLS
    // verification off, or trusted a CA of its own, for one package, where
    // the composer.json check above never looks. A path package's symlink
    // and relative say only how it is linked into vendor/.
    it('refuses transport-options in a lock entry, but a path package\'s link options', function () {
        $this->workspace->composer('acme/insecure', [
            'transport-options' => ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]],
        ]);
        $this->workspace->composer('acme/trusting', ['transport-options' => ['ssl' => ['cafile' => 'ca.pem']]]);
        $this->workspace->composer('acme/inside', [
            'dist' => ['type' => 'path', 'url' => 'packages/inside', 'reference' => null],
            'transport-options' => ['symlink' => true, 'relative' => true],
        ]);
        $this->workspace->composer('acme/proxied', [
            'dist' => ['type' => 'path', 'url' => 'packages/proxied', 'reference' => null],
            'transport-options' => ['symlink' => true, 'http' => ['proxy' => 'tcp://proxy.example:8080']],
        ]);

        $because = 'sets transport-options, which Composer applies to its download alone -- TLS verification, a CA file, headers -- where secure-http and disable-tls do not reach.';
        expect(violations($this->workspace))->toBe([
            "composer.lock acme/insecure {$because}",
            "composer.lock acme/trusting {$because}",
            "composer.lock acme/proxied {$because}",
        ]);
    });

    it('refuses a registry prefix a lookalike host could match', function () {
        new PackagePolicy(registries: ['https://registry.npmjs.org']);
    })->throws(\InvalidArgumentException::class, 'not an https URL prefix ending in a slash');
});

describe('dockerfiles', function () {
    $digest = '@sha256:' . str_repeat('ab', 32);

    it('passes a Dockerfile whose every image is pinned by digest', function () use ($digest) {
        $sum = str_repeat('cd', 32);
        $this->workspace->file('Dockerfile', <<<DOCKERFILE
            # syntax=docker/dockerfile:1.12{$digest}
            # A comment.
            FROM --platform=linux/arm64 php:8.5-cli{$digest} AS build
            RUN echo built
            FROM debian:trixie-slim{$digest} AS runtime
            COPY --from=build /out /srv
            COPY --from=0 /a /b
            RUN --mount=type=bind,from=build,source=/out,target=/mnt \\
                # a comment inside the instruction
                cp -a /mnt/. /srv/
            ADD --checksum=sha256:{$sum} https://example.com/a.tar.gz /tmp/
            FROM scratch
            DOCKERFILE);

        expect(violations($this->workspace))->toBe([]);
    });

    it('refuses an image named by tag, or by a variable, wherever it is taken from', function () use ($digest) {
        $this->workspace->file('examples/app/Dockerfile', <<<DOCKERFILE
            # syntax=docker/dockerfile:1
            ARG BASE=debian:trixie-slim{$digest}
            from node:22 as build
            FROM \$BASE
            COPY --from=busybox:1 /bin/busybox /bin/
            RUN --mount=type=cache,target=/root/.npm \\
                --mount=type=bind,from=alpine:3,source=/etc,target=/mnt true
            ADD https://example.com/tool.tar.gz /tmp/
            DOCKERFILE);
        $this->workspace->file('docker/app.Dockerfile', "FROM nginx:latest\n");
        $this->workspace->file('Dockerfile.dockerignore', "node_modules\n");
        $this->workspace->file('node_modules/x/Dockerfile', "FROM anything\n");

        expect(violations($this->workspace))->toBe([
            'docker/app.Dockerfile builds FROM nginx:latest, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile names the frontend docker/dockerfile:1, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile builds FROM node:22, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile builds FROM $BASE, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile COPY takes files from busybox:1, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile RUN takes files from alpine:3, which is not pinned to a sha256 digest.',
            'examples/app/Dockerfile ADDs https://example.com/tool.tar.gz with no --checksum=sha256: to hold it to.',
        ]);
    });

    // With another escape character, lines continue where this check would
    // read them as ended, and the two would read different instructions.
    it('refuses an escape directive other than the backslash', function () use ($digest) {
        $this->workspace->file('Dockerfile', "# escape=`\nFROM debian{$digest} AS base\nRUN echo `\nFROM node:22\n");
        $this->workspace->file('b/Dockerfile', "# escape=\\\nFROM debian{$digest}\n");

        expect(violations($this->workspace))->toBe([
            'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.',
        ]);
    });

    // What an ONBUILD wraps runs in whatever builds FROM this image, as
    // though it were written there.
    it('checks the instruction an ONBUILD wraps', function () use ($digest) {
        $this->workspace->file('Dockerfile', <<<DOCKERFILE
            FROM debian{$digest}
            ONBUILD ADD https://example.com/tool.tar.gz /tmp/
            onbuild COPY --from=busybox:1 /bin/busybox /bin/
            ONBUILD RUN --mount=type=bind,from=alpine:3,target=/mnt true
            DOCKERFILE);

        expect(violations($this->workspace))->toBe([
            'Dockerfile ADDs https://example.com/tool.tar.gz with no --checksum=sha256: to hold it to.',
            'Dockerfile COPY takes files from busybox:1, which is not pinned to a sha256 digest.',
            'Dockerfile RUN takes files from alpine:3, which is not pinned to a sha256 digest.',
        ]);
    });

    // Anything ADD fetches is remote, whatever the scheme; a git source is
    // held to a full commit, as an action is.
    it('holds every remote ADD source to a checksum, and a git one to a commit', function () use ($digest) {
        $commit = str_repeat('ab', 20);
        $sum = str_repeat('cd', 32);
        $this->workspace->file('Dockerfile', <<<DOCKERFILE
            FROM debian{$digest}
            ADD ftp://example.com/a.tar.gz /a
            ADD s3://bucket/b /b
            ADD user@git.example.com:org/repo.git /c
            ADD ssh://git@example.com/org/repo.git#main /d
            ADD https://github.com/org/repo.git#v1.2.3 /e
            ADD --checksum=sha256:{$sum} https://github.com/org/repo.git#main /f
            ADD https://github.com/org/repo.git#{$commit} /g
            ADD git@github.com:org/repo.git#{$commit}:sub /h
            ADD --checksum={$commit} https://github.com/org/repo.git /i
            ADD --checksum=sha256:{$sum} https://example.com/j.tar.gz /j
            ADD local/file /k
            DOCKERFILE);

        expect(violations($this->workspace))->toBe([
            'Dockerfile ADDs ftp://example.com/a.tar.gz with no --checksum=sha256: to hold it to.',
            'Dockerfile ADDs s3://bucket/b with no --checksum=sha256: to hold it to.',
            'Dockerfile ADDs the git source user@git.example.com:org/repo.git, which is not pinned to a full commit.',
            'Dockerfile ADDs the git source ssh://git@example.com/org/repo.git#main, which is not pinned to a full commit.',
            'Dockerfile ADDs the git source https://github.com/org/repo.git#v1.2.3, which is not pinned to a full commit.',
            'Dockerfile ADDs the git source https://github.com/org/repo.git#main, which is not pinned to a full commit.',
        ]);
    });

    // Docker and Podman take a file by any of these names, in any case; a
    // link is not followed, so one is refused rather than left unread; and
    // only the root's installed directories are passed over, not any
    // directory that shares a name with one.
    it('finds every Dockerfile and Containerfile, and refuses one that is a link', function () {
        $this->workspace->file('dockerfile', "FROM a\n");
        $this->workspace->file('Containerfile', "FROM b\n");
        $this->workspace->file('deploy/api.containerfile', "FROM c\n");
        $this->workspace->file('deploy/DOCKERFILE.prod', "FROM d\n");
        $this->workspace->file('app/vendor/Dockerfile', "FROM e\n");
        $this->workspace->file('app/node_modules/Dockerfile', "FROM f\n");
        $this->workspace->file('deploy/Containerfile.containerignore', "x\n");
        $this->workspace->file('vendor/acme/Dockerfile', "FROM g\n");
        $this->workspace->file('outside/real', "FROM h\n");
        $this->workspace->write();
        symlink($this->workspace->root . '/outside/real', $this->workspace->root . '/deploy/Dockerfile');

        expect((new SupplyChain($this->workspace->root, new PackagePolicy()))->check())->toBe([
            'Containerfile builds FROM b, which is not pinned to a sha256 digest.',
            'app/node_modules/Dockerfile builds FROM f, which is not pinned to a sha256 digest.',
            'app/vendor/Dockerfile builds FROM e, which is not pinned to a sha256 digest.',
            'deploy/DOCKERFILE.prod builds FROM d, which is not pinned to a sha256 digest.',
            'deploy/Dockerfile is a link, which the check does not follow: make it the file itself.',
            'deploy/api.containerfile builds FROM c, which is not pinned to a sha256 digest.',
            'dockerfile builds FROM a, which is not pinned to a sha256 digest.',
        ]);
    });

    // BuildKit joins a continued line to the next with nothing between, and
    // with its leading whitespace taken off: `FR\` and `OM x` are `FROM x`,
    // and `--from=build\` and `er` take from the image builder. Only a
    // space or a tab may follow the backslash; after a form feed or a
    // vertical tab the instruction has ended, and the next line is one of
    // its own. Each was built with BuildKit, which pulled the image.
    it('reads continued lines as BuildKit joins them', function (string $contents) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->not->toBe([]);
    })->with([
        'a keyword cut in two' => ["FROM scratch\nFR\\\nOM nope:latest\n"],
        'a stage name cut in two' => ["FROM scratch AS build\nFROM scratch\nCOPY --from=build\\\n   er /x /x\n"],
        'a form feed after the backslash' => ["FROM scratch\nCOPY a /a \\\f\nFROM nope:latest\n"],
        'a vertical tab after the backslash' => ["FROM scratch\nCOPY a /a \\\v\nFROM nope:latest\n"],
        'a no-break space before the keyword' => ["FROM scratch\n\u{A0}FROM nope:latest\n"],
    ]);

    // BuildKit takes quotes and backslashes out of a flag before it reads
    // it, a quoted space does not end one, and a mount is CSV whose fields
    // may be quoted again. Each was built with BuildKit, which pulled nope.
    it('reads a flag as BuildKit unquotes it', function (string $contents) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->not->toBe([]);
    })->with([
        'a quoted mount option' => ["FROM scratch\nRUN --mount=type=bind,\"from=nope\",target=/x true\n"],
        'an escaped mount option' => ["FROM scratch\nRUN --mount=type=bind,fr\\om=nope,target=/x true\n"],
        'a CSV-quoted mount option' => ["FROM scratch\nRUN --mount='type=bind,\"from=nope\",target=/x' true\n"],
        'a quoted space in an earlier flag' => ["FROM scratch\nRUN --mount=\"type=cache,target=/a b\" --mount=type=bind,from=nope,target=/x true\n"],
        'a quoted space before --from' => ["FROM scratch\nCOPY --exclude=\"x y\" --from=nope /x /x\n"],
        'an escaped --from' => ["FROM scratch\nCOPY --fr\\om=nope /x /x\n"],
    ]);

    it('reads a quoted stage name as the stage', function () use ($digest) {
        expect(SupplyChain::dockerfile('Dockerfile', "FROM debian{$digest} AS build\nCOPY --from=\"build\" /a /b\nRUN --mount=\"type=bind,from=build,target=/m\" true\n"))->toBe([]);
    });

    // BuildKit fetched each of these: a quoted URL, one in the JSON form,
    // and one an ARG names.
    it('holds an ADD source however it is written', function () {
        $sum = str_repeat('cd', 32);

        expect(SupplyChain::dockerfile('Dockerfile', <<<DOCKERFILE
            FROM scratch
            ADD "https://example.com/a" /a
            ADD ["https://example.com/b", "/b"]
            ARG U=https://example.com/c
            ADD \$U /c
            ADD --checksum=sha256:{$sum} \${U} /d
            ADD ["local/e", "/e"]
            DOCKERFILE))->toBe([
            'Dockerfile ADDs https://example.com/a with no --checksum=sha256: to hold it to.',
            'Dockerfile ADDs https://example.com/b with no --checksum=sha256: to hold it to.',
            'Dockerfile ADDs $U, a variable the check cannot read: name the source, or hold it with --checksum.',
        ]);
    });

    // A heredoc's body is a file's contents or a script: a `FROM ... AS` in
    // one names no stage, and BuildKit pulls the image a later --from names.
    it('takes no stage name from a heredoc body', function (string $contents) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->toContain(
            'Dockerfile COPY takes files from nope, which is not pinned to a sha256 digest.',
        );
    })->with([
        'COPY <<EOF' => ["FROM scratch\nCOPY <<EOF /f\nFROM scratch AS nope\nEOF\nCOPY --from=nope /x /x\n"],
        "RUN <<-'EOT'" => ["FROM scratch\nRUN <<-'EOT' bash\n\tFROM scratch AS nope\n\tEOT\nCOPY --from=nope /x /x\n"],
        'a second heredoc' => ["FROM scratch\nRUN <<A cat - <<\"B\"\na\nA\nFROM scratch AS nope\nB\nCOPY --from=nope /x /x\n"],
        'a file descriptor' => ["FROM scratch\nRUN 3<<EOF cat\nFROM scratch AS nope\nEOF\nCOPY --from=nope /x /x\n"],
        'an ONBUILD RUN' => ["FROM scratch\nONBUILD RUN <<EOF\nFROM scratch AS nope\nEOF\nCOPY --from=nope /x /x\n"],
        '<<- and tabs' => ["FROM scratch\nRUN <<-EOF\n\tFROM scratch AS nope\n\t\tEOF\nCOPY --from=nope /x /x\n"],
    ]);

    // BuildKit opens a heredoc only on RUN, COPY and ADD, in a word that
    // starts with `<<`: each of these was built, and BuildKit read the
    // line after it as an instruction -- here a stage, the --from's.
    it('opens a heredoc only where BuildKit does', function (string $opener) use ($digest) {
        expect(SupplyChain::dockerfile('Dockerfile', "FROM debian{$digest}\n{$opener}\nFROM debian{$digest} AS build\nCOPY --from=build /a /b\n"))->toBe([]);
    })->with([
        'on another instruction' => ['LABEL note=<<EOF'],
        'inside quotes' => ['RUN echo "<<EOF"'],
        'in the middle of a word' => ['RUN cat a<<EOF'],
        'after a variable' => ['RUN $X<<EOF'],
    ]);

    // Where the two could read the body's end apart, the file is refused.
    it('refuses a heredoc it cannot be sure of', function (string $contents, string $what) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->toBe([
            "Dockerfile has {$what}, which the check cannot read as BuildKit does: name it with a plain word, and end it.",
        ]);
    })->with([
        'no terminator' => ["FROM scratch\nRUN <<EOF\nFROM scratch AS nope\n", 'a heredoc EOF that never ends'],
        'a tab before the terminator without <<-' => ["FROM scratch\nRUN <<EOF\n\tEOF\nFROM scratch AS nope\n", 'a heredoc EOF that never ends'],
        'a variable in the name' => ["FROM scratch\nRUN <<E\$X\nE\nFROM scratch AS nope\n", 'a heredoc named E$X'],
    ]);

    // Where this check takes `<<` for a heredoc and BuildKit does not, the
    // lines after it are instructions to BuildKit: read as a body, they are
    // still read as instructions are, so none hides there.
    it('reads a body as instructions are read', function (string $contents) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->toContain(
            'Dockerfile builds FROM nope:latest, which is not pinned to a sha256 digest.',
        );
    })->with([
        'a continued line' => ["FROM scratch\nRUN <<EOF\nFR\\\nOM nope:latest\nEOF\n"],
        'a no-break space' => ["FROM scratch\nRUN <<EOF\n\u{A0}FROM nope:latest\nEOF\n"],
    ]);

    it('reads the instructions after a heredoc', function () use ($digest) {
        expect(SupplyChain::dockerfile('Dockerfile', "FROM debian{$digest} AS build\nRUN <<EOF\necho hi\nEOF\nFROM debian{$digest}\nCOPY --from=build /a /b\nFROM nope:latest\n"))->toBe([
            'Dockerfile builds FROM nope:latest, which is not pinned to a sha256 digest.',
        ]);
    });

    // BuildKit found the frontend past a byte-order mark and a shebang, and
    // in a `//` directive, and pulled it; and an escape directive after one
    // whose value has a space is still read.
    it('reads the directives wherever BuildKit finds them', function (string $contents, string $violation) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->toContain($violation);
    })->with([
        'past a byte-order mark' => ["\u{FEFF}# syntax=nope/frontend:latest\nFROM scratch\n", 'Dockerfile names the frontend nope/frontend:latest, which is not pinned to a sha256 digest.'],
        'past a shebang' => ["#!/usr/bin/env docker\n# syntax=nope/frontend:latest\nFROM scratch\n", 'Dockerfile names the frontend nope/frontend:latest, which is not pinned to a sha256 digest.'],
        'in a // comment' => ["// syntax=nope/frontend:latest\nFROM scratch\n", 'Dockerfile names the frontend nope/frontend:latest, which is not pinned to a sha256 digest.'],
        'after a value with a space' => ["# check=skip=A, B\n# escape=`\nFROM scratch\n", 'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.'],
        'an escape past a byte-order mark' => ["\u{FEFF}# escape=`\nFROM scratch\n", 'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.'],
    ]);

    it('refuses a Dockerfile that is a JSON object', function () {
        expect(SupplyChain::dockerfile('Dockerfile', " {\"SYNTAX\": \"nope/frontend:latest\"}\n"))->toBe([
            'Dockerfile is a JSON object, which BuildKit may read a frontend from; the check refuses it.',
        ]);
    });

    // A directive's spaces are read as the instructions' are, so the two
    // readings never disagree on whether a line is one.
    it('reads a directive past any space BuildKit trims', function (string $contents, string $violation) {
        expect(SupplyChain::dockerfile('Dockerfile', $contents))->toContain($violation);
    })->with([
        'a form feed before it' => ["\f# escape=`\nFROM scratch\n", 'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.'],
        'a vertical tab after it' => ["# escape=`\v\nFROM scratch\n", 'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.'],
        'a no-break space inside it' => ["#\u{A0}escape\u{A0}=\u{A0}`\nFROM scratch\n", 'Dockerfile sets the escape character to `, which the check does not read; only \\ is allowed.'],
        'an ideographic space before the frontend' => ["\u{3000}# syntax=nope/frontend:latest\nFROM scratch\n", 'Dockerfile names the frontend nope/frontend:latest, which is not pinned to a sha256 digest.'],
    ]);

    // A stage is named before it is used: a later stage's name is an image.
    it('takes only an earlier stage\'s name as a stage', function () use ($digest) {
        $this->workspace->file('Dockerfile', "FROM later\nFROM debian{$digest} AS later\n");

        expect(violations($this->workspace))->toBe(['Dockerfile builds FROM later, which is not pinned to a sha256 digest.']);
    });
});

describe('workflows', function () {
    it('refuses an action pinned by tag, and passes one pinned by commit', function () {
        $this->workspace->workflows['ci.yml'] = <<<'YAML'
        on: [push]
        jobs:
          test:
            steps:
              - uses: actions/checkout@v6
              - uses: actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6.5.0
              - uses: ./local-action
              - uses: docker://alpine:3
        YAML;
        $this->workspace->file('local-action/action.yml', "runs:\n  using: composite\n  steps: []\n");

        expect(violations($this->workspace))->toBe([
            '.github/workflows/ci.yml jobs.test.steps[0] uses actions/checkout@v6, which is not pinned to a full commit.',
            '.github/workflows/ci.yml jobs.test.steps[3] uses docker://alpine:3, which is not pinned to a full commit.',
        ]);
    });

    // Only `on` triggers a workflow: the word anywhere else, in a comment or
    // an `if:`, is not one.
    it('refuses the triggers that run a fork\'s code with the repository\'s secrets', function () {
        $this->workspace->workflows['a.yml'] = "on:\n  pull_request_target:\n    types: [opened]\n";
        $this->workspace->workflows['b.yaml'] = "on: [push, workflow_run]\n# pull_request_target in a comment is fine\n";
        $this->workspace->workflows['c.yml'] = "on: push\njobs:\n  t:\n    if: github.event_name != 'workflow_run'\n    steps: []\n";

        expect(violations($this->workspace))->toBe([
            ".github/workflows/a.yml triggers on pull_request_target, which runs on a fork's behalf with the repository's secrets.",
            ".github/workflows/b.yaml triggers on workflow_run, which runs on a fork's behalf with the repository's secrets.",
        ]);
    });

    // Anyone may comment on a pull request, and a workflow it triggers runs
    // on the default branch with the repository's secrets: the usual way a
    // fork's code is checked out under them (ADR 0218).
    it('refuses a workflow a comment triggers', function () {
        $this->workspace->workflows['a.yml'] = "on:\n  issue_comment:\n    types: [created]\n";

        expect(violations($this->workspace))->toBe([
            ".github/workflows/a.yml triggers on issue_comment, which runs on a fork's behalf with the repository's secrets.",
        ]);
    });

    it('reads a uses however YAML spells it, in a step or a job', function () {
        $this->workspace->workflows['ci.yml'] = <<<'YAML'
        "on": push
        jobs:
          test:
            steps:
              - "uses": actions/checkout@v6
              - {name: node, uses: actions/setup-node@v5}
              - uses: >-
                  actions/cache@v4
          call:
            uses: acme/workflows/.github/workflows/test.yml@main
        YAML;

        expect(violations($this->workspace))->toBe([
            '.github/workflows/ci.yml jobs.test.steps[0] uses actions/checkout@v6, which is not pinned to a full commit.',
            '.github/workflows/ci.yml jobs.test.steps[1] uses actions/setup-node@v5, which is not pinned to a full commit.',
            '.github/workflows/ci.yml jobs.test.steps[2] uses actions/cache@v4, which is not pinned to a full commit.',
            '.github/workflows/ci.yml jobs.call uses acme/workflows/.github/workflows/test.yml@main, which is not pinned to a full commit.',
        ]);
    });

    // A double-quoted scalar can spell a character by its code, or break a
    // word over two lines: neither line holds the word.
    it('reads a trigger however YAML spells it', function () {
        $this->workspace->workflows['a.yml'] = <<<'YAML'
        "on": ["pull_request\x5Ftarget"]
        YAML;
        $this->workspace->workflows['b.yml'] = <<<'YAML'
        on: "workflow_\
          run"
        YAML;

        expect(violations($this->workspace))->toBe([
            ".github/workflows/a.yml triggers on pull_request_target, which runs on a fork's behalf with the repository's secrets.",
            ".github/workflows/b.yml triggers on workflow_run, which runs on a fork's behalf with the repository's secrets.",
        ]);
    });

    // A composite action runs the `uses` in its own action.yml as though the
    // workflow had named them, and a local one is named by its path alone.
    it('holds a local action\'s own steps to the same rule', function () {
        $this->workspace->workflows['ci.yml'] = "on: push\njobs:\n  test:\n    steps:\n      - uses: ./.github/actions/setup\n      - uses: ./.github/actions/missing\n      - uses: ./../outside\n";
        $this->workspace->file('.github/actions/setup/action.yml', <<<'YAML'
        runs:
          using: composite
          steps:
            - uses: actions/setup-node@v5
            - uses: actions/cache@0057852bfaa89a56745cba8c7296529d2fc39830 # v4.3.0
            - uses: ./.github/actions/inner
            - uses: ./.github/actions/setup
        YAML);
        $this->workspace->file('.github/actions/inner/action.yaml', "runs:\n  using: docker\n  image: docker://alpine:3\n");

        expect(violations($this->workspace))->toBe([
            '.github/actions/setup/action.yml runs.steps[0] uses actions/setup-node@v5, which is not pinned to a full commit.',
            '.github/actions/inner/action.yaml runs.image uses docker://alpine:3, which is not pinned to a full commit.',
            '.github/workflows/ci.yml jobs.test.steps[1] uses ./.github/actions/missing, a local action with no action.yml the check can read.',
            '.github/workflows/ci.yml jobs.test.steps[2] uses ./../outside, a local action outside the project.',
        ]);
    });

    // A job's container and its services are pulled by name: the digest is
    // the pin, and a tag beside it changes nothing.
    it('refuses a container or service image not pinned by digest', function () {
        $digest = 'sha256:' . str_repeat('ab', 32);
        $this->workspace->workflows['ci.yml'] = <<<YAML
        on: push
        jobs:
          pinned:
            container:
              image: php:8.5-cli@{$digest}
            services:
              postgres:
                image: docker://postgres:17@{$digest}
            steps: []
          short:
            container: ghcr.io/acme/ci@{$digest}
            steps: []
          tagged:
            container:
              image: php:8.5-cli
            services:
              postgres:
                image: postgres@sha256:abc
              mysql: mysql:8
              redis:
                ports: ['6379:6379']
            steps: []
          expression:
            container: \${{ matrix.image }}@{$digest}
            steps: []
          odd:
            container: {image: [php]}
            services: postgres
            steps: []
        YAML;

        expect(violations($this->workspace))->toBe([
            '.github/workflows/ci.yml jobs.tagged.container image php:8.5-cli is not pinned to a sha256 digest.',
            '.github/workflows/ci.yml jobs.tagged.services.postgres image postgres@sha256:abc is not pinned to a sha256 digest.',
            '.github/workflows/ci.yml jobs.tagged.services.mysql image mysql:8 is not pinned to a sha256 digest.',
            '.github/workflows/ci.yml jobs.tagged.services.redis names no image the check can read.',
            ".github/workflows/ci.yml jobs.expression.container image \${{ matrix.image }}@{$digest} is not pinned to a sha256 digest.",
            '.github/workflows/ci.yml jobs.odd.container image is not a string.',
            '.github/workflows/ci.yml jobs.odd.services is not a mapping the check can read.',
        ]);
    });

    it('refuses a workflow it cannot read', function () {
        $this->workspace->workflows['a.yml'] = "on: push\non: push\n";
        $this->workspace->workflows['b.yml'] = "on: 1\n";
        $this->workspace->workflows['c.yml'] = "on: push\njobs:\n  test:\n    steps:\n      - actions/checkout@v6\n";

        expect(violations($this->workspace))->toBe([
            '.github/workflows/a.yml is not YAML the check can read: Duplicate key "on" detected at line 2 (near "on: push").',
            '.github/workflows/b.yml has triggers the check cannot read: an event, a list of events or a map of them.',
            '.github/workflows/c.yml jobs.test.steps[0] is not a mapping the check can read.',
        ]);
    });

    // The workflow this repository runs: the downstream suites have the
    // secrets, so they are held to pushes to main.
    it('runs the downstream suites only for a push to main', function () {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/ci.yml');

        expect($workflow)->toContain("if: github.event_name == 'push' && github.ref == 'refs/heads/main'")
            ->and(preg_match('/^  downstream:\n(?:    .*\n|\n)*?    if: github\.event_name == \'push\'/m', $workflow))->toBe(1);
    });

    // The image is built on pull requests too, which is safe only while the
    // job is handed no secret and sends the image nowhere (ADR 0059).
    it('builds and checks the image with no secret, and pushes it nowhere', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/ci.yml'));
        $job = $workflow['jobs']['image'];
        $text = (string) json_encode($job);
        $runs = implode("\n", array_column($job['steps'], 'run'));

        expect($text)->not->toContain('secrets.')
            ->and($text)->not->toContain('github.token')
            ->and($job)->not->toHaveKey('permissions')
            ->and($runs)->toContain('docker build --provenance=false --sbom=false -f examples/shop/Dockerfile')
            ->and($runs)->toContain('examples/shop/bin/verify-image')
            ->and($runs)->toContain('--target pod-php')
            ->and($runs)->toContain('--target pod-renderer')
            ->and($runs)->toContain('examples/shop/bin/verify-pod')
            ->and($runs)->toContain('bin/sbom image pellwick-shop:ci')
            ->and($runs)->toContain('bin/sbom image pellwick-shop-php:ci')
            ->and($runs)->toContain('bin/sbom image pellwick-shop-renderer:ci')
            ->and($runs)->toContain('bin/sbom workspace')
            ->and(substr_count($runs, '--dockerfile examples/shop/Dockerfile'))->toBe(3)
            ->and(preg_match('/docker\s+(push|login)|buildx.*--push/', $runs))->toBe(0);
    });

    // Attestations sign with the run's OIDC identity, so the one job that
    // may mint a token runs for what has landed -- main and version tags --
    // and no other job, nor the workflow, is given one (ADR 0080).
    it('attests only from a push to main or a tag, in the one job with id-token', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/ci.yml'));
        $attest = $workflow['jobs']['attest'];
        $uses = array_column($attest['steps'], 'uses');

        expect($workflow['permissions'])->toBe(['contents' => 'read'])
            ->and($workflow['on']['push'])->toBe(['branches' => ['main'], 'tags' => ['v*']])
            ->and($attest['permissions'])->toBe(['contents' => 'read', 'id-token' => 'write', 'attestations' => 'write'])
            ->and($attest['needs'])->toBe('image')
            ->and($attest['if'])->toStartWith("github.event_name == 'push' && (github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v'))")
            ->and(array_filter($uses, static fn (?string $use): bool => $use !== null && str_starts_with($use, 'actions/attest@')))->toHaveCount(5)
            ->and((string) json_encode($attest))->not->toContain('secrets.');

        foreach ($workflow['jobs'] as $name => $job) {
            if ($name !== 'attest') {
                expect($job['permissions'] ?? [])->not->toHaveKey('id-token', "{$name} is given id-token");
            }
        }

        // The digests reach a shell only through the environment, and are
        // held to a sha256 and to the SBOMs' before anything is signed.
        $subjects = $attest['steps'][2];
        expect($subjects['name'])->toBe('Subjects')
            ->and($subjects['run'])->not->toContain('${{')
            ->and($subjects['run'])->toContain('^sha256:[0-9a-f]{64}$');
    });

    // Each package's suite with only what it requires installed: every one,
    // tenancy's included, and the framework's and the database's without
    // it (ADR 0055, 0056). The skeleton is an application, which no suite
    // installs as a package: its own job makes an app of it (ADR 0082).
    it('runs every package\'s suite alone in CI, and makes an app of the skeleton', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/ci.yml'));
        $packages = array_map(static fn (string $file): string => basename(\dirname($file)), glob(\dirname(__DIR__, 4) . '/packages/*/composer.json') ?: []);
        $packages = array_values(array_diff($packages, ['skeleton']));
        // Those whose suites reach a database, and those whose do not.
        $matrix = [
            ...$workflow['jobs']['packages']['strategy']['matrix']['package'],
            ...$workflow['jobs']['packages-plain']['strategy']['matrix']['package'],
        ];
        sort($packages);
        sort($matrix);

        expect($matrix)->toBe($packages)
            ->and($packages)->toContain('tenancy')
            ->and(array_column($workflow['jobs']['skeleton']['steps'], 'run'))->toContain('php bin/skeleton-proof --context="$RUNNER_TEMP/skeleton-app.tar"')
            // And the skeleton's image, built from the app the proof passed.
            ->and($workflow['jobs']['skeleton-image']['needs'] ?? null)->toBe(['changes', 'skeleton'])
            ->and(array_column($workflow['jobs']['skeleton-image']['steps'], 'run'))->toContain('.github/ci/verify-skeleton-image "$RUNNER_TEMP/skeleton-app.tar"');
    });

    // The split holds the mirrors' deploy keys, so it runs for what has
    // landed -- main and tags -- and nothing a pull request brings (ADR 0055).
    it('splits the packages to their mirrors only for a push to main or a tag', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/split.yml'));
        $script = (string) file_get_contents(\dirname(__DIR__, 4) . '/.github/ci/split');

        expect($workflow['on'])->toBe(['push' => ['branches' => ['main'], 'tags' => ['v*']]])
            ->and($workflow['permissions'])->toBe(['contents' => 'read'])
            ->and(array_column($workflow['jobs']['split']['strategy']['matrix']['include'], 'package'))->toBe(['support', 'database', 'framework', 'tenancy', 'billing', 'testing', 'codegen', 'skeleton'])
            ->and(array_column($workflow['jobs']['split']['strategy']['matrix']['include'], 'key'))->toBe([
                'TINVAR_SPLIT_KEY_SUPPORT', 'TINVAR_SPLIT_KEY_DATABASE', 'TINVAR_SPLIT_KEY_FRAMEWORK',
                'TINVAR_SPLIT_KEY_TENANCY', 'TINVAR_SPLIT_KEY_BILLING', 'TINVAR_SPLIT_KEY_TESTING', 'TINVAR_SPLIT_KEY_CODEGEN',
                'TINVAR_SPLIT_KEY_SKELETON',
            ])
            ->and($script)->toContain('refs/heads/main) target=refs/heads/main')
            ->and($script)->toContain('StrictHostKeyChecking=yes')
            ->and($script)->toContain('sha256sum -c')
            ->and($script)->not->toContain('--force');
    });
});

it('enables nothing while the tree fails', function () {
    $this->workspace->npm('native', ['hasInstallScript' => true]);

    (new SupplyChain($this->workspace->write()->root, new PackagePolicy()))->enabled();
})->throws(\RuntimeException::class, 'The supply-chain check fails');

/*
 * The advisory databases, over the real lockfiles. Needs the network, so it
 * runs only when asked: TINVAR_NETWORK_TESTS=1.
 */
it('finds no advisories against the locked packages', function () {
    $root = \dirname(__DIR__, 4);
    exec('php ' . escapeshellarg($root . '/bin/tinvar') . ' audit 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
})->skip(fn () => getenv('TINVAR_NETWORK_TESTS') !== '1', 'needs the network; set TINVAR_NETWORK_TESTS=1');
