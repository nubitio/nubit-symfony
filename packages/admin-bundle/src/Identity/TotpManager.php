<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Identity\Entity\TotpCredential;
use Nubit\AdminBundle\Identity\Exception\TotpException;
use Nubit\Platform\Identity\Totp;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Enrolment and verification of the second factor.
 *
 * Three rules live here and nowhere else, because each of them is a way second
 * factors are commonly got wrong:
 *
 *  - a credential is not in force until the user proves they can produce a code
 *    from it, so scanning a QR and closing the tab cannot lock anyone out;
 *  - a code is single-use, because a code stays valid for its whole window and
 *    an observed one is otherwise replayable for a minute and a half;
 *  - a recovery code is consumed when used, and is stored hashed, because it is
 *    a password that bypasses the second factor.
 */
final readonly class TotpManager
{
    /** Enough that losing a phone is survivable; few enough that they get written down once. */
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TotpPolicy $policy,
        private string $issuer = 'Nubit',
    ) {}

    public function find(string $userIdentifier): ?TotpCredential
    {
        $credential = $this->entityManager
            ->getRepository(TotpCredential::class)
            ->findOneBy(['userIdentifier' => $userIdentifier]);

        return $credential instanceof TotpCredential ? $credential : null;
    }

    public function isEnrolled(string $userIdentifier): bool
    {
        return $this->find($userIdentifier)?->isConfirmed() ?? false;
    }

    /** Whether this user must present a second factor to sign in. */
    public function isRequiredFor(UserInterface $user): bool
    {
        return $this->policy->requires($user) || $this->isEnrolled($user->getUserIdentifier());
    }

    /**
     * Starts enrolment and returns what the user needs, once.
     *
     * Replacing an unconfirmed credential is deliberate: a user who abandoned
     * enrolment halfway must be able to start again, and the abandoned secret
     * was never in force.
     *
     * @return array{secret: string, uri: string, recoveryCodes: list<string>}
     */
    public function beginEnrolment(string $userIdentifier): array
    {
        $existing = $this->find($userIdentifier);

        if (null !== $existing && $existing->isConfirmed()) {
            throw new TotpException('This account already has a second factor; remove it before enrolling again.');
        }

        if (null !== $existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $secret = Totp::generateSecret();
        $plainCodes = $this->generateRecoveryCodes();

        $credential = new TotpCredential($userIdentifier, $secret);
        $credential->setRecoveryCodes(array_map($this->hashRecoveryCode(...), $plainCodes));

        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, $userIdentifier, $this->issuer),
            // The only time these are ever readable. Stored hashed from here on.
            'recoveryCodes' => $plainCodes,
        ];
    }

    /** Proves the user can produce a code, and puts the credential in force. */
    public function confirmEnrolment(string $userIdentifier, string $code): void
    {
        $credential = $this->find($userIdentifier);

        if (null === $credential) {
            throw new TotpException('Start enrolment before confirming it.');
        }

        if ($credential->isConfirmed()) {
            throw new TotpException('This second factor is already confirmed.');
        }

        $step = Totp::verify($credential->getSecret(), $code);
        if (null === $step) {
            throw new TotpException('That code is not valid. Check the clock on your device and try again.');
        }

        $credential->confirm()->markStepUsed($step);
        $this->entityManager->flush();
    }

    /**
     * Verifies a code — or a recovery code — at sign-in.
     *
     * Returns quietly on success and throws otherwise, so a caller cannot
     * mistake a falsy return for a pass.
     */
    public function verify(string $userIdentifier, string $code): void
    {
        $credential = $this->find($userIdentifier);

        if (null === $credential || !$credential->isConfirmed()) {
            throw new TotpException('This account has no confirmed second factor.');
        }

        $step = Totp::verify($credential->getSecret(), $code);

        if (null !== $step) {
            if ($credential->isStepSpent($step)) {
                // The code is arithmetically valid but already spent. Refusing
                // it is what makes an observed code useless.
                throw new TotpException('That code has already been used. Wait for the next one.');
            }

            $credential->markStepUsed($step);
            $this->entityManager->flush();

            return;
        }

        if ($this->consumeRecoveryCode($credential, $code)) {
            return;
        }

        throw new TotpException('That code is not valid.');
    }

    /** Removing the second factor removes the recovery codes with it. */
    public function disable(string $userIdentifier): void
    {
        $credential = $this->find($userIdentifier);

        if (null === $credential) {
            return;
        }

        $this->entityManager->remove($credential);
        $this->entityManager->flush();
    }

    /** @return list<string> The new plaintext codes, readable only here. */
    public function regenerateRecoveryCodes(string $userIdentifier): array
    {
        $credential = $this->find($userIdentifier);

        if (null === $credential || !$credential->isConfirmed()) {
            throw new TotpException('This account has no confirmed second factor.');
        }

        $plainCodes = $this->generateRecoveryCodes();
        $credential->setRecoveryCodes(array_map($this->hashRecoveryCode(...), $plainCodes));
        $this->entityManager->flush();

        return $plainCodes;
    }

    private function consumeRecoveryCode(TotpCredential $credential, string $candidate): bool
    {
        $normalized = self::normalizeRecoveryCode($candidate);
        if ('' === $normalized) {
            return false;
        }

        $hash = $this->hashRecoveryCode($normalized);

        // Every stored hash is compared, and always all of them: returning early
        // on a match would make "which position matched" measurable.
        $matched = false;
        foreach ($credential->getRecoveryCodes() as $stored) {
            if (hash_equals($stored, $hash)) {
                $matched = true;
            }
        }

        if (!$matched) {
            return false;
        }

        $credential->consumeRecoveryCode($hash);
        $this->entityManager->flush();

        return true;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            // Grouped in fives so a person can read one off paper without
            // losing their place.
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    /**
     * SHA-256 rather than a password hash.
     *
     * A recovery code is 40 bits of true randomness, so it has no dictionary to
     * be attacked with — the work factor a password hash exists to add buys
     * nothing, and would make every sign-in attempt pay for ten comparisons.
     */
    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', self::normalizeRecoveryCode($code));
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
