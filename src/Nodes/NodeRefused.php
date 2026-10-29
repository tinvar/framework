<?php

declare(strict_types=1);

namespace Tinvar\Nodes;

/** A node CA or a node's certificate this server does not believe. */
final class NodeRefused extends \RuntimeException {}
