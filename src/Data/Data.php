<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * The only object kind allowed across the PHP-to-renderer boundary.
 *
 * Models never cross. Pages, queries and actions return Data objects, which
 * keeps columns the view never declared -- password hashes, internal flags --
 * out of the RSC payload and out of view-source.
 */
abstract class Data {}
