<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\ResponseModeResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ResponseModeResolverTest extends TestCase
{
    private ResponseModeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ResponseModeResolver();
    }

    public function testDefaultsToCookieMode(): void
    {
        $request = Request::create('/api/auth/login', 'POST', content: '{"username":"a","password":"b"}');

        self::assertFalse($this->resolver->wantsJsonTokens($request));
    }

    public function testExplicitJsonResponseMode(): void
    {
        $request = Request::create('/api/auth/login', 'POST', content: '{"response_mode":"json"}');

        self::assertTrue($this->resolver->wantsJsonTokens($request));
    }

    public function testExplicitCookieResponseModeWinsOverMobileHeader(): void
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            server: ['HTTP_X_CLIENT_TYPE' => 'android'],
            content: '{"response_mode":"cookie"}',
        );

        self::assertFalse($this->resolver->wantsJsonTokens($request));
    }

    public function testAndroidHeaderImpliesJson(): void
    {
        $request = Request::create(
            '/api/auth/refresh',
            'POST',
            server: ['HTTP_X_CLIENT_TYPE' => 'Android'],
            content: '{}',
        );

        self::assertTrue($this->resolver->wantsJsonTokens($request));
    }

    public function testUnknownClientTypeDefaultsToCookie(): void
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            server: ['HTTP_X_CLIENT_TYPE' => 'smart-fridge'],
            content: '{}',
        );

        self::assertFalse($this->resolver->wantsJsonTokens($request));
    }
}
