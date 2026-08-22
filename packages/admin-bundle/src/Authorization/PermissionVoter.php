<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use Nubit\ApiPlatform\Authorization\Permission;
use Nubit\Platform\Money\Money;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Makes `is_granted('invoice.approve')` work, and `is_granted('invoice.approve', $invoice)`
 * additionally check the amount.
 *
 * The subject form is what turns "may approve" into "may approve *this*". A
 * limit that is only enforced in a service somewhere is a limit the API can be
 * asked to skip; evaluating it in the voter means every path — the operation's
 * `security:` expression, a controller, a Twig template — asks the same question
 * and gets the same answer.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly PermissionCatalog $catalog,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::isPermissionName($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        if (!$this->resolver->hasPermission($user, $attribute)) {
            return false;
        }

        $limit = $this->resolver->limitFor($user, $attribute);
        if (null === $limit || !is_object($subject)) {
            return true;
        }

        $amount = $this->amountFor($attribute, $subject);
        if (null === $amount) {
            return true;
        }

        if (!$amount->currency->is($limit->currency)) {
            // Comparing across currencies would be a conversion this layer has
            // no rate for, and guessing would let a limit be bypassed by
            // denominating a document differently.
            $this->logger->warning('Refusing a limited permission: the amount and the limit differ in currency.', [
                'permission' => $attribute,
                'amount' => (string) $amount,
                'limit' => (string) $limit,
            ]);

            return false;
        }

        return $amount->absolute()->isLessThanOrEqualTo($limit);
    }

    /** The amount a limited permission is measured against, per `#[Authorized(limited: …)]`. */
    private function amountFor(string $permission, object $subject): ?Money
    {
        $resource = $this->catalog->forClass($subject::class);
        $property = $resource?->limitProperty($permission);

        if (null === $property) {
            return null;
        }

        $getter = 'get' . ucfirst($property);
        /** @var mixed $value */
        $value = match (true) {
            method_exists($subject, $getter) => $subject->{$getter}(),
            property_exists($subject, $property) => $subject->{$property},
            default => null,
        };

        return $value instanceof Money ? $value : null;
    }
}
