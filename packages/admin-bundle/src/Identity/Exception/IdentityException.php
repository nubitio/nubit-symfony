<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity\Exception;

use Nubit\Platform\Exception\ServiceException;

/** A refusal from the identity lifecycle: bad token, expired invitation, unusable user entity. */
class IdentityException extends ServiceException {}
