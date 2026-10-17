<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Doctrine\DBAL\Connection;
use Laminas\ConfigAggregator\ArrayProvider;
use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\Diactoros\ResponseFactory;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Laminas\ServiceManager\ServiceManager;
use Override;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Ssmiff\MezzioContainerHelpers\ConfigProvider as HelpersConfigProvider;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\ConfigProvider as DoctrineConfigProvider;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\ConfigProvider;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function bin2hex;
use function is_array;
use function is_string;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

abstract class TestCase extends PhpUnitTestCase
{
    protected string $base;

    #[Override]
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mezzio-tenancy-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/tenants', 0775, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->base);
    }

    /** @param array<string, mixed> $override */
    protected function container(array $override = [], bool $landlordSchema = true): ServiceManager
    {
        $config = new ConfigAggregator([
            HelpersConfigProvider::class,
            DoctrineConfigProvider::class,
            ConfigProvider::class,
            new ArrayProvider([
                'dependencies' => ['factories' => [
                    ResponseFactoryInterface::class => static fn() => new ResponseFactory(),
                ]],
                'doctrine' => [
                    'default' => Connections::LANDLORD,
                    'base_path' => $this->base,
                    'connections' => [
                        Connections::LANDLORD => ['driver' => 'pdo_sqlite', 'path' => 'landlord.sqlite'],
                    ],
                ],
                'tenancy' => [
                    'base_domain' => 'example.com',
                    'connection_template' => ['driver' => 'pdo_sqlite'],
                ],
            ]),
            new ArrayProvider($override),
        ])->getMergedConfig();

        $container = new ServiceManager($config['dependencies']);
        $container->setService('config', $config);

        if ($landlordSchema) {
            $landlord = Services::get($container, Registry::class)->connection(Connections::LANDLORD);
            $landlord->executeStatement(
                'CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, '
                    . 'name VARCHAR(255) NOT NULL, params CLOB NOT NULL, active BOOLEAN NOT NULL)',
            );
            $landlord->executeStatement('CREATE UNIQUE INDEX UNIQ_TENANTS_SLUG ON tenants (slug)');
        }

        return $container;
    }

    protected function databaseFile(Connection $connection): string
    {
        $row = $connection->executeQuery('PRAGMA database_list')->fetchAssociative();

        return is_array($row) && is_string($row['file'] ?? null) ? basename($row['file']) : '';
    }

    protected function addTenant(ServiceManager $container, string $slug, bool $active = true): Tenant
    {
        $tenant = new Tenant($slug, ucfirst($slug), ['path' => $this->base . '/tenants/' . $slug . '.sqlite'], $active);
        Services::get($container, TenantRepository::class)->add($tenant);

        return $tenant;
    }
}
