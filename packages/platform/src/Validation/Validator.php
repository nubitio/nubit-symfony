<?php

declare(strict_types=1);

namespace Nubit\Platform\Validation;

use Nubit\Platform\Exception\ValidationException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class Validator
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param array<int, mixed> $constraints
     * @param array<int, string> $groups
     */
    public function validate(mixed $value, array $constraints = [], array $groups = []): void
    {
        $violations = $this->validator->validate($value, $constraints, $groups);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            throw ValidationException::withErrors($errors);
        }
    }

    /**
     * @param array<int, mixed> $constraints
     * @param array<int, string> $groups
     */
    public function isValid(mixed $value, array $constraints = [], array $groups = []): bool
    {
        $violations = $this->validator->validate($value, $constraints, $groups);

        return count($violations) === 0;
    }

    /**
     * @param array<int, mixed> $constraints
     * @param array<int, string> $groups
     * @return array<string, array<string>>
     */
    public function getViolations(mixed $value, array $constraints = [], array $groups = []): array
    {
        $violations = $this->validator->validate($value, $constraints, $groups);
        $errors = [];

        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return $errors;
    }
}
