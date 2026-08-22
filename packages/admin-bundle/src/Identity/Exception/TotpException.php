<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Exception;

use Nubit\Platform\Exception\ServiceException;

/** A refusal from the second-factor layer, with a message meant for the user. */
class TotpException extends ServiceException {}
