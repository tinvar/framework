<?php

declare(strict_types=1);

namespace Tinvar\Live;

/**
 * A document's live token, as the data block at its end the client reads.
 *
 * A document cannot read its own response headers, so a payload's
 * `X-Live-Token` has no equivalent there -- and the page metadata the
 * renderer writes first in the document is written before the render has
 * read anything. So PHP writes this once the render is done, as the last
 * thing in the body, and the client reads the LAST block with the id: page
 * content comes before it, so nothing the page renders can stand in for it.
 *
 * Written for every page that could have had a token, with null when it
 * read nothing, for the same reason: a page with no block of its own would
 * have the client read whatever block its content held. A data block is
 * never run, so it takes no nonce, and React's hydration passes over a
 * script element it did not render, as it does the payload's.
 */
final class LiveBlock
{
    public const string ID = 'tinvar-live';

    public static function tag(?string $token): string
    {
        // Escaped as the page metadata is: no `</script>`, nor any `<`, can
        // appear in it whatever it holds.
        $json = json_encode(['token' => $token], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script type="application/json" id="' . self::ID . '">' . $json . '</script>';
    }

    /** The document, with the block put just before its closing body tag -- or at its end, without one. */
    public static function into(string $html, ?string $token): string
    {
        $at = strripos($html, '</body>');

        return $at === false
            ? $html . self::tag($token)
            : substr($html, 0, $at) . self::tag($token) . substr($html, $at);
    }
}
