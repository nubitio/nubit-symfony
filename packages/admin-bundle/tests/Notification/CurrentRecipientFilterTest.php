<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nubit\AdminBundle\Notification\EventListener\CurrentRecipientFilter;
use Nubit\AdminBundle\Notification\Entity\Notification;
use PHPUnit\Framework\TestCase;

final class CurrentRecipientFilterTest extends TestCase
{
    public function testAddsNoConstraintForEntitiesOtherThanNotification(): void
    {
        $filter = $this->makeFilter();
        $metadata = $this->makeMetadata(self::class);

        static::assertSame('', $filter->addFilterConstraint($metadata, 't0'));
    }

    public function testAddsNoConstraintWhenTheRecipientParameterIsNotSet(): void
    {
        $filter = $this->makeFilter();
        $metadata = $this->makeMetadata(Notification::class);

        static::assertSame('', $filter->addFilterConstraint($metadata, 't0'));
    }

    public function testConstrainsToTheRecipientParameterWhenSet(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value) => "'" . $value . "'");

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $filter = new CurrentRecipientFilter($entityManager);
        $filter->setParameter('recipient', 'user-42', 'string');

        $metadata = $this->makeMetadata(Notification::class);

        static::assertSame("t0.recipient = 'user-42'", $filter->addFilterConstraint($metadata, 't0'));
    }

    private function makeFilter(): CurrentRecipientFilter
    {
        return new CurrentRecipientFilter($this->createStub(EntityManagerInterface::class));
    }

    /**
     * @param class-string $name
     */
    private function makeMetadata(string $name): ClassMetadata
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getName')->willReturn($name);

        return $metadata;
    }
}
