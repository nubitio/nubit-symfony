<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Tests\Http;

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
