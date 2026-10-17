<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

interface TenantRepository
{
    public function findBySlug(string $slug): ?Tenant;

    /** @return list<Tenant> */
    public function all(): array;

    public function add(Tenant $tenant): void;
}
