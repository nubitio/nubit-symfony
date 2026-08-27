<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Notification\EventListener;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nubit\AdminBundle\Notification\Entity\Notification;

/**
 * Restricts every query against Notification to the current recipient. Not
 * parameter-driven from the request — CurrentRecipientFilterListener sets
 * the `recipient` filter parameter from the authenticated token, so there is
 * no client-suppliable value here to bypass.
 */
final class CurrentRecipientFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (Notification::class !== $targetEntity->getName()) {
            return '';
        }

        if (!$this->hasParameter('recipient')) {
            // Fail closed: a worker or console query that forgot to set the
            // recipient must not see everybody's inbox. HTTP requests get the
            // parameter from CurrentRecipientFilterListener.
            return '1=0';
        }

        return sprintf('%s.recipient = %s', $targetTableAlias, $this->getParameter('recipient'));
    }
}
