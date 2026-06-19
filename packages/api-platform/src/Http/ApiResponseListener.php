<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final readonly class ApiResponseListener
{
    public function __construct(
        private GridSummaryCalculator $gridSummaryCalculator,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $data = $request->attributes->get('data');

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
}
