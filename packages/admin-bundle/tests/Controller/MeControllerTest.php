<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Controller;

use Nubit\AdminBundle\Controller\MeController;
use Nubit\AdminBundle\Session\MeResponseBuilderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

final class MeControllerTest extends TestCase
{
    public function testReturnsUnauthorizedWhenUserIsMissing(): void
    {
        $controller = new MeController(new class implements MeResponseBuilderInterface {
            public function build(UserInterface $user): array
            {
                return [];
            }
        });

        $response = $controller->__invoke(null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('{"message":"Unauthorized"}', $response->getContent());
    }

    public function testReturnsBuilderPayloadForAuthenticatedUser(): void
    {
        $controller = new MeController(new class implements MeResponseBuilderInterface {
            public function build(UserInterface $user): array
            {
                return [
                    'username' => $user->getUserIdentifier(),
                    'roles' => $user->getRoles(),
                    'appProfile' => 'internal',
                ];
            }
        });

        $response = $controller->__invoke($this->user('admin@example.com', ['ROLE_ADMIN']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            '{"username":"admin@example.com","roles":["ROLE_ADMIN"],"appProfile":"internal"}',
            $response->getContent(),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $identifier, array $roles): UserInterface
    {
        return new readonly class ($identifier, $roles) implements UserInterface {
            public function __construct(
                private string $identifier,
                private array $roles,
            ) {
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }
}