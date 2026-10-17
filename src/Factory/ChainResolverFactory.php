<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Factory;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioContainerHelpers\Support\Config;
use Ssmiff\MezzioTenancy\Resolver\ChainResolver;
use Ssmiff\MezzioTenancy\Resolver\TenantResolver;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;

final readonly class ChainResolverFactory implements FactoryInterface
{
    #[Override]
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ChainResolver
    {
        return new ChainResolver(array_map(
            static fn(string $id): TenantResolver => Services::get($container, TenantResolver::class, $id),
            self::ids(Config::get($container, 'tenancy.resolvers', [])),
        ));
    }

    /** @return list<string> */
    private static function ids(mixed $configured): array
    {
        return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
    }
}
