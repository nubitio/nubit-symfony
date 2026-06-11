<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final readonly class ApiResponseListener
{
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
        }
    }
}
