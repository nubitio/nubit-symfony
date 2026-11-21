<?php

declare(strict_types=1);

namespace Nubit\Platform\Money\Exception;

use Nubit\Platform\Exception\ServiceException;

/** Any refusal from the money layer: bad currency, mixed currencies, lost precision, overflow. */
class MoneyException extends ServiceException {}
