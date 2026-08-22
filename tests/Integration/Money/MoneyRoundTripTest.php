<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Money;

use Doctrine\DBAL\Connection;
use Nubit\AdminBundle\NubitAdminBundle;
use Nubit\Platform\Money\Money;
use Nubit\Tests\Integration\Fixture\Entity\Payment;
use Nubit\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money from the database to the JSON response and back.
 *
 * The value object has its own unit tests; what those cannot show is whether
 * the amount survives Doctrine, the serializer and the HTTP layer unchanged.
 * Every one of those boundaries is a place where a decimal has historically
 * turned into a float.
 */
#[CoversNothing]
final class MoneyRoundTripTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->boot(
            [NubitAdminBundle::class],
            [
                'nubit_admin' => [
                    'app_profile' => 'internal',
                    'auth' => ['secret' => '%env(APP_SECRET)%'],
                ],
            ],
            self::fixtureMapping(),
        );

        $this->resetSchema();
    }

    public function testAnAmountSurvivesTheDatabaseUnchanged(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-1';
        $payment->setAmount(Money::of('1234567.89', 'EUR'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $id = $payment->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Payment::class, $id);
        self::assertInstanceOf(Payment::class, $reloaded);

        $amount = $reloaded->getAmount();
        self::assertInstanceOf(Money::class, $amount);
        self::assertSame('1234567.89', $amount->toDecimalString());
        self::assertSame(123456789, $amount->minorAmount);
    }

    /** Minor units in a bigint column are what make SQL aggregates exact. */
    public function testAmountsAreStoredAsIntegerMinorUnits(): void
    {
        $entityManager = $this->entityManager();

        foreach ([['P-1', '0.10'], ['P-2', '0.20']] as [$reference, $amount]) {
            $payment = new Payment();
            $payment->reference = $reference;
            $payment->setAmount(Money::of($amount, 'EUR'));
            $entityManager->persist($payment);
        }
        $entityManager->flush();
        $entityManager->clear();

        $connection = $entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        $total = $connection->executeQuery('SELECT SUM(amount_minor_amount) FROM fixture_payment')->fetchOne();

        // 0.1 + 0.2 in SQL floating point is 0.30000000000000004; in minor units
        // it is 30, and 30 has no other reading.
        self::assertSame(30, (int) $total);
    }

    /** A currency with no minor unit must not gain two decimals on the way through. */
    public function testAZeroDecimalCurrencyRoundTrips(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-JPY';
        $payment->setAmount(Money::of('1999', 'JPY'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $id = $payment->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Payment::class, $id);
        self::assertInstanceOf(Payment::class, $reloaded);
        $amount = $reloaded->getAmount();
        self::assertInstanceOf(Money::class, $amount);

        self::assertSame('1999', $amount->toDecimalString());
        self::assertSame('JPY', $amount->currency->code);
        self::assertSame(0, $amount->currency->scale);
    }

    public function testAnAbsentAmountStaysNull(): void
    {
        $entityManager = $this->entityManager();

        $payment = new Payment();
        $payment->reference = 'P-EMPTY';
        $entityManager->persist($payment);
        $entityManager->flush();
        $id = $payment->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Payment::class, $id);
        self::assertInstanceOf(Payment::class, $reloaded);
        self::assertNull($reloaded->getAmount());
    }

    /**
     * The amount reaches the client as a string. A JSON number would be parsed
     * into a double by every browser, which is the drift the money layer exists
     * to prevent — one line of serializer configuration away from returning.
     */
    public function testTheApiPublishesTheAmountAsAString(): void
    {
        $entityManager = $this->entityManager();
        $payment = new Payment();
        $payment->reference = 'P-1';
        $payment->setAmount(Money::of('1234567.89', 'EUR'));
        $entityManager->persist($payment);
        $entityManager->flush();
        $entityManager->clear();

        $payload = $this->json($this->apiRequest('GET', '/api/payments'));

        $members = $payload['hydra:member'] ?? $payload['member'] ?? null;
        self::assertIsArray($members);
        self::assertCount(1, $members);

        $amount = $members[0]['amount'] ?? null;
        self::assertIsArray($amount);
        self::assertSame('1234567.89', $amount['amount']);
        self::assertIsString($amount['amount']);
        self::assertSame('EUR', $amount['currency']);
        self::assertSame(2, $amount['scale']);
        self::assertSame(123456789, $amount['minorAmount']);
    }

    public function testAnAmountCanBeWrittenThroughTheApi(): void
    {
        $response = $this->apiRequest('POST', '/api/payments', [
            'reference' => 'P-NEW',
            'amount' => ['amount' => '42.50', 'currency' => 'EUR'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $payload = $this->json($response);
        self::assertIsArray($payload['amount']);
        self::assertSame('42.50', $payload['amount']['amount']);
    }

    /**
     * More decimals than the currency has is a question, not a value. Answering
     * it by truncating would make the API silently disagree with the client
     * about what was paid.
     */
    public function testAnOverPreciseAmountIsRefusedRatherThanTruncated(): void
    {
        $response = $this->apiRequest('POST', '/api/payments', [
            'reference' => 'P-BAD',
            'amount' => ['amount' => '42.505', 'currency' => 'EUR'],
        ]);

        self::assertSame(
            Response::HTTP_BAD_REQUEST,
            $response->getStatusCode(),
            'An amount the currency cannot express must be refused: ' . (string) $response->getContent(),
        );
    }

    public function testAnUnknownCurrencyCodeIsRefused(): void
    {
        $response = $this->apiRequest('POST', '/api/payments', [
            'reference' => 'P-BAD',
            'amount' => ['amount' => '1.00', 'currency' => 'EUROS'],
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /** The documented format is how the frontend and code-generating agents learn the shape. */
    public function testTheDocumentationMarksThePropertyAsMoney(): void
    {
        $payload = $this->json($this->apiRequest('GET', '/api/docs.jsonld'));

        $classes = $payload['hydra:supportedClass'] ?? $payload['supportedClass'] ?? null;
        self::assertIsArray($classes);

        $property = $this->findSupportedProperty($classes, 'Payment', 'amount');
        self::assertNotNull($property, 'The Payment.amount property is missing from the documentation.');
        self::assertSame('money', $property['x-crud']['format'] ?? null);
    }

    /**
     * @param array<mixed> $classes
     *
     * @return array<string, mixed>|null
     */
    private function findSupportedProperty(array $classes, string $className, string $propertyName): ?array
    {
        foreach ($classes as $class) {
            if (!is_array($class)) {
                continue;
            }

            $title = $class['hydra:title'] ?? $class['title'] ?? null;
            if ($title !== $className) {
                continue;
            }

            $properties = $class['hydra:supportedProperty'] ?? $class['supportedProperty'] ?? [];
            if (!is_array($properties)) {
                continue;
            }

            foreach ($properties as $property) {
                if (!is_array($property)) {
                    continue;
                }

                $title = $property['hydra:title'] ?? $property['title'] ?? null;
                if ($title === $propertyName) {
                    /** @var array<string, mixed> $property */
                    return $property;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function apiRequest(string $method, string $path, array $body = []): Response
    {
        $this->entityManager()->clear();

        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            [] === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        if (null === $this->kernel) {
            self::fail('Boot the kernel before issuing requests.');
        }

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
