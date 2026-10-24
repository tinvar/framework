<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Support\Signer;

/**
 * The hub topic a tag is published and subscribed under.
 *
 * Opaque: a keyed hash of the tag and its tenant, never the tag itself. The
 * client names its topics in the URL it opens the stream on, and a URL is
 * what access logs keep -- a tag such as `orders:customer:42` would put who
 * is reading what into every one of them. A keyed hash also cannot be worked
 * out from a tag by someone who wants another tenant's topic; the access
 * token the hub checks is what keeps them out, and this keeps them guessing
 * as well.
 *
 * Namespaced by tenant exactly as QueryCache namespaces a tag: the same tag
 * in two tenants is two topics, so a write in one is never announced to the
 * other.
 */
final readonly class Topics
{
    public const string PURPOSE = 'live-topic';

    /** What every topic looks like: the prefix and 128 bits of MAC, base64url. */
    public const string SHAPE = '/^tinvar:[A-Za-z0-9_-]{22}\z/';

    public function __construct(private Signer $signer) {}

    /** The topic of a tag in a tenant. */
    public function for(string $tenant, string $tag): string
    {
        return $this->of($tenant . "\0" . $tag);
    }

    /**
     * The topic of a tag already namespaced -- tenant, NUL, tag -- as
     * QueryRunner::tagsRead() and TagsRead hand them over.
     */
    public function of(string $namespaced): string
    {
        return 'tinvar:' . Signer::encode(substr($this->signer->sign($namespaced), 0, 16));
    }
}
