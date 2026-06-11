<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Http;

use Nubit\Platform\Http\ApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiResponse::class)]
final class ApiResponseTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decode the JSON body back to an associative array for assertion.
     *
     * JsonResponse serialises $data to JSON in the parent constructor, so
     * $response->data ends up as the raw JSON string after construction.
     * We decode the body to verify the payload round-trips correctly.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(ApiResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // success() factory
    // -------------------------------------------------------------------------

    public function testSuccessFactoryReturnsSuccessResponseWithDataAndHttp200(): void
    {
        $response = ApiResponse::success('OK', ['key' => 'val']);

        self::assertTrue($response->success);
        self::assertSame('OK', $response->message);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertTrue($body['success']);
        self::assertSame('OK', $body['message']);
        self::assertSame(['key' => 'val'], $body['data']);
    }

    public function testSuccessFactoryWithNoDataSetsDataToNullInBody(): void
    {
        $response = ApiResponse::success('Done');

        self::assertTrue($response->success);
        self::assertSame('Done', $response->message);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertNull($body['data']);
    }

    // -------------------------------------------------------------------------
    // error() factory
    // -------------------------------------------------------------------------

    public function testErrorFactoryReturnsFailureResponseWithHttp400(): void
    {
        $response = ApiResponse::error('Bad', null);

        self::assertFalse($response->success);
        self::assertSame('Bad', $response->message);
        self::assertSame(400, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertFalse($body['success']);
        self::assertNull($body['data']);
    }

    // -------------------------------------------------------------------------
    // toArray()
    // -------------------------------------------------------------------------

    public function testToArrayContainsSuccessAndMessageKeys(): void
    {
        $response = ApiResponse::success('OK', ['key' => 'val']);

        $array = $response->toArray();

        // success and message are set before parent::__construct() so they
        // retain their original typed values.
        self::assertTrue($array['success']);
        self::assertSame('OK', $array['message']);
        // The 'data' key is present (its value is the JSON-serialised string
        // because JsonResponse::setData() overwrites $this->data).
        self::assertArrayHasKey('data', $array);
    }

    public function testToArrayForErrorResponseHasCorrectSuccessAndMessage(): void
    {
        $response = ApiResponse::error('Oops');

        $array = $response->toArray();

        self::assertFalse($array['success']);
        self::assertSame('Oops', $array['message']);
        self::assertArrayHasKey('data', $array);
    }

    // -------------------------------------------------------------------------
    // Constructor status codes
    // -------------------------------------------------------------------------

    public function testConstructorWithSuccessTrueReturnsHttp200(): void
    {
        $response = new ApiResponse(true, 'Created', ['id' => 1]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testConstructorWithSuccessFalseReturnsHttp400(): void
    {
        $response = new ApiResponse(false, 'Validation failed');

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($response->success);
    }
}
