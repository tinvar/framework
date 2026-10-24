<?php

declare(strict_types=1);

/**
 * Reading a rendered page's RSC payload back out of the document, for the
 * client-component and model-leak assertions.
 */

namespace Tinvar\Tests\Testing\RscPayload;

use Tinvar\Testing\RscPayload;

it('joins a document\'s inline rows, strings and bytes alike, split wherever the stream split them', function () {
    $rows = "0:[\"\$\",\"main\",null,{}]\n1:I[\"773b\",[],\"FormSession\",1]\n";
    $html = '<script nonce="n">self.__rsc=[];self.__rsc_done=false</script>'
        . '<script nonce="n">self.__rsc.push(' . json_encode(substr($rows, 0, 20)) . ')</script>'
        . '<p>between</p>'
        . '<script nonce="n">self.__rsc.push(' . json_encode(substr($rows, 20)) . ')</script>'
        . '<script nonce="n">self.__rsc.push({"$b64":"' . base64_encode("2:\"\xff\"\n") . '"})</script>';

    expect(RscPayload::of($html))->toBe($rows . "2:\"\xff\"\n")
        ->and(RscPayload::of('1:"raw"', 'text/x-component'))->toBe('1:"raw"');
});

it('names the client components a payload references, an export given in place or by reference', function () {
    $payload = "1:I[\"773b\",[],\"FormSession\",1]\n"
        . "4:\"TimeZoneProvider\"\n"
        . "5:I[\"f8f5\",[],\"\$4\",1]\n"
        . "6:[\"\$\",\"\$L1\",null,{}]\n"
        . "7:I[\"773b\",[],\"FormSession\",1]\n";

    expect(RscPayload::clientComponents($payload))->toBe(['FormSession', 'TimeZoneProvider']);
});
