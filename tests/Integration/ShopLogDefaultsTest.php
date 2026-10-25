<?php

declare(strict_types=1);

/**
 * Where the shop's logs go when its database is in memory and nothing
 * names a file: the mail log holds every message whole, and the shared
 * temporary directory is one where anyone can put a file or a link first
 * at a name the shop is known to use.
 */

namespace Tinvar\Tests\Integration\ShopLogDefaults;

use Shop\Kernel;
use Tinvar\Mail\Address;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\Message;

it('keeps the in-memory shop\'s mail log in a directory of this user\'s alone', function () {
    $transport = Kernel::mailTransport(':memory:');
    $path = (fn (): string => $this->path)->call($transport);
    \assert($transport instanceof LogTransport);

    $transport->send(new Message(new Address('shop@pellwick.test'), [new Address('ada@example.test')], 'Hello', '<p>Hello</p>', 'Hello'));

    clearstatcache();
    expect(\dirname($path))->not->toBe(rtrim(sys_get_temp_dir(), '/'))
        ->and(fileperms(\dirname($path)) & 0o7777)->toBe(0o700)
        ->and(fileowner(\dirname($path)))->toBe(posix_geteuid())
        ->and(fileperms($path) & 0o777)->toBe(0o600);
})->skip(getenv('SHOP_MAIL') !== false && getenv('SHOP_MAIL') !== '' || getenv('SHOP_MAIL_LOG') !== false && getenv('SHOP_MAIL_LOG') !== '', 'the environment names where the shop\'s mail goes');
