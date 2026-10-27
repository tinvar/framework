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

    // Each package's suite with only what it requires installed: every one,
    // tenancy's included, and the framework's and the database's without
    // it (ADR 0055, 0056).
    it('runs every package\'s suite alone in CI', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/ci.yml'));
        $packages = array_map(static fn (string $file): string => basename(\dirname($file)), glob(\dirname(__DIR__, 4) . '/packages/*/composer.json') ?: []);
        $matrix = $workflow['jobs']['packages']['strategy']['matrix']['package'];
        sort($packages);
        sort($matrix);

        expect($matrix)->toBe($packages)
            ->and($packages)->toContain('tenancy');
    });

    // The split holds the mirrors' deploy keys, so it runs for what has
    // landed -- main and tags -- and nothing a pull request brings (ADR 0055).
    it('splits the packages to their mirrors only for a push to main or a tag', function () {
        $workflow = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 4) . '/.github/workflows/split.yml'));
        $script = (string) file_get_contents(\dirname(__DIR__, 4) . '/.github/ci/split');

        expect($workflow['on'])->toBe(['push' => ['branches' => ['main'], 'tags' => ['v*']]])
            ->and($workflow['permissions'])->toBe(['contents' => 'read'])
            ->and(array_column($workflow['jobs']['split']['strategy']['matrix']['include'], 'package'))->toBe(['support', 'database', 'framework', 'tenancy', 'testing', 'codegen'])
            ->and(array_column($workflow['jobs']['split']['strategy']['matrix']['include'], 'key'))->toBe([
                'TINVAR_SPLIT_KEY_SUPPORT', 'TINVAR_SPLIT_KEY_DATABASE', 'TINVAR_SPLIT_KEY_FRAMEWORK',
                'TINVAR_SPLIT_KEY_TENANCY', 'TINVAR_SPLIT_KEY_TESTING', 'TINVAR_SPLIT_KEY_CODEGEN',
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
