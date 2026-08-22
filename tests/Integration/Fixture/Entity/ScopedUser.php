<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user carrying a row-scope claim, as an application would model one.
 *
 * `warehouses` is null for an unscoped account — a manager — and a list of ids
 * for a scoped one. Null and empty deliberately mean different things: null is
 * "no restriction", empty is "restricted to nothing".
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_scoped_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_FIXTURE_SCOPED_USER_EMAIL', columns: ['email'])]
class ScopedUser implements UserInterface, PasswordAuthenticatedUserInterface, FixtureEntity
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
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** @var list<int>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $warehouses = null;

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
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /** @return list<int>|null */
    public function getWarehouses(): ?array
    {
        return $this->warehouses;
    }

    /** @param list<int>|null $warehouses */
    public function setWarehouses(?array $warehouses): static
    {
        $this->warehouses = $warehouses;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Fixture user was persisted without an email.');
        }

        return $this->email;
    }

    public function eraseCredentials(): void {}
}
