<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\Tenant;

final readonly class TenantSwitcher
{
    public function __construct(
        private TenantContext $context,
        private Registry $registry,
    ) {}

    public function activate(Tenant $tenant): void
    {
        $this->context->set($tenant);
        $this->registry->reset(Connections::TENANT);
    }

    public function deactivate(): void
    {
        $this->context->set(null);
        $this->registry->reset(Connections::TENANT);
    }

    /**
     * @template T
     * @param callable(Tenant): T $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->context->current();
        $this->activate($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previous === null ? $this->deactivate() : $this->activate($previous);
        }
    }
}
