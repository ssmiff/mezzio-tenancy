<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

use LogicException;

/** The provisioner chosen with tenancy.provisioner, if any. */
final readonly class Provisioning
{
    public function __construct(public ?TenantProvisioner $provisioner = null) {}

    public function require(): TenantProvisioner
    {
        return $this->provisioner ?? throw new LogicException(
            'No tenant provisioner is configured; set tenancy.provisioner to create or drop tenant storage.',
        );
    }
}
