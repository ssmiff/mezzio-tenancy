<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

/**
 * Creates and destroys the storage of a tenant: a database, a schema, a file, or anything else. Choose one with
 * tenancy.provisioner; tenancy:create and tenancy:delete use it.
 */
interface TenantProvisioner
{
    /**
     * The connection parameters of the tenant's storage, merged over tenancy.connection_template. They are saved
     * with the tenant, and tenancy:delete --drop only destroys a tenant whose saved parameters still match them.
     *
     * @return array<string, mixed>
     */
    public function params(string $slug): array;

    /** Creates the tenant's storage; fails if it already exists. */
    public function create(string $slug): void;

    /** Destroys the tenant's storage and everything in it. */
    public function drop(string $slug): void;
}
