<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Override;

use function get_debug_type;
use function sprintf;

/**
 * A database per tenant, on the server of the provisioning connection (MySQL, MariaDB or PostgreSQL). That
 * connection's user needs the right to create and drop databases.
 */
final readonly class DatabaseProvisioner implements TenantProvisioner
{
    public function __construct(private Connection $server, private string $prefix = 'tenant_') {}

    #[Override]
    public function params(string $slug): array
    {
        return ['dbname' => TenantName::for($this->prefix, $slug)];
    }

    #[Override]
    public function create(string $slug): void
    {
        $this->server->executeStatement('CREATE DATABASE ' . $this->quoted($slug));
    }

    #[Override]
    public function drop(string $slug): void
    {
        $this->server->executeStatement('DROP DATABASE ' . $this->quoted($slug));
    }

    private function quoted(string $slug): string
    {
        $platform = $this->server->getDatabasePlatform();

        if (! $platform instanceof AbstractMySQLPlatform && ! $platform instanceof PostgreSQLPlatform) {
            throw new LogicException(sprintf(
                'DatabaseProvisioner supports MySQL, MariaDB and PostgreSQL, not %s.%s',
                get_debug_type($platform),
                $platform instanceof SQLitePlatform ? ' For SQLite, use SqliteFileProvisioner.' : '',
            ));
        }

        return $this->server->quoteSingleIdentifier(TenantName::for($this->prefix, $slug));
    }
}
