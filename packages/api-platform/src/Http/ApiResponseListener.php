<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nubit\ApiPlatform\Doctrine\ApproximateCounter;
use Nubit\ApiPlatform\Doctrine\GridScaleRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final readonly class ApiResponseListener
{
    public function __construct(
        private GridSummaryCalculator $gridSummaryCalculator,
        private ?GridScaleRegistry $gridScales = null,
        private ?ApproximateCounter $approximateCounter = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $data = $request->attributes->get('data');

        if ($data instanceof PartialPaginatorInterface && !$data instanceof PaginatorInterface) {
            // Partial pagination deliberately skips the COUNT, which is why it
            // scales — but it also leaves the grid with nothing to show. An
            // estimate from the planner's own statistics costs a single indexed
            // lookup and gives the footer an honest "about N".
            $this->addEstimatedCount($event, $request);

            return;
        }

        if ($data instanceof PaginatorInterface) {
            $response = $event->getResponse();
            $response->headers->add([
                'X-Total-Count' => $data->getTotalItems(),
                'X-Total-Pages' => (int) ceil($data->getTotalItems() / $data->getItemsPerPage()),
                'X-Current-Page' => $data->getCurrentPage(),
            ]);

            $resourceClass = $request->attributes->get('_api_resource_class');
            if (\is_string($resourceClass)) {
                $summary = $this->gridSummaryCalculator->compute($resourceClass, $request);
                if ($summary !== []) {
                    $response->headers->set('X-Grid-Summary', (string) json_encode($summary, \JSON_THROW_ON_ERROR));
                }
            }
        }
    }

    /**
     * Adds `X-Estimated-Count` when the resource has opted out of exact counts.
     *
     * Only for an unfiltered collection. A filtered count cannot be estimated
     * from table statistics, and a number that quietly ignored the filter would
     * be worse than no number at all — so the header is simply absent, and the
     * client shows a page indicator instead of a total.
     */
    private function addEstimatedCount(ResponseEvent $event, \Symfony\Component\HttpFoundation\Request $request): void
    {
        if (null === $this->approximateCounter || null === $this->entityManager || null === $this->gridScales) {
            return;
        }

        $resourceClass = $request->attributes->get('_api_resource_class');
        if (!\is_string($resourceClass) || ($this->gridScales->find($resourceClass)?->exactCount ?? true)) {
            return;
        }

        foreach (['filter', 'searchValue'] as $parameter) {
            if ($request->query->has($parameter)) {
                return;
            }
        }

        try {
            $table = $this->entityManager->getClassMetadata($resourceClass)->getTableName();
        } catch (\Throwable) {
            return;
        }

        $estimate = $this->approximateCounter->estimate($table);
        if (null !== $estimate) {
            $event->getResponse()->headers->set('X-Estimated-Count', (string) $estimate);
        }
    }
}
