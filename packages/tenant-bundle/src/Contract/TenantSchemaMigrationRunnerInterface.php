<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Application-owned marker for schema migrations, backup policy and secret retrieval.
 * The bundle deliberately provides no migration implementation or DDL.
 */
interface TenantSchemaMigrationRunnerInterface
{
}
