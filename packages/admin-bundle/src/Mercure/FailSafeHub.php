<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Mercure;

use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Hub decorator that keeps a dead Mercure hub from breaking writes.
 *
 * API Platform publishes `mercure: true` resource updates AFTER the Doctrine
 * flush: when the hub is unreachable the row is already persisted but the
 * client gets a 500 — it retries and duplicates data. Live grid refresh is a
 * progressive enhancement; losing one update must never fail the request.
 *
 * Context-aware on purpose:
 * - During an HTTP request: failures are logged (warning) and swallowed —
 *   the response stays 2xx.
 * - Outside a request (messenger worker, console): failures are RETHROWN so
 *   an async `Update` routing keeps its retry semantics — swallowing there
 *   would mark the message handled and lose the event permanently.
 */
final class FailSafeHub implements HubInterface
{
    public function __construct(
        private readonly HubInterface $inner,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {}

    public function publish(Update $update): string
    {
        try {
            return $this->inner->publish($update);
        } catch (Throwable $exception) {
            if ($this->requestStack->getCurrentRequest() === null) {
                throw $exception;
            }

            $this->logger->warning('Mercure publish failed; the response is preserved and the live update is lost.', [
                'topics' => $update->getTopics(),
                'exception' => $exception,
            ]);

            return '';
        }
    }

    public function getUrl(): string
    {
        if (!$this->inner instanceof RemoteHubInterface) {
            throw new LogicException('The decorated Mercure hub does not expose an internal URL.');
        }

        return $this->inner->getUrl();
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }
}
