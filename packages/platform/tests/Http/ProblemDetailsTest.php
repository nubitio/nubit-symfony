<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Http;

use Nubit\Platform\Exception\DomainErrorCode;
use Nubit\Platform\Exception\DomainProblemException;
use Nubit\Platform\Http\ProblemDetails;
use Nubit\Platform\Http\ProblemResponse;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function testProblemDetailsSerializesRfc7807Shape(): void
    {
        $problem = new ProblemDetails(
            type: 'https://example.test/problem',
            title: 'Invalid state',
            status: 409,
            detail: 'Cannot continue.',
            code: 'INVALID_STATE',
            action: 'retry',
            extensions: ['resource' => 'sale'],
        );

        self::assertSame(
            [
                'type' => 'https://example.test/problem',
                'title' => 'Invalid state',
                'status' => 409,
                'detail' => 'Cannot continue.',
                'code' => 'INVALID_STATE',
                'action' => 'retry',
                'extensions' => ['resource' => 'sale'],
            ],
            $problem->toArray(),
        );
    }

    public function testProblemResponseUsesProblemJsonContentTypeAndStatus(): void
    {
        $response = new ProblemResponse(new ProblemDetails(
            type: 'about:blank',
            title: 'Payment required',
            status: 402,
            detail: 'Upgrade required.',
        ));

        self::assertSame(402, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function testDomainProblemExceptionCanExposeGenericProblemDetails(): void
    {
        $exception = new DomainProblemException(
            errorCode: DomainErrorCode::SaleCashSessionRequired,
            detail: 'Open a cash session first.',
            title: 'Cash session required',
            type: 'https://docs.nubit.test/problems/cash-session-required',
            action: 'open_cash_session',
            numericCode: 1001,
            statusCode: 409,
        );

        self::assertSame(
            [
                'type' => 'https://docs.nubit.test/problems/cash-session-required',
                'title' => 'Cash session required',
                'status' => 409,
                'detail' => 'Open a cash session first.',
                'code' => 'SALE_CASH_SESSION_REQUIRED',
                'action' => 'open_cash_session',
                'extensions' => ['numericCode' => 1001],
            ],
            $exception->toProblemDetails()->toArray(),
        );
    }
}
