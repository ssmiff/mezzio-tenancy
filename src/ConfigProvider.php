<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Ssmiff\MezzioContainerHelpers\Factory\AttributeFactory;
use Ssmiff\MezzioDoctrine\ConfigProvider as DoctrineConfigProvider;
use Ssmiff\MezzioDoctrine\Console\ConnectionsCommand;
use Ssmiff\MezzioDoctrine\ServiceId;
use Ssmiff\MezzioTenancy\Console\AddTenantOption;
use Ssmiff\MezzioTenancy\Console\TenancyMigrateCommand;
use Ssmiff\MezzioTenancy\Console\TenantCreateCommand;
use Ssmiff\MezzioTenancy\Console\TenantDeleteCommand;
use Ssmiff\MezzioTenancy\Console\TenantListCommand;
use Ssmiff\MezzioTenancy\Console\TenantOptionListener;
use Ssmiff\MezzioTenancy\Console\TenantRunAsCommand;
use Ssmiff\MezzioTenancy\Doctrine\LandlordMigrations;
use Ssmiff\MezzioTenancy\Doctrine\TenantConnectionParams;
use Ssmiff\MezzioTenancy\Factory\ChainResolverFactory;
use Ssmiff\MezzioTenancy\Factory\ProvisionerFactory;
use Ssmiff\MezzioTenancy\Factory\ProvisioningFactory;
use Ssmiff\MezzioTenancy\Http\TenantMiddleware;
use Ssmiff\MezzioTenancy\Provisioning\DatabaseProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\Provisioning;
use Ssmiff\MezzioTenancy\Provisioning\SchemaProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\SqliteFileProvisioner;
use Ssmiff\MezzioTenancy\Resolver\ChainResolver;
use Ssmiff\MezzioTenancy\Resolver\HeaderResolver;
use Ssmiff\MezzioTenancy\Resolver\SubdomainResolver;
use Ssmiff\MezzioTenancy\Resolver\TenantResolver;

use function array_diff;
use function array_unique;
use function array_values;

final readonly class ConfigProvider
{
    /** The landlord migrations, supplied to the landlord connection by LandlordMigrations. */
    public const array MIGRATIONS_PATHS = ['Ssmiff\\MezzioTenancy\\Migrations' => __DIR__ . '/../migrations'];

    private const array COMMANDS = [
        'tenancy:create' => TenantCreateCommand::class,
        'tenancy:delete' => TenantDeleteCommand::class,
        'tenancy:list' => TenantListCommand::class,
        'tenancy:migrate' => TenancyMigrateCommand::class,
        'tenancy:run-as' => TenantRunAsCommand::class,
    ];

    /** @return array<string, class-string> */
    public function commands(): array
    {
        return self::COMMANDS;
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->dependencies(),
            'laminas-cli' => ['commands' => self::COMMANDS],
            'tenancy' => [
                // The doctrine.connections entry holding the tenants table.
                'landlord_connection' => Connections::LANDLORD,
                // Creates and drops tenant storage for tenancy:create and tenancy:delete: a service id such as
                // DatabaseProvisioner::class, SchemaProvisioner::class or SqliteFileProvisioner::class; null for none.
                'provisioner' => null,
                // Put in front of the slug to name a tenant's database, schema or file.
                'prefix' => 'tenant_',
                // The connection the database and schema provisioners run CREATE/DROP on; null uses the landlord's.
                'provisioning_connection' => null,
                // Where SqliteFileProvisioner keeps the files, relative to doctrine.base_path.
                'sqlite_directory' => 'data/tenants',
                'resolvers' => [HeaderResolver::class, SubdomainResolver::class],
                'header' => 'X-Tenant',
                'base_domain' => null,
                'connection_template' => [],
                'required' => true,
            ],
            'doctrine' => [
                'connections' => [
                    Connections::TENANT => ['params_provider' => TenantConnectionParams::class],
                ],
                // Supplies the landlord migrations to the connection named by tenancy.landlord_connection.
                'migrations' => ['paths_providers' => [LandlordMigrations::class]],
                'console_listeners' => [TenantOptionListener::class => 100],
            ],
        ];
    }

    /**
     * @return array{
     *     aliases: array<string, string>,
     *     factories: array<string, class-string<FactoryInterface>>,
     *     delegators: array<string, list<class-string<DelegatorFactoryInterface>>>,
     *     shared: array<string, bool>,
     * }
     */
    public function dependencies(): array
    {
        $factories = [
            DbalTenantRepository::class => AttributeFactory::class,
            TenantContext::class => InvokableFactory::class,
            TenantSwitcher::class => AttributeFactory::class,
            HeaderResolver::class => AttributeFactory::class,
            SubdomainResolver::class => AttributeFactory::class,
            ChainResolver::class => ChainResolverFactory::class,
            TenantMiddleware::class => AttributeFactory::class,
            TenantConnectionParams::class => AttributeFactory::class,
            LandlordMigrations::class => AttributeFactory::class,
            Provisioning::class => ProvisioningFactory::class,
            DatabaseProvisioner::class => ProvisionerFactory::class,
            SchemaProvisioner::class => ProvisionerFactory::class,
            SqliteFileProvisioner::class => ProvisionerFactory::class,
            TenantOptionListener::class => AttributeFactory::class,
        ];

        foreach (self::COMMANDS as $class) {
            $factories[$class] = AttributeFactory::class;
        }

        $delegators = [];
        $doctrineCommands = new DoctrineConfigProvider()->commands();
        $commands = array_values(array_unique(array_diff($doctrineCommands, [ConnectionsCommand::class])));
        foreach ($commands as $command) {
            $delegators[$command] = [AddTenantOption::class];
        }

        return [
            'aliases' => [
                TenantRepository::class => DbalTenantRepository::class,
                TenantResolver::class => ChainResolver::class,
            ],
            'factories' => $factories,
            'delegators' => $delegators,
            'shared' => [
                ServiceId::entityManager(Connections::TENANT) => false,
                ServiceId::connection(Connections::TENANT) => false,
            ],
        ];
    }
}
