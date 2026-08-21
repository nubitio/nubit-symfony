<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Export;

use Nubit\AdminBundle\Export\EventListener\ExportContentDispositionListener;
use Nubit\AdminBundle\Export\XlsxEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * This listener runs on every main response, so it has to stay inert for the
 * requests it does not care about — including the common case where API
 * Platform's optional `{._format}` suffix leaves `_format` present but null.
 */
final class ExportContentDispositionListenerTest extends TestCase
{
    public function testAttachesADownloadFilenameForTheExportFormat(): void
    {
        $response = self::handle([
            '_format' => XlsxEncoder::FORMAT,
            '_api_resource_class' => 'App\\Entity\\SalesDocument',
        ]);

        self::assertSame(
            sprintf('attachment; filename="sales-document-%s.xlsx"', date('Y-m-d')),
            $response->headers->get('Content-Disposition'),
        );
    }

    public function testFallsBackToAGenericNameWithoutAResourceClass(): void
    {
        $response = self::handle(['_format' => XlsxEncoder::FORMAT]);

        self::assertSame(
            sprintf('attachment; filename="export-%s.xlsx"', date('Y-m-d')),
            $response->headers->get('Content-Disposition'),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function untouchedRequestCases(): iterable
    {
        // The regression: a plain `GET /api/customers` matches a route whose
        // `{._format}` suffix went unmatched, leaving a null attribute. Reading
        // it with getString() threw and turned the response into a 500.
        yield 'format attribute present but null' => [['_format' => null]];
        yield 'no format attribute at all' => [[]];
        yield 'another negotiated format' => [['_format' => 'jsonld']];
        yield 'format attribute is not a string' => [['_format' => ['xlsx']]];
        yield 'resource class is not a string' => [
            ['_format' => 'jsonld', '_api_resource_class' => ['App\\Entity\\Customer']],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('untouchedRequestCases')]
    public function testLeavesEveryOtherResponseUntouched(array $attributes): void
    {
        $response = self::handle($attributes);

        self::assertFalse($response->headers->has('Content-Disposition'));
    }

    public function testKeepsAContentDispositionSetUpstream(): void
    {
        $response = new Response();
        $response->headers->set('Content-Disposition', 'inline');

        self::handle(['_format' => XlsxEncoder::FORMAT], $response);

        self::assertSame('inline', $response->headers->get('Content-Disposition'));
    }

    public function testIgnoresSubRequests(): void
    {
        $response = self::handle(['_format' => XlsxEncoder::FORMAT], null, HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('Content-Disposition'));
    }

    /** @param array<string, mixed> $attributes */
    private static function handle(
        array $attributes,
        ?Response $response = null,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $request = new Request();
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        $response ??= new Response();
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, $response);

        (new ExportContentDispositionListener())->onKernelResponse($event);

        return $response;
    }
}
