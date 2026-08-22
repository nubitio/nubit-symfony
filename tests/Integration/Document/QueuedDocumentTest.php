<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Document;

use Nubit\AdminBundle\Document\DocumentIssuer;
use Nubit\AdminBundle\Document\Entity\IssuedDocument;
use Nubit\AdminBundle\Document\Message\RenderDocument;
use Nubit\AdminBundle\Document\Message\RenderDocumentHandler;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Platform\Money\Money;
use Nubit\Tests\Integration\Fixture\Document\RecordingRenderer;
use Nubit\Tests\Integration\Fixture\Entity\Payment;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Rendering off the request thread.
 *
 * Documents are the slowest thing an ERP produces — a hundred-page statement is
 * seconds of layout work — so issuing has to be able to return before the file
 * exists. What that costs is a state the client can observe, and this suite
 * pins it: a pending document, a 202 while it is pending, and bytes once the
 * worker has run.
 */
#[CoversNothing]
final class QueuedDocumentTest extends IntegrationTestCase
{
    private int $paymentId = 0;

    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'framework' => [
                    'messenger' => [
                        'transports' => ['documents' => 'in-memory://'],
                        'routing' => [RenderDocument::class => 'documents'],
                    ],
                ],
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                    'documents' => [
                        'enabled' => true,
                        'async' => true,
                        'storage' => ['local_directory' => sys_get_temp_dir() . '/nubit-documents-async-test'],
                    ],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
        $this->seedPayment();
    }

    public function testIssuingReturnsBeforeTheDocumentExists(): void
    {
        $document = $this->issuer()->issue($this->payment());

        self::assertSame(IssuedDocument::STATUS_PENDING, $document->getStatus());
        self::assertNull($document->getStoragePath());
        self::assertSame(0, $this->renderer()->calls, 'The request thread rendered the document itself.');
        self::assertCount(1, $this->queued());
    }

    /**
     * A pending document answers 202 rather than rendering on the spot. Doing
     * the work here would produce a second copy simply because someone clicked
     * before the worker got to it.
     */
    public function testDownloadingAPendingDocumentIsAccepted(): void
    {
        $issued = $this->json($this->send('POST', sprintf('/api/documents/payments/%d', $this->paymentId)));

        $response = $this->send('GET', sprintf('/api/documents/%s/file', self::stringValue($issued, 'id')));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame(0, $this->renderer()->calls);
    }

    public function testTheWorkerCompletesTheDocument(): void
    {
        $document = $this->issuer()->issue($this->payment());
        $documentId = (string) $document->getId();

        $this->runWorker();

        $this->entityManager()->clear();
        $completed = $this->entityManager()->find(IssuedDocument::class, $documentId);
        self::assertInstanceOf(IssuedDocument::class, $completed);

        self::assertSame(IssuedDocument::STATUS_READY, $completed->getStatus());
        self::assertNotNull($completed->getChecksum());
        self::assertSame(1, $this->renderer()->calls);
    }

    /**
     * A redelivered message must not replace bytes somebody already has. At-least-once
     * delivery is the norm, so this is an ordinary Tuesday rather than an edge case.
     */
    public function testARedeliveredMessageDoesNotReRender(): void
    {
        $document = $this->issuer()->issue($this->payment());
        $documentId = (string) $document->getId();

        $this->runWorker();
        $firstChecksum = $this->reload($documentId)->getChecksum();

        // The same envelope arriving twice.
        $this->handler()(new RenderDocument($documentId));

        self::assertSame(1, $this->renderer()->calls, 'The document was rendered again on redelivery.');
        self::assertSame($firstChecksum, $this->reload($documentId)->getChecksum());
    }

    /** A message for a row that no longer exists is dropped, not retried forever. */
    public function testAMessageForAMissingDocumentIsDiscarded(): void
    {
        $this->handler()(new RenderDocument('00000000-0000-0000-0000-000000000000'));

        self::assertSame(0, $this->renderer()->calls);
    }

    private function runWorker(): void
    {
        foreach ($this->queued() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RenderDocument::class, $message);
            $this->handler()($message);
        }
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function queued(): array
    {
        $transport = $this->container()->get('messenger.transport.documents');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values($transport->getSent());
    }

    private function handler(): RenderDocumentHandler
    {
        $handler = $this->container()->get(RenderDocumentHandler::class);
        self::assertInstanceOf(RenderDocumentHandler::class, $handler);

        return $handler;
    }

    private function reload(string $documentId): IssuedDocument
    {
        $this->entityManager()->clear();
        $document = $this->entityManager()->find(IssuedDocument::class, $documentId);
        self::assertInstanceOf(IssuedDocument::class, $document);

        return $document;
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
        $payment->reference = 'P-ASYNC';
        $payment->setAmount(Money::of('10.00', 'EUR'));
        $entityManager->persist($payment);
        $entityManager->flush();

        $this->paymentId = (int) $payment->getId();
        $entityManager->clear();
    }

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
