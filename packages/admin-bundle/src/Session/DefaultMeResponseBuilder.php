<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Session;

use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Generic session profile: user, roles, app profile. SaaS and hybrid apps
 * also receive tenant and feature entitlements when the platform contracts
 * supply them — domain-specific fields belong in a custom builder.
 */
final readonly class DefaultMeResponseBuilder implements MeResponseBuilderInterface
{
    public function __construct(
        private AppProfile $appProfile,
        private ?TenantContext $tenantContext = null,
        private ?FeatureCheckerInterface $featureChecker = null,
    ) {
    }

    public function build(UserInterface $user): array
    {
        $response = [
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'appProfile' => $this->appProfile->value,
        ];

        if ($this->appProfile === AppProfile::Internal) {
            return $response;
        }

        $tenant = $this->buildTenantBlock();
        if ($tenant !== null) {
            $response['tenant'] = $tenant;
        }

        $features = $this->buildFeaturesBlock();
        if ($features !== []) {
            $response['features'] = $features;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildTenantBlock(): ?array
    {
        if ($this->tenantContext === null) {
            return null;
        }

        $id = $this->tenantContext->getTenantId();
        $name = $this->tenantContext->getTenantName();

        if ($id === null && ($name === null || $name === '')) {
            return null;
        }

        $tenant = [];
        if ($id !== null) {
            $tenant['id'] = $id;
        }
        if ($name !== null && $name !== '') {
            $tenant['name'] = $name;
        }

        $domain = $this->tenantContext->getTenantDomain();
        if ($domain !== null && $domain !== '') {
            $tenant['domain'] = $domain;
        }

        return $tenant !== [] ? $tenant : null;
    }

    /**
     * @return array<string, array{enabled: bool, config: array<string, mixed>}>
     */
    private function buildFeaturesBlock(): array
    {
        if ($this->featureChecker === null) {
            return [];
        }

        return $this->featureChecker->getEntitlements();
    }
}