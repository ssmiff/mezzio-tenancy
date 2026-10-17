<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\Http\TenantMiddleware;
use Ssmiff\MezzioTenancy\Test\Fixture\CallbackHandler;
use Ssmiff\MezzioTenancy\TenantContext;

use function json_decode;

final class MiddlewareTest extends TestCase
{
    public function testActivatesTheTenantForTheRequestAndDeactivatesAfterwards(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');
        $context = Services::get($container, TenantContext::class);
        $registry = Services::get($container, Registry::class);
        $seen = null;
        $current = null;
        $file = null;

        $handler = new CallbackHandler(function (ServerRequestInterface $request) use (
            &$seen,
            &$current,
            &$file,
            $context,
            $registry,
        ): void {
            $seen = $request;
            $current = $context->current();
            $file = $this->databaseFile($registry->connection(Connections::TENANT));
        });

        $middleware = Services::get($container, TenantMiddleware::class);
        $response = $middleware->process(new ServerRequest(headers: ['X-Tenant' => 'acme']), $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $seen);
        self::assertInstanceOf(Tenant::class, $current);
        self::assertSame($current, $seen->getAttribute(Tenant::class));
        self::assertSame('acme.sqlite', $file);
        self::assertNull($context->current());
    }

    public function testTenantsDoNotShareDatabases(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');
        $this->addTenant($container, 'globex');
        $registry = Services::get($container, Registry::class);
        $middleware = Services::get($container, TenantMiddleware::class);
        $hasNotes = false;

        $handler = new CallbackHandler(static function (ServerRequestInterface $request) use (
            &$hasNotes,
            $registry,
        ): void {
            $connection = $registry->connection(Connections::TENANT);

            if ($request->getMethod() === 'POST') {
                $connection->executeStatement('CREATE TABLE notes (id INTEGER)');
            }

            $hasNotes = $connection->createSchemaManager()->tablesExist(['notes']);
        });

        $middleware->process(new ServerRequest(method: 'POST', headers: ['X-Tenant' => 'acme']), $handler);
        $middleware->process(new ServerRequest(headers: ['X-Tenant' => 'globex']), $handler);
        self::assertFalse($hasNotes);

        $middleware->process(new ServerRequest(headers: ['X-Tenant' => 'acme']), $handler);
        self::assertTrue($hasNotes);
    }

    public function testUnknownInactiveAndMissingTenantsGetA404(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'dormant', false);
        $middleware = Services::get($container, TenantMiddleware::class);
        $handler = new CallbackHandler(static function (): void {});

        foreach (['nope', 'dormant'] as $slug) {
            $response = $middleware->process(new ServerRequest(headers: ['X-Tenant' => $slug]), $handler);

            self::assertSame(404, $response->getStatusCode());
            self::assertSame(['error' => 'Unknown tenant'], json_decode((string) $response->getBody(), true));
        }

        self::assertSame(404, $middleware->process(new ServerRequest(), $handler)->getStatusCode());
    }

    public function testRequestsWithoutATenantPassThroughWhenNotRequired(): void
    {
        $container = $this->container(['tenancy' => ['required' => false]]);

        $middleware = Services::get($container, TenantMiddleware::class);
        $response = $middleware->process(new ServerRequest(), new CallbackHandler(static function (): void {}, 202));

        self::assertSame(202, $response->getStatusCode());
    }

    public function testSubdomainIsUsedWhenThereIsNoHeader(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');
        $seen = null;

        $handler = new CallbackHandler(static function (ServerRequestInterface $request) use (&$seen): void {
            $seen = $request;
        });

        $middleware = Services::get($container, TenantMiddleware::class);
        $middleware->process(new ServerRequest(uri: 'https://acme.example.com/'), $handler);

        self::assertInstanceOf(ServerRequestInterface::class, $seen);
        self::assertInstanceOf(Tenant::class, $seen->getAttribute(Tenant::class));
    }
}
