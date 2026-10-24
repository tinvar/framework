<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** A template that did not render as an email may be sent. */
final class MailRenderingException extends \RuntimeException {}
