<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

/**
 * A Precognition request named a field no form request of the endpoint or
 * action declares, nor a parameter of the action. The client's input, not
 * an author's bug: answered with a 422 that does not quote the name back,
 * and the only failure Precognition answers so -- a misconfigured rule is
 * a bug, and lets out as one.
 */
final class UnknownField extends \RuntimeException {}
