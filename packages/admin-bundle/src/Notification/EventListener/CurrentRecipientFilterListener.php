<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enables nubit_notification_recipient for authenticated HTTP main requests,
 * scoped to the current token's user identifier — mirrors
 * SoftDeleteFilterListener's shape. An unauthenticated request leaves the
 * filter disabled rather than guessing; the Notification resource's own
 * `security: "is_granted('ROLE_USER')"` is what actually rejects those.
 */
#[AsEventListener]
final readonly class CurrentRecipientFilterListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $identifier = $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier();
        if ($identifier === null) {
            return;
        }

        $filters = $this->entityManager->getFilters();
        if (!$filters->has('nubit_notification_recipient')) {
            return;
        }

        $filters->enable('nubit_notification_recipient')->setParameter('recipient', $identifier, 'string');
    }
}
