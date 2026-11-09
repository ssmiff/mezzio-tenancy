<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Factory;

use InvalidArgumentException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioContainerHelpers\Support\Config;
use Ssmiff\MezzioTenancy\Provisioning\Provisioning;
use Ssmiff\MezzioTenancy\Provisioning\TenantProvisioner;

use function get_debug_type;
use function is_string;
use function sprintf;

/** Looks up the service named by tenancy.provisioner. */
final readonly class ProvisioningFactory implements FactoryInterface
{
    #[Override]
    public function __invoke(ContainerInterface $container, string $requestedName, ?array $options = null): Provisioning
    {
        $id = Config::get($container, 'tenancy.provisioner');

        if ($id === null) {
            return new Provisioning();
        }

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException(sprintf(
                'Config "tenancy.provisioner" must be a service id or null, %s given.',
                get_debug_type($id),
            ));
        }

        return new Provisioning(Services::get($container, TenantProvisioner::class, $id));
    }
}
