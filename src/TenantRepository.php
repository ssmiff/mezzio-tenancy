<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

interface TenantRepository
{
    public function findBySlug(string $slug): ?Tenant;

    /** @return list<Tenant> */
    public function all(): array;

    public function add(Tenant $tenant): void;

    /** Removes the tenant from the landlord database; false when there was none. */
    public function remove(string $slug): bool;
}
