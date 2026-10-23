<?php

declare(strict_types=1);

namespace Tinvar\Tests\Protocol\Surface;

use Tinvar\Protocol\Frame;

it('builds a fragment frame carrying the verified token, nonce and allowlist', function () {
    $frame = Frame::fragment(
        token: ['c' => 'UnitHistory', 'p' => ['unit' => 'u-1']],
        requestId: 'req-2',
        cspNonce: 'kX9a',
        allowedQueries: ['history' => 'units.history'],
    );

    expect($frame)->toBe([
        't' => Frame::FRAGMENT,
        'token' => ['c' => 'UnitHistory', 'p' => ['unit' => 'u-1']],
        'rid' => 'req-2',
        'nonce' => 'kX9a',
        // Keyed input still crosses as a list, which is what the renderer reads.
        'allow' => ['units.history'],
    ]);
});
