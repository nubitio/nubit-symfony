<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Document;

use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\Entity\IssuedDocument;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Platform\Money\Money;
use Nubit\Tests\Integration\Fixture\Document\RecordingRenderer;
use Nubit\Tests\Integration\Fixture\Entity\Payment;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issuing a document, and the rules that make it a record rather than a render.
 *
 * The behaviour worth guarding is not "a PDF comes out". It is that the second
 * request returns the *first* PDF, that a correction leaves the original
 * readable, and that the bytes on disk are provably the bytes that were handed
 * out. All three only exist once a database, a filesystem and an HTTP pipeline
 * are involved at the same time.
 */
#[CoversNothing]
final class IssuedDocumentTest extends IntegrationTestCase
{
    private int $paymentId = 0;

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'documents' => [
                        'enabled' => true,
                        'storage' => ['local_directory' => sys_get_temp_dir() . '/nubit-documents-test'],
                    ],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->seedPayment();
    }

    public function testIssuingProducesAReadyDocument(): void
    {
        $document = $this->issuer()->issue($this->payment(), 'admin@example.com');

        self::assertSame(IssuedDocument::STATUS_READY, $document->getStatus());
        self::assertSame('P-1', $document->getDocumentNumber());
        self::assertSame('application/pdf', $document->getMediaType());
        self::assertNotNull($document->getChecksum());
        self::assertSame('admin@example.com', $document->getIssuedBy());
    }

    /** The template receives the resource and the issue context, not a stale copy. */
    public function testTheTemplateSeesTheResource(): void
    {
        $this->issuer()->issue($this->payment());

        $html = $this->renderer()->renderedHtml[0] ?? '';

        self::assertStringContainsString('P-1', $html);
        self::assertStringContainsString('42.50 EUR', $html);
    }

    /**
     * The rule the module exists for. A template change six months from now must
     * not rewrite invoices that are already in someone else's hands.
     */
    public function testIssuingTwiceReturnsTheSameDocumentWithoutRendering(): void
    {
        $first = $this->issuer()->issue($this->payment());
        $firstBytes = $this->issuer()->bytes($first);

        $second = $this->issuer()->issue($this->payment());

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->renderer()->calls, 'The document was rendered a second time.');
        self::assertSame($firstBytes, $this->issuer()->bytes($second));
    }

    public function testTheStoredBytesMatchTheirChecksum(): void
    {
        $document = $this->issuer()->issue($this->payment());

        self::assertSame(hash('sha256', $this->issuer()->bytes($document)), $document->getChecksum());
        self::assertSame(strlen($this->issuer()->bytes($document)), $document->getByteSize());
    }

    /**
     * A correction is a new document, never an edit. The copy the customer
     * already holds has to stay explainable.
     */
    public function testReissuingSupersedesRatherThanOverwrites(): void
    {
        $original = $this->issuer()->issue($this->payment());
        $originalBytes = $this->issuer()->bytes($original);

        $correction = $this->issuer()->reissue($this->payment(), 'admin@example.com');

        self::assertNotSame($original->getId(), $correction->getId());
        self::assertSame($original->getId(), $correction->getSupersedesId());
        self::assertSame($correction->getId(), $original->getSupersededById());
        self::assertTrue($original->isSuperseded());

        // The superseded copy is still readable, byte for byte.
        self::assertSame($originalBytes, $this->issuer()->bytes($original));
        self::assertNotSame($originalBytes, $this->issuer()->bytes($correction));
    }

    public function testTheCorrectionBecomesTheCurrentDocument(): void
    {
        $this->issuer()->issue($this->payment());
        $correction = $this->issuer()->reissue($this->payment());

        self::assertSame($correction->getId(), $this->issuer()->current($this->payment())?->getId());
        self::assertCount(2, $this->issuer()->history($this->payment()));
    }

    /**
     * A failed render must leave the row behind carrying the reason. A failure
     * that vanished would leave a user pressing a button that appears to do
     * nothing at all.
     */
    public function testAFailedRenderIsRecorded(): void
    {
        $this->renderer()->shouldFail = true;

        $document = $this->issuer()->issue($this->payment());

        self::assertSame(IssuedDocument::STATUS_FAILED, $document->getStatus());
        self::assertStringContainsString('rendering engine is unavailable', (string) $document->getFailureReason());
        self::assertNull($document->getStoragePath());
    }

    /** A failed attempt must not block a later successful one. */
    public function testIssuingAgainAfterAFailureRetries(): void
    {
        $this->renderer()->shouldFail = true;
        $this->issuer()->issue($this->payment());

        $this->renderer()->shouldFail = false;
        $retried = $this->issuer()->issue($this->payment());

        self::assertSame(IssuedDocument::STATUS_READY, $retried->getStatus());
    }

    // ── HTTP surface ──────────────────────────────────────────────────────

    public function testIssuingOverHttpIsIdempotent(): void
    {
        $first = $this->json($this->send('POST', sprintf('/api/documents/payments/%d', $this->paymentId)));
        $second = $this->json($this->send('POST', sprintf('/api/documents/payments/%d', $this->paymentId)));

        self::assertSame($first['id'], $second['id']);
        self::assertSame('ready', $first['status']);
        self::assertSame(1, $this->renderer()->calls);
    }

    public function testDownloadReturnsTheIssuedBytes(): void
    {
        $issued = $this->json($this->send('POST', sprintf('/api/documents/payments/%d', $this->paymentId)));

        $response = $this->send('GET', sprintf('/api/documents/%s/file', self::stringValue($issued, 'id')));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        $checksum = self::stringValue($issued, 'checksum');
        self::assertSame($checksum, $response->headers->get('X-Document-Checksum'));
        self::assertSame(hash('sha256', (string) $response->getContent()), $checksum);
    }

    public function testHistoryListsEveryCopyNewestFirst(): void
    {
        $this->send('POST', sprintf('/api/documents/payments/%d', $this->paymentId));
        $this->send('POST', sprintf('/api/documents/payments/%d?reissue=1', $this->paymentId));

        $payload = $this->json($this->send('GET', sprintf('/api/documents/payments/%d', $this->paymentId)));

        $documents = self::rowList($payload, 'documents');
        self::assertCount(2, $documents);
        self::assertNull($documents[0]['supersededBy']);
        self::assertNotNull($documents[1]['supersededBy']);
    }

    /**
     * The `{resource}` segment names a published printable resource, never a
     * class. Accepting a class name would let a caller have any class in the
     * application loaded by URL.
     */
    public function testAnUnknownResourceSegmentIsRefused(): void
    {
        $response = $this->send('POST', '/api/documents/App%5CEntity%5CUser/1');

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
    }

    public function testDownloadingAnUnknownDocumentIsNotFound(): void
    {
        $response = $this->send('GET', '/api/documents/00000000-0000-0000-0000-000000000000/file');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /** The capability is published so the frontend renders the action from the contract. */
    public function testTheDocumentationAdvertisesThePrintableResource(): void
    {
        $payload = $this->json($this->send('GET', '/api/docs.jsonld'));

        $classes = $payload['hydra:supportedClass'] ?? $payload['supportedClass'] ?? [];
        self::assertIsArray($classes);

        $printable = null;
        foreach ($classes as $class) {
            if (is_array($class) && ($class['hydra:title'] ?? $class['title'] ?? null) === 'Payment') {
                $printable = $class['x-printable'] ?? null;
            }
        }

        self::assertIsArray($printable, 'Payment is printable but the documentation does not say so.');
        self::assertSame('payment.print', $printable['title']);
        self::assertSame('/api/documents/payments/{id}', $printable['issueUrl']);
        self::assertTrue($printable['allowReissue']);
    }

    private function issuer(): DocumentIssuer
    {
        $issuer = $this->container()->get(DocumentIssuer::class);
        self::assertInstanceOf(DocumentIssuer::class, $issuer);

        return $issuer;
    }

    private function renderer(): RecordingRenderer
    {
        $renderer = $this->container()->get(RecordingRenderer::class);
        self::assertInstanceOf(RecordingRenderer::class, $renderer);

        return $renderer;
    }

    private function payment(): Payment
    {
        $payment = $this->entityManager()->find(Payment::class, $this->paymentId);
        self::assertInstanceOf(Payment::class, $payment);

        return $payment;
    }

    private function seedPayment(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-1';
        $payment->setAmount(Money::of('42.50', 'EUR'));
        $entityManager->persist($payment);
        $entityManager->flush();

        $this->paymentId = (int) $payment->getId();
        $entityManager->clear();
    }

    /** Named `send` so it does not collide with the base class's query helper. */
    private function send(string $method, string $path): Response
    {
        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

        $request = Request::create($path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
