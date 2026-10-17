<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Exception;

use LogicException;

final class NoTenant extends LogicException
{
    public static function active(): self
    {
        return new self(
            'No tenant is active. Resolve one with TenantMiddleware, --tenant=<slug>, or TenantSwitcher::run().',
        );
    }
}
