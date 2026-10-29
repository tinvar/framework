<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * A discovery document or key set that was fetched and not taken: a status
 * other than 200, a body that is not a JSON object, an issuer that is not
 * exactly the one asked for, an endpoint that is not https, a field
 * missing. The transport's own refusals and failures are OutboundRefused
 * and OutboundFailed, thrown as they are.
 *
 * The message names the issuer -- configuration, not a secret -- and never
 * quotes the document: what the provider sent is the provider's to say.
 */
final class OidcDiscoveryFailed extends \RuntimeException
{
    public const string STATUS = 'status';
    public const string DOCUMENT = 'document';
    public const string ISSUER = 'issuer';
    public const string ENDPOINT = 'endpoint';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
