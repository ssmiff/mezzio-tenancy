<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use UnexpectedValueException;

use function is_object;

final readonly class AddTenantOption implements DelegatorFactoryInterface
{
    #[Override]
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null): object
    {
        return self::withTenantOption($callback());
    }

    private static function withTenantOption(mixed $command): object
    {
        if (! is_object($command)) {
            throw new UnexpectedValueException('Expected the delegated service to be an object.');
        }

        if ($command instanceof Command && ! $command->getDefinition()->hasOption(TenantOption::NAME)) {
            $command->addOption(
                TenantOption::NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'The slug of the tenant to run against; implies --conn=tenant.',
            );
        }

        return $command;
    }
}
