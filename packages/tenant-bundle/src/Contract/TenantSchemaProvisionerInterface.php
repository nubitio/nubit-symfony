<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Contract;

/**
 * Application-owned marker for schema provisioning (creation, roles and grants).
 * The bundle deliberately provides no provisioning implementation or DDL.
 */
interface TenantSchemaProvisionerInterface {}
