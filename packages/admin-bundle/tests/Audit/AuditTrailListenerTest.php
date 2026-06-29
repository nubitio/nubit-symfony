<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Audit;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nubit\AdminBundle\Audit\AuditTrailListener;
use Nubit\AdminBundle\Audit\Entity\AuditLog;
use Nubit\AdminBundle\Tests\Audit\Fixture\AuditedThing;
use Nubit\AdminBundle\Tests\Audit\Fixture\PlainThing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuditTrailListenerTest extends TestCase
{
    private EntityManager $em;
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/Fixture', \dirname(__DIR__, 2) . '/src/Audit/Entity'],
            true,
        );
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(AuditedThing::class),
            $this->em->getClassMetadata(PlainThing::class),
            $this->em->getClassMetadata(AuditLog::class),
        ]);

        $this->tokenStorage = new TokenStorage();
        $listener = new AuditTrailListener($this->tokenStorage);
        $this->em->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $listener);
    }

    public function testCreateIsRecordedWithAfterValues(): void
    {
        $thing = new AuditedThing('First', '10.00');
        $this->em->persist($thing);
        $this->em->flush();

        $logs = $this->allLogs();
        self::assertCount(1, $logs);
        self::assertSame('create', $logs[0]->getAction());
        self::assertSame('auditedthing', $logs[0]->getResource());
        self::assertSame((string) $thing->getId(), $logs[0]->getResourceId());
        self::assertSame(['before' => null, 'after' => 'First'], $logs[0]->getChanges()['name']);
    }

    public function testUpdateRecordsBeforeAndAfter(): void
    {
        $thing = new AuditedThing('Original', '10.00');
        $this->em->persist($thing);
        $this->em->flush();

        $thing->setName('Renamed');
        $this->em->flush();

        $logs = $this->allLogs();
        self::assertCount(2, $logs);
        self::assertSame('update', $logs[1]->getAction());
        self::assertSame(['name' => ['before' => 'Original', 'after' => 'Renamed']], $logs[1]->getChanges());
    }

    public function testFlushWithoutAuditableChangesRecordsNothing(): void
    {
        $this->em->persist(new PlainThing('untracked'));
        $this->em->flush();

        self::assertCount(0, $this->allLogs());
    }

    public function testDeleteRecordsBeforeValuesAndKeepsTheId(): void
    {
        $thing = new AuditedThing('Doomed', '10.00');
        $this->em->persist($thing);
        $this->em->flush();
        $id = (string) $thing->getId();

        $this->em->remove($thing);
        $this->em->flush();

        $logs = $this->allLogs();
        self::assertCount(2, $logs);
        self::assertSame('delete', $logs[1]->getAction());
        self::assertSame($id, $logs[1]->getResourceId());
        self::assertSame(['before' => 'Doomed', 'after' => null], $logs[1]->getChanges()['name']);
    }

    public function testUsernameComesFromTheTokenAndFallsBackToSystem(): void
    {
        $this->em->persist(new AuditedThing('Anon', '1.00'));
        $this->em->flush();
        self::assertSame('system', $this->allLogs()[0]->getUsername());

        $user = new InMemoryUser('jane@example.test', null);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $this->em->persist(new AuditedThing('Named', '1.00'));
        $this->em->flush();
        self::assertSame('jane@example.test', $this->allLogs()[1]->getUsername());
    }

    public function testIgnoredFieldsAreExcludedFromTheDiff(): void
    {
        $thing = new AuditedThing('X', '1.00');
        $this->em->persist($thing);
        $this->em->flush();

        self::assertArrayNotHasKey('updatedAt', $this->allLogs()[0]->getChanges());
    }

    /** @return list<AuditLog> */
    private function allLogs(): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')->from(AuditLog::class, 'a')->orderBy('a.id', 'ASC')
            ->getQuery()->getResult();
    }
}
