<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Factory;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioContainerHelpers\Support\Config;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioDoctrine\Support\Paths;
use Ssmiff\MezzioDoctrine\Support\Settings;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\Provisioning\DatabaseProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\SchemaProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\SqliteFileProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\TenantProvisioner;
use UnexpectedValueException;

use function is_string;
use function sprintf;

/** Builds the shipped provisioners from the tenancy config. */
final readonly class ProvisionerFactory implements FactoryInterface
{
    #[Override]
    public function __invoke(
        ContainerInterface $container,
        string $requestedName,
        ?array $options = null,
    ): TenantProvisioner {
        $prefix = self::string($container, 'tenancy.prefix') ?? 'tenant_';

        return match ($requestedName) {
            DatabaseProvisioner::class => new DatabaseProvisioner(self::connection($container), $prefix),
            SchemaProvisioner::class => new SchemaProvisioner(self::connection($container), $prefix),
            SqliteFileProvisioner::class => new SqliteFileProvisioner(
                self::string($container, 'tenancy.sqlite_directory') ?? 'data/tenants',
                Paths::base(Settings::fromContainer($container)),
                $prefix,
            ),
            default => throw new UnexpectedValueException(sprintf('No provisioner "%s".', $requestedName)),
        };
    }

    /** tenancy.provisioning_connection, or the landlord connection. Nothing connects until it is used. */
    private static function connection(ContainerInterface $container): Connection
    {
        $name = self::string($container, 'tenancy.provisioning_connection')
            ?? self::string($container, 'tenancy.landlord_connection')
            ?? Connections::LANDLORD;

        return Services::get($container, Registry::class)->connection($name);
    }

    private static function string(ContainerInterface $container, string $path): ?string
    {
        return self::stringOrNull(Config::get($container, $path), $path);
    }

    private static function stringOrNull(mixed $value, string $path): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw new InvalidArgumentException(sprintf('Config "%s" must be a string.', $path)),
        };
    }
}
