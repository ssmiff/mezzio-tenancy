<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Ssmiff\MezzioTenancy\Resolver\ChainResolver;
use Ssmiff\MezzioTenancy\Resolver\HeaderResolver;
use Ssmiff\MezzioTenancy\Resolver\SubdomainResolver;

final class ResolverTest extends TestCase
{
    public function testHeaderResolverReadsTheConfiguredHeader(): void
    {
        $resolver = new HeaderResolver('X-Tenant');

        self::assertSame('acme', $resolver->identify(new ServerRequest(headers: ['X-Tenant' => 'acme'])));
        self::assertNull($resolver->identify(new ServerRequest()));
    }

    public function testSubdomainResolverTakesASingleLabelBelowTheBaseDomain(): void
    {
        $resolver = new SubdomainResolver('example.com');

        self::assertSame('acme', $resolver->identify(new ServerRequest(uri: 'https://acme.example.com/x')));
        self::assertSame('acme', $resolver->identify(new ServerRequest(uri: 'https://ACME.Example.com/x')));
        self::assertNull($resolver->identify(new ServerRequest(uri: 'https://example.com/')));
        self::assertNull($resolver->identify(new ServerRequest(uri: 'https://a.b.example.com/')));
        self::assertNull($resolver->identify(new ServerRequest(uri: 'https://acme.other.org/')));
    }

    public function testSubdomainResolverIsDisabledWithoutABaseDomain(): void
    {
        self::assertNull(new SubdomainResolver()->identify(new ServerRequest(uri: 'https://acme.example.com/')));
    }

    public function testChainReturnsTheFirstMatch(): void
    {
        $chain = new ChainResolver([new HeaderResolver('X-Tenant'), new SubdomainResolver('example.com')]);

        $request = new ServerRequest(uri: 'https://sub.example.com/', headers: ['X-Tenant' => 'header']);
        self::assertSame('header', $chain->identify($request));
        self::assertSame('sub', $chain->identify(new ServerRequest(uri: 'https://sub.example.com/')));
        self::assertNull($chain->identify(new ServerRequest(uri: 'https://other.org/')));
    }
}
