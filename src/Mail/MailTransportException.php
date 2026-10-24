<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** A message the transport could not hand over, and the server's reason when it gave one. */
final class MailTransportException extends \RuntimeException {}
