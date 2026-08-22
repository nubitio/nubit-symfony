<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Exception;

use Nubit\Platform\Exception\ServiceException;

/**
 * A sort the resource cannot serve correctly.
 *
 * Client input, so it becomes a 400 rather than a 500 — and a message that says
 * what to do instead, because the person seeing it is a developer wiring a grid.
 */
final class SortNotSupportedException extends ServiceException {}
