<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use Throwable;
use Nubit\Platform\Exception\DomainProblemException;
use Nubit\Platform\Exception\NotFoundException;
use Nubit\Platform\Exception\QuotaExceededException;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener]
final readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof ServiceException) {
            return;
        }

        $this->logger->error($exception->getMessage(), [
            'exception' => $exception,
        ]);

        $isDev = 'dev' === $this->environment;
        $statusCode = $this->getStatusCode($exception);

        $data = [
            'type' => '/errors/service-error',
            'title' => 'Service error',
            'status' => $statusCode,
            'detail' => $exception->getMessage(),
            // Legacy fields kept during migration for older frontend paths.
            'success' => false,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        if ($exception instanceof DomainProblemException) {
            $data['type'] = $exception->type;
            $data['title'] = $exception->title;
            $data['errorCode'] = $exception->errorCode->value;
            $data['action'] = $exception->action;
            $data['numericCode'] = $exception->numericCode;
        }

        if ($exception instanceof ValidationException) {
            $data['errors'] = $exception->getErrors();
        }

        if ($isDev) {
            $data['trace'] = $exception->getTrace();
            $data['file'] = $exception->getFile();
            $data['line'] = $exception->getLine();
        }

        $response = new JsonResponse($data, $statusCode);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }

    private function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof NotFoundException) {
            return Response::HTTP_NOT_FOUND;
        }

        if ($exception instanceof ValidationException) {
            return Response::HTTP_UNPROCESSABLE_ENTITY;
        }
        if ($exception instanceof QuotaExceededException) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }

        $code = $exception->getCode();

        return ($code >= 400 && $code < 600) ? $code : Response::HTTP_BAD_REQUEST;
    }
}
