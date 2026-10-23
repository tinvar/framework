<?php

declare(strict_types=1);

/**
 * The mint exchange, end to end over a real socket.
 *
 * The Phase 2 review found the whole deferred feature dead in the composed
 * system: the renderer built a mint client and never handed it to the app, so
 * no mint frame could ever be produced. Every test of this exchange had a
 * fake on the other side of the seam -- which is how an ADR, a frame pair,
 * caps enforced twice and a dedicated class to hold the guarantees all came
 * to exist with nothing able to run them.
 *
 * These drive PHP's half with a fake renderer that really does send a mint
 * frame, so the branch in RenderChannel and the minter behind it are
 * exercised by something other than a unit test holding them directly.
 */

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RenderChannel;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

/**
 * A renderer that asks for tokens and reports what came back.
 *
 * @param list<array{id: int, component: string, props: array<string, mixed>}> $requests
 */
function mintingRenderer(string $path, array $requests): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = stream_socket_accept($server, 5);

        if ($client !== false) {
            stream_set_timeout($client, 5);
            fread($client, 65536);

            @fwrite($client, $codec->encodeFrame(['t' => Frame::MINT, 'm' => $requests]));

            // Whatever comes back is handed straight to the sink, so the
            // parent can assert on the tokens without a second channel.
            $reply = @fread($client, 65536);
            if (\is_string($reply) && $reply !== '') {
                $offset = 0;
                $frame = $codec->decodeFrame($reply, $offset);
                @fwrite($client, $codec->encodeFrame(Frame::chunk(json_encode($frame ?? []))));
            }

            @fwrite($client, $codec->encodeFrame(['t' => Frame::DONE]));
            @fclose($client);
        }

        fclose($server);
        exit(0);
    }

    fclose($server);

    return $pid;
}

function mintChannel(Fixture $fixture, string $path): RenderChannel
{
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), $orm, new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 10.0,
    );
}

it('signs a token for a boundary the renderer asked about', function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $path = sys_get_temp_dir() . '/tinvar-mint-' . bin2hex(random_bytes(6)) . '.sock';
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);

    $support = new FragmentSupport(
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static fn (): mixed => null,
        sessionId: static fn (Request $r): string => 'session-abc',
    );
    $minter = $support->minterFor(Request::get('/racks/r-1'), 'racks.show', ['rack' => 'r-1']);

    $pid = mintingRenderer($path, [
        ['id' => 0, 'component' => 'UnitHistory', 'props' => ['unit' => 'u-1']],
    ]);

    $seen = '';

    try {
        mintChannel($fixture, $path)->render(
            Frame::render('racks/show', ['rack' => 'r-1'], [], 'rid', 'nonce', []),
            [],
            static function (string $bytes) use (&$seen): void {
                $seen .= $bytes;
            },
            $minter->closure(),
        );
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }

    $reply = json_decode($seen, true);
    $token = FragmentToken::decode($reply['m'][0]['token'], $signer);

    expect($reply['t'])->toBe(Frame::MINTED)
        ->and($token->component)->toBe('UnitHistory')
        ->and($token->props)->toBe(['unit' => 'u-1'])
        // Everything below was chosen by PHP, not by the renderer.
        ->and($token->route)->toBe('racks.show')
        ->and($token->params)->toBe(['rack' => 'r-1'])
        ->and($token->build)->toBe('build-1')
        ->and($token->belongsTo($signer, 'session-abc'))->toBeTrue();
});

/**
 * "A render that passes no minting closure rejects a mint frame as a
 * protocol violation. A page with no deferred boundaries cannot be talked
 * into minting." Asserted against a real frame rather than a unit call.
 */
it('refuses to mint for a render that was not given a minter', function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $path = sys_get_temp_dir() . '/tinvar-mint-' . bin2hex(random_bytes(6)) . '.sock';

    $pid = mintingRenderer($path, [['id' => 0, 'component' => 'UnitHistory', 'props' => []]]);

    try {
        expect(fn () => mintChannel($fixture, $path)->render(
            Frame::render('racks/show', ['rack' => 'r-1'], [], 'rid', 'nonce', []),
            [],
            static fn (string $bytes): null => null,
        ))->toThrow(ProtocolException::class, 'may not do');
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
});
