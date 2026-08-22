<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Identity\Exception\IdentityException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The default gateway: a Doctrine entity with the accessors Symfony security
 * already requires.
 *
 * Enough for the common shape — an entity with an identifier property, a
 * password and a roles array — and honest about it: anything else aliases
 * {@see IdentityUserGatewayInterface} and writes ten lines instead of bending a
 * configuration option until it fits.
 */
final readonly class DoctrineIdentityUserGateway implements IdentityUserGatewayInterface
{
    /** @param class-string<UserInterface> $userClass */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private string $userClass,
        private string $identifierProperty = 'email',
    ) {}

    public function findByIdentifier(string $identifier): ?UserInterface
    {
        $user = $this->entityManager
            ->getRepository($this->userClass)
            ->findOneBy([$this->identifierProperty => $identifier]);

        return $user instanceof UserInterface ? $user : null;
    }

    public function changePassword(UserInterface $user, string $plainPassword): void
    {
        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new IdentityException('This user cannot hold a password.');
        }

        $this->write($user, 'password', $this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();
    }

    public function createUser(string $identifier, string $plainPassword, array $roles): UserInterface
    {
        $user = new $this->userClass();

        $this->write($user, $this->identifierProperty, $identifier);
        if ([] !== $roles) {
            $this->write($user, 'roles', $roles);
        }

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            throw new IdentityException('This user cannot hold a password.');
        }

        $this->write($user, 'password', $this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function write(object $user, string $property, mixed $value): void
    {
        $setter = 'set' . ucfirst($property);

        if (method_exists($user, $setter)) {
            $user->{$setter}($value);

            return;
        }

        $reflection = new \ReflectionObject($user);
        if ($reflection->hasProperty($property)) {
            $reflection->getProperty($property)->setValue($user, $value);

            return;
        }

        throw new IdentityException(sprintf(
            'The user entity exposes no way to write "%s". Alias IdentityUserGatewayInterface with your own '
            . 'implementation.',
            $property,
        ));
    }
}
