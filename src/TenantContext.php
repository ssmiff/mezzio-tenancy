<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\Exception\NoTenant;

final class TenantContext
{
    private ?Tenant $current = null;

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function require(): Tenant
    {
        return $this->current ?? throw NoTenant::active();
    }

    public function set(?Tenant $tenant): void
    {
        $this->current = $tenant;
    }
}
