<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Session;

/**
 * Declares how the admin app is deployed. Drives which blocks appear in
 * {@see MeResponseBuilderInterface} (e.g. tenant/features for SaaS).
 */
enum AppProfile: string
{
    case Internal = 'internal';
    case Saas = 'saas';
    case Hybrid = 'hybrid';
}