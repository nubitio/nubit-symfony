<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\Common\Collections\Collection;

/**
 * Binds embedded line entities to their parent on every persist operation.
 * Extend and implement {@see linesProperty()} plus optional hooks.
 *
 * @template TParent of object
 * @template TLine of object
 *
 * @implements ProcessorInterface<TParent, TParent>
 */
abstract readonly class AbstractEmbeddedLinesProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<mixed, mixed> $persistProcessor */
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($this->supports($data)) {
            $this->syncLines($data);
            $this->afterLinesSynced($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    abstract protected function supports(mixed $data): bool;

    /** @return non-empty-string */
    abstract protected function linesProperty(): string;

    /** @return non-empty-string */
    abstract protected function lineSetter(): string;

    protected function afterLinesSynced(mixed $data): void
    {
    }

    private function syncLines(mixed $parent): void
    {
        $lines = $this->readProperty($parent, $this->linesProperty());
        if (!$lines instanceof Collection) {
            return;
        }

        $setter = $this->lineSetter();
        foreach ($lines as $line) {
            if (!\is_object($line)) {
                continue;
            }

            if (!method_exists($line, $setter)) {
                continue;
            }

            $line->{$setter}($parent);
        }
    }

    private function readProperty(object $object, string $property): mixed
    {
        $getter = 'get' . ucfirst($property);
        if (!method_exists($object, $getter)) {
            return null;
        }

        return $object->{$getter}();
    }
}