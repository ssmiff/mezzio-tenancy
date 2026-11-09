<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use LogicException;
use Override;

use function get_debug_type;
use function sprintf;

/**
 * A PostgreSQL schema per tenant, in the database of the provisioning connection. Tenants connect with
 * tenancy.connection_template (pointing at that same database) and a search_path of their schema, so their
 * tables, and their migrations version table, live in it.
 */
final readonly class SchemaProvisioner implements TenantProvisioner
{
    public function __construct(private Connection $database, private string $prefix = 'tenant_') {}

    #[Override]
    public function params(string $slug): array
    {
        return ['search_path' => [TenantName::for($this->prefix, $slug)]];
    }

    #[Override]
    public function create(string $slug): void
    {
        $this->database->executeStatement('CREATE SCHEMA ' . $this->quoted($slug));
    }

    #[Override]
    public function drop(string $slug): void
    {
        $this->database->executeStatement('DROP SCHEMA ' . $this->quoted($slug) . ' CASCADE');
    }

    private function quoted(string $slug): string
    {
        $platform = $this->database->getDatabasePlatform();

        if (! $platform instanceof PostgreSQLPlatform) {
            throw new LogicException(sprintf(
                'SchemaProvisioner supports PostgreSQL only, not %s.',
                get_debug_type($platform),
            ));
        }

        return $this->database->quoteSingleIdentifier(TenantName::for($this->prefix, $slug));
    }
}
