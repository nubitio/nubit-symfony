<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Http;

use Nubit\Platform\Http\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponsePayloadTest extends TestCase
{
    /** @return array<string, mixed> */
    private function decode(ApiResponse $r): array
    {
        return json_decode((string) $r->getContent(), true);
    }

    public function testSuccessConstructorSets200AndTrueFlag(): void
    {
        $r = new ApiResponse(true, 'OK', ['id' => 1]);

        self::assertSame(200, $r->getStatusCode());
        self::assertTrue($r->success);
        self::assertSame('OK', $r->message);
    }

    public function testSuccessResponseBodyContainsData(): void
    {
        $r = new ApiResponse(true, 'OK', ['id' => 1]);
        $body = $this->decode($r);

        self::assertTrue($body['success']);
        self::assertSame('OK', $body['message']);
        self::assertSame(['id' => 1], $body['data']);
    }

    public function testErrorConstructorSets400AndFalseFlag(): void
    {
        $r = new ApiResponse(false, 'Oops');

        self::assertSame(400, $r->getStatusCode());
        self::assertFalse($r->success);
    }

    public function testErrorResponseBodyHasNullData(): void
    {
        $r = new ApiResponse(false, 'Oops');
        $body = $this->decode($r);

        self::assertFalse($body['success']);
        self::assertNull($body['data']);
    }

    public function testSuccessFactoryReturns200(): void
    {
        $r = ApiResponse::success('Created', ['key' => 'val']);

        self::assertSame(200, $r->getStatusCode());
        self::assertTrue($r->success);
        self::assertSame('Created', $r->message);
    }

    public function testErrorFactoryReturns400(): void
    {
        $r = ApiResponse::error('Not found');

        self::assertSame(400, $r->getStatusCode());
        self::assertFalse($r->success);
    }

    public function testToArrayContainsAllKeys(): void
    {
        $r = new ApiResponse(true, 'msg', 42);

        self::assertSame(
            ['success' => true, 'message' => 'msg', 'data' => 42],
            $r->toArray()
        );
    }

    public function testJsonContentMatchesToArray(): void
    {
        $r    = ApiResponse::success('done', ['x' => 1]);
        $body = $this->decode($r);

        self::assertSame($r->toArray(), $body);
    }
}
