<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Tests\Http;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Nubit\ApiPlatform\Http\ExceptionListener;
use Nubit\Platform\Exception\DomainErrorCode;
use Nubit\Platform\Exception\DomainProblemException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExceptionListenerTest extends TestCase
{
    /**
     * Regression coverage: deleting/updating a row still referenced by a
     * foreign key used to fall through this listener untouched, leaving
     * Symfony's default handling to return a bare 500 with the raw
     * SQLSTATE message. It must now become a 409 with a message a client
     * can display, without leaking the underlying schema in prod.
     */
    public function testForeignKeyConstraintViolationBecomesA409InProd(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/liquidacions/1', 'DELETE'),
            HttpKernelInterface::MAIN_REQUEST,
            new class('SQLSTATE[23503]: Foreign key violation') extends ForeignKeyConstraintViolationException {
                public function __construct(string $message)
                {
                    // Bypass DriverException::__construct(), which requires a
                    // real Driver\Exception to chain — irrelevant here, this
                    // only needs to be catchable as this exception type.
                    \Exception::__construct($message);
                }
            },
        );

        (new ExceptionListener(new NullLogger(), 'prod'))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(409, $payload['status']);
        self::assertSame(
            'No se puede completar la operación: el registro está siendo referenciado por otro registro.',
            $payload['detail'],
        );
        self::assertArrayNotHasKey('sqlMessage', $payload, 'must not leak the raw SQLSTATE message in prod');
    }

    public function testForeignKeyConstraintViolationExposesSqlDetailInDev(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/liquidacions/1', 'DELETE'),
            HttpKernelInterface::MAIN_REQUEST,
            new class('SQLSTATE[23503]: Foreign key violation') extends ForeignKeyConstraintViolationException {
                public function __construct(string $message)
                {
                    \Exception::__construct($message);
                }
            },
        );

        (new ExceptionListener(new NullLogger(), 'dev'))($event);

        $payload = json_decode((string) $event->getResponse()?->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('SQLSTATE[23503]: Foreign key violation', $payload['sqlMessage']);
    }

    public function testDomainProblemExceptionProducesProblemDetailsWithStableCode(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/sales', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new DomainProblemException(
                errorCode: DomainErrorCode::SaleCashSessionRequired,
                detail: 'No puedes realizar ventas, no tienes una caja abierta',
                title: 'Cash register session required',
                type: '/errors/sale-cash-session-required',
                action: 'OPEN_CASH_REGISTER',
                numericCode: 1000,
            ),
        );

        (new ExceptionListener(new NullLogger(), 'prod'))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/errors/sale-cash-session-required', $payload['type']);
        self::assertSame('Cash register session required', $payload['title']);
        self::assertSame(422, $payload['status']);
        self::assertSame('No puedes realizar ventas, no tienes una caja abierta', $payload['detail']);
        self::assertSame('SALE_CASH_SESSION_REQUIRED', $payload['errorCode']);
        self::assertSame('OPEN_CASH_REGISTER', $payload['action']);
        self::assertSame(1000, $payload['numericCode']);
        self::assertSame($payload['detail'], $payload['message']);
    }
}
