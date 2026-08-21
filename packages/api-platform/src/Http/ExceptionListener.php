<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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
use Throwable;

#[AsEventListener]
final readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ForeignKeyConstraintViolationException) {
            $this->handleForeignKeyConstraintViolation($event, $exception);

            return;
        }

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

        return $code >= 400 && $code < 600 ? $code : Response::HTTP_BAD_REQUEST;
    }

    /**
     * Doctrine's default handling surfaces a raw SQLSTATE message ("update or
     * delete on table X violates foreign key constraint...") as a bare 500 —
     * technically correct (the delete/update was rightly blocked) but useless
     * to an end user and leaks schema details. This translates it to a 409
     * with a message a client can display, still logging the original
     * exception (and, in dev, still exposing its detail) for diagnosis.
     */
    private function handleForeignKeyConstraintViolation(
        ExceptionEvent $event,
        ForeignKeyConstraintViolationException $exception,
    ): void {
        $this->logger->warning('Rejected a write that would have violated a foreign key constraint.', [
            'exception' => $exception,
        ]);

        $isDev = 'dev' === $this->environment;

        $data = [
            'type' => '/errors/foreign-key-constraint',
            'title' => 'Referenced by another record',
            'status' => Response::HTTP_CONFLICT,
            'detail' => 'No se puede completar la operación: el registro está siendo referenciado por otro registro.',
        ];

        if ($isDev) {
            $data['sqlMessage'] = $exception->getMessage();
            $data['trace'] = $exception->getTrace();
            $data['file'] = $exception->getFile();
            $data['line'] = $exception->getLine();
        }

        $response = new JsonResponse($data, Response::HTTP_CONFLICT);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }
}
