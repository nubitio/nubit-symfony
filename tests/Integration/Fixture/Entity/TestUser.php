<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Minimal account for the authentication suite — the shape the skeleton's
 * `App\Entity\User` takes, without anything the tests do not exercise.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_FIXTURE_USER_EMAIL', columns: ['email'])]
class TestUser implements UserInterface, PasswordAuthenticatedUserInterface, FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        // Symfony declares this non-empty; an account with a blank email is a
        // seeding mistake, and returning it would fail authentication with a
        // far less obvious message than this one.
        if ('' === $this->email) {
            throw new \LogicException('Fixture user was persisted without an email.');
        }

        return $this->email;
    }

    public function eraseCredentials(): void {}
}
