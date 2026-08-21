<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use Nubit\AdminBundle\Notification\EventListener\CurrentRecipientFilter;
use Nubit\AdminBundle\Notification\EventListener\CurrentRecipientFilterListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CurrentRecipientFilterListenerTest extends TestCase
{
    public function testDoesNothingForSubRequests(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(static::never())->method('getFilters');

        $listener = new CurrentRecipientFilterListener($entityManager, $this->createStub(TokenStorageInterface::class));
        $listener->__invoke($this->makeEvent(main: false));
    }

    public function testDoesNothingWhenThereIsNoAuthenticatedUser(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(static::never())->method('getFilters');

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $listener = new CurrentRecipientFilterListener($entityManager, $tokenStorage);
        $listener->__invoke($this->makeEvent());
    }

    public function testDoesNothingWhenTheFilterIsNotRegistered(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('has')->with('nubit_notification_recipient')->willReturn(false);
        $filters->expects(static::never())->method('enable');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getFilters')->willReturn($filters);

        $listener = new CurrentRecipientFilterListener($entityManager, $this->authenticatedTokenStorage('user-42'));
        $listener->__invoke($this->makeEvent());
    }

    public function testEnablesTheFilterWithTheAuthenticatedUsersIdentifier(): void
    {
        // setParameter() is final on SQLFilter, so it can't be stubbed on a
        // mock — use the real filter and assert on its resulting state.
        $connection = $this->createStub(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn(string $v) => "'" . $v . "'");
        $filterEntityManager = $this->createStub(EntityManagerInterface::class);
        $filterEntityManager->method('getConnection')->willReturn($connection);
        $realFilter = new CurrentRecipientFilter($filterEntityManager);

        $filters = $this->createMock(FilterCollection::class);
        $filters->method('has')->with('nubit_notification_recipient')->willReturn(true);
        $filters
            ->expects(static::once())
            ->method('enable')
            ->with('nubit_notification_recipient')
            ->willReturn($realFilter);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getFilters')->willReturn($filters);

        $listener = new CurrentRecipientFilterListener($entityManager, $this->authenticatedTokenStorage('user-42'));
        $listener->__invoke($this->makeEvent());

        static::assertTrue($realFilter->hasParameter('recipient'));
        static::assertSame("'user-42'", $realFilter->getParameter('recipient'));
    }

    private function authenticatedTokenStorage(string $identifier): TokenStorageInterface
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn($identifier);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }

    private function makeEvent(bool $main = true): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent(
            $kernel,
            new Request(),
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }
}
