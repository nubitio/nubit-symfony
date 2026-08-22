<?php

declare(strict_types=1);

namespace Nubit\Platform\Time;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one place that decides which timezone an instant is *shown* in.
 *
 * Storage is not part of the question: timestamps are written in UTC, always,
 * and that is enforced elsewhere. What varies is presentation, and it varies by
 * viewer — a purchase order raised at 23:30 in Lima is dated the next day in
 * Madrid, and an ERP that lets each layer answer that differently will report
 * the same document in two periods.
 *
 * The chain is deliberately short and ordered from most specific to least:
 * the user's own preference, then the tenant's, then the deployment default,
 * then UTC.
 */
final readonly class TimeZoneResolver
{
    public function __construct(
        private string $defaultTimeZone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param object|null $user   Consulted when it implements {@see TimeZoneAwareInterface}
     * @param object|null $tenant Same, and only reached when the user expressed no preference
     */
    public function resolve(?object $user = null, ?object $tenant = null): \DateTimeZone
    {
        foreach ([$user, $tenant] as $source) {
            $identifier = $source instanceof TimeZoneAwareInterface ? $source->getTimeZone() : null;

            $zone = $this->toTimeZone($identifier);
            if (null !== $zone) {
                return $zone;
            }
        }

        return $this->toTimeZone($this->defaultTimeZone) ?? new \DateTimeZone('UTC');
    }

    /** The identifier the API reports, so the frontend formats the way the backend intends. */
    public function resolveIdentifier(?object $user = null, ?object $tenant = null): string
    {
        return $this->resolve($user, $tenant)->getName();
    }

    /**
     * Restates a stored instant for display. The instant itself never moves —
     * only the wall-clock reading of it does.
     */
    public function toDisplay(
        \DateTimeInterface $instant,
        ?object $user = null,
        ?object $tenant = null,
    ): \DateTimeImmutable {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone($this->resolve($user, $tenant));
    }

    /**
     * A misconfigured timezone must not take the request down, and must not
     * silently become the server's local zone either — falling back to the next
     * step in the chain is both visible in the log and safe.
     */
    private function toTimeZone(?string $identifier): ?\DateTimeZone
    {
        if (null === $identifier || '' === trim($identifier)) {
            return null;
        }

        try {
            return new \DateTimeZone($identifier);
        } catch (\Exception $exception) {
            $this->logger->warning('Ignoring an unknown timezone identifier.', [
                'identifier' => $identifier,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
