<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Enables the nubit_soft_delete filter for HTTP main requests only: web
 * traffic never sees soft-deleted rows, while console commands and
 * migrations keep full visibility unless they enable the filter themselves.
 */
#[AsEventListener]
final readonly class SoftDeleteFilterListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $filters = $this->entityManager->getFilters();

        if ($filters->has('nubit_soft_delete') && !$filters->isEnabled('nubit_soft_delete')) {
            $filters->enable('nubit_soft_delete');
        }
    }
}
