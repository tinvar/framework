<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\AppKeySurface;

use Tinvar\Support\AppKey;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/tinvar-appkey-surface-' . bin2hex(random_bytes(4));
    putenv('TINVAR_TEST_KEY');
});

afterEach(function () {
    putenv('TINVAR_TEST_KEY');
    if (is_dir($this->dir)) {
        chmod($this->dir, 0700);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }
});

/**
 * Resolves a key that cannot be stored, returning what it threw and every
 * warning it let through. Under the CLI server a warning lands in the
 * response, so AppKey silences the filesystem call and throws instead; the
 * silenced warning is kept here, where PHPUnit would otherwise report it.
 *
 * @return array{\Throwable|null, list<string>}
 */
function resolveFailing(string $file): array
{
    $loud = [];
    set_error_handler(static function (int $level, string $message) use (&$loud): bool {
        if ((error_reporting() & $level) !== 0) {
            $loud[] = $message;
        }

        return true;
    });

    try {
        AppKey::resolve('TINVAR_TEST_KEY', $file);

        return [null, $loud];
    } catch (\Throwable $e) {
        return [$e, $loud];
    } finally {
        restore_error_handler();
    }
}

it('names a key directory it cannot create, without a warning', function () {
    mkdir($this->dir, 0700);
    touch($this->dir . '/plain-file');

    [$error, $warnings] = resolveFailing($this->dir . '/plain-file/state/app.key');

    expect($error)->toBeInstanceOf(\RuntimeException::class)
        ->and($error?->getMessage())->toBe("Cannot create {$this->dir}/plain-file/state to store the application key.")
        ->and($warnings)->toBe([]);
});

it('names a key directory it may not write into, without a warning', function () {
    mkdir($this->dir, 0700);
    chmod($this->dir, 0500);

    [$error, $warnings] = resolveFailing($this->dir . '/app.key');

    expect($error)->toBeInstanceOf(\RuntimeException::class)
        ->and($error?->getMessage())->toBe("Cannot create {$this->dir}/.app.key.lock for the application key.")
        ->and($warnings)->toBe([])
        ->and(file_exists($this->dir . '/app.key'))->toBeFalse();
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes anywhere');
