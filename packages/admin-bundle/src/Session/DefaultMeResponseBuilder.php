<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Session;

use Nubit\AdminBundle\Authorization\PermissionResolver;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Time\TimeZoneResolver;
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
        private ?TimeZoneResolver $timeZoneResolver = null,
        private ?PermissionResolver $permissionResolver = null,
    ) {}

    public function build(UserInterface $user): array
    {
        $response = [
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'appProfile' => $this->appProfile->value,
        ];

        // Storage is UTC, so the frontend cannot format a timestamp until it
        // knows which zone to render it in. Sending it with the session is what
        // keeps a date shown in the grid, in an export and on a printed
        // document from disagreeing.
        if ($this->timeZoneResolver !== null) {
            $response['timeZone'] = $this->timeZoneResolver->resolveIdentifier($user);
        }

        // The frontend renders actions from this. It is a convenience, never
        // the gate: the same permissions are enforced in the voter, so a client
        // that ignores the list gets a 403 rather than a result.
        if ($this->permissionResolver !== null) {
            $response = [...$response, ...$this->permissionResolver->sessionBlock($user)];
        }

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

        return $tenant;
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
