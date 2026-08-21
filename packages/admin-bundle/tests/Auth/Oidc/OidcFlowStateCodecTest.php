<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Nubit\AdminBundle\Auth\Oidc\OidcFlowState;
use Nubit\AdminBundle\Auth\Oidc\OidcFlowStateCodec;
use PHPUnit\Framework\TestCase;

final class OidcFlowStateCodecTest extends TestCase
{
    public function testRoundTripsAFreshState(): void
    {
        $codec = new OidcFlowStateCodec('a-signing-secret-at-least-32-bytes-long');
        $state = new OidcFlowState('okta', 'state-123', 'nonce-456', 'verifier-789', time());

        $decoded = $codec->decode($codec->encode($state));

        static::assertNotNull($decoded);
        static::assertSame('okta', $decoded->provider);
        static::assertSame('state-123', $decoded->state);
        static::assertSame('nonce-456', $decoded->nonce);
        static::assertSame('verifier-789', $decoded->codeVerifier);
    }

    public function testRejectsATamperedPayload(): void
    {
        $codec = new OidcFlowStateCodec('a-signing-secret-at-least-32-bytes-long');
        $token = $codec->encode(new OidcFlowState('okta', 'state-123', 'nonce-456', 'verifier-789', time()));

        [$payload, $signature] = explode('.', $token, 2);
        $tamperedPayload = base64_encode(str_replace('okta', 'evil!', (string) base64_decode($payload, true)));

        static::assertNull($codec->decode($tamperedPayload . '.' . $signature));
    }

    public function testRejectsATokenSignedWithADifferentSecret(): void
    {
        $token = (new OidcFlowStateCodec('secret-one-at-least-32-bytes-long'))
            ->encode(new OidcFlowState('okta', 'state-123', 'nonce-456', 'verifier-789', time()));

        static::assertNull((new OidcFlowStateCodec('secret-two-at-least-32-bytes-long'))->decode($token));
    }

    public function testRejectsAnExpiredState(): void
    {
        $codec = new OidcFlowStateCodec('a-signing-secret-at-least-32-bytes-long');
        $expired = new OidcFlowState('okta', 'state-123', 'nonce-456', 'verifier-789', time() - 601);

        static::assertNull($codec->decode($codec->encode($expired)));
    }

    public function testRejectsMalformedInput(): void
    {
        $codec = new OidcFlowStateCodec('a-signing-secret-at-least-32-bytes-long');

        static::assertNull($codec->decode('not-a-valid-token'));
        static::assertNull($codec->decode(''));
    }
}
