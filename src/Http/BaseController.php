<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use Nubit\Platform\Http\ApiResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

abstract class BaseController extends AbstractController
{
    public function __construct(
        protected readonly SerializerInterface $serializer,
    ) {
    }

    protected function success(
        string $message = 'Success',
        mixed $data = null,
        int $status = Response::HTTP_OK
    ): JsonResponse {
        return $this->json(
            ApiResponse::success($message, $data)->toArray(),
            $status
        );
    }

    protected function error(
        string $message,
        mixed $data = null,
        int $status = Response::HTTP_BAD_REQUEST
    ): JsonResponse {
        return $this->json(
            ApiResponse::error($message, $data)->toArray(),
            $status
        );
    }

    protected function created(string $message = 'Created', mixed $data = null): JsonResponse
    {
        return $this->success($message, $data, Response::HTTP_CREATED);
    }

    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, null, Response::HTTP_NOT_FOUND);
    }
}
