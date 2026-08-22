<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import\Exception;

use Nubit\Platform\Exception\ServiceException;

/** A refusal from the import pipeline: bad mapping, unreadable file, unimportable resource. */
class ImportException extends ServiceException {}
