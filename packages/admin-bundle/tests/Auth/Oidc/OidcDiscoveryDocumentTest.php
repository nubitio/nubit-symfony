<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Nubit\AdminBundle\Auth\Oidc\OidcDiscoveryDocument;
use PHPUnit\Framework\TestCase;

final class OidcDiscoveryDocumentTest extends TestCase
{
    public function testParsesTheRequiredFields(): void
    {
        $document = OidcDiscoveryDocument::fromArray([
            'issuer' => 'https://idp.example.com',
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'jwks_uri' => 'https://idp.example.com/jwks',
            'some_other_field' => 'ignored',
        ]);

        static::assertSame('https://idp.example.com', $document->issuer);
        static::assertSame('https://idp.example.com/authorize', $document->authorizationEndpoint);
        static::assertSame('https://idp.example.com/token', $document->tokenEndpoint);
        static::assertSame('https://idp.example.com/jwks', $document->jwksUri);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function missingFieldCases(): iterable
    {
        yield 'missing issuer' => [['authorization_endpoint' => 'a', 'token_endpoint' => 'b', 'jwks_uri' => 'c']];
        yield 'missing authorization_endpoint' => [['issuer' => 'a', 'token_endpoint' => 'b', 'jwks_uri' => 'c']];
        yield 'missing token_endpoint' => [['issuer' => 'a', 'authorization_endpoint' => 'b', 'jwks_uri' => 'c']];
        yield 'missing jwks_uri' => [['issuer' => 'a', 'authorization_endpoint' => 'b', 'token_endpoint' => 'c']];
        yield 'empty issuer' => [[
            'issuer' => '',
            'authorization_endpoint' => 'b',
            'token_endpoint' => 'c',
            'jwks_uri' => 'd',
        ]];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingFieldCases')]
    public function testThrowsWhenARequiredFieldIsMissingOrInvalid(array $document): void
    {
        $this->expectException(\RuntimeException::class);

        OidcDiscoveryDocument::fromArray($document);
    }
}
