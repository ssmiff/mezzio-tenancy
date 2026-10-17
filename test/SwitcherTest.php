<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\Doctrine\TenantConnectionParams;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\Exception\NoTenant;
use Ssmiff\MezzioTenancy\TenantContext;
use Ssmiff\MezzioTenancy\TenantRepository;
use Ssmiff\MezzioTenancy\TenantSwitcher;

final class SwitcherTest extends TestCase
{
    public function testRunSwitchesAndRestoresThePreviousTenant(): void
    {
        $container = $this->container();
        $acme = $this->addTenant($container, 'acme');
        $globex = $this->addTenant($container, 'globex');
        $switcher = Services::get($container, TenantSwitcher::class);
        $context = Services::get($container, TenantContext::class);
        $registry = Services::get($container, Registry::class);

        $switcher->activate($acme);
        $inner = $switcher->run($globex, fn(Tenant $tenant): array => [
            $tenant->slug,
            $context->current()?->slug,
            $this->databaseFile($registry->connection(Connections::TENANT)),
        ]);

        self::assertSame(['globex', 'globex', 'globex.sqlite'], $inner);
        self::assertSame('acme', $context->current()?->slug);
        self::assertSame('acme.sqlite', $this->databaseFile($registry->connection(Connections::TENANT)));
    }

    public function testRunWithoutAnActiveTenantLeavesNoneActive(): void
    {
        $container = $this->container();
        $acme = $this->addTenant($container, 'acme');
        $switcher = Services::get($container, TenantSwitcher::class);

        $switcher->run($acme, static fn(): null => null);

        self::assertNull(Services::get($container, TenantContext::class)->current());
    }

    public function testTenantConnectionNeedsAnActiveTenant(): void
    {
        $container = $this->container();

        $this->expectException(NoTenant::class);

        Services::get($container, TenantConnectionParams::class)->params();
    }

    public function testConnectionTemplateIsMergedUnderTheTenantParams(): void
    {
        $container = $this->container();
        Services::get($container, TenantSwitcher::class)->activate($this->addTenant($container, 'acme'));

        self::assertSame(
            ['driver' => 'pdo_sqlite', 'path' => $this->base . '/tenants/acme.sqlite'],
            Services::get($container, TenantConnectionParams::class)->params(),
        );
    }

    public function testInactiveFlagIsRoundTrippedThroughTheRepository(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'dormant', false);

        self::assertFalse(Services::get($container, TenantRepository::class)->findBySlug('dormant')?->active);
    }

    public function testTenantSlugsAreValidated(): void
    {
        $this->expectExceptionMessage('not a valid tenant slug');

        new Tenant('Bad Slug', 'Bad', []);
    }
}
