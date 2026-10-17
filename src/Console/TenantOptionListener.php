<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use InvalidArgumentException;
use Override;
use Ssmiff\MezzioDoctrine\Console\ConsoleCommandListener;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\TenantRepository;
use Ssmiff\MezzioTenancy\TenantSwitcher;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

use function sprintf;

final readonly class TenantOptionListener implements ConsoleCommandListener
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
    ) {}

    #[Override]
    public function __invoke(ConsoleCommandEvent $event): void
    {
        $definition = $event->getCommand()?->getDefinition();

        if ($definition === null || ! $definition->hasOption(TenantOption::NAME) || ! $definition->hasOption('conn')) {
            return;
        }

        $input = $event->getInput();
        $slug = Options::string($input, TenantOption::NAME);
        $connection = Options::string($input, 'conn') ?? Options::string($input, 'em');

        if ($slug === null) {
            if ($connection === Connections::TENANT) {
                throw new InvalidArgumentException('The "tenant" connection needs --tenant=<slug>.');
            }

            return;
        }

        if ($connection !== null && $connection !== Connections::TENANT) {
            throw new InvalidArgumentException(sprintf(
                '--tenant only applies to the "tenant" connection, not "%s".',
                $connection,
            ));
        }

        $tenant = $this->tenants->findBySlug($slug);

        if ($tenant === null || ! $tenant->active) {
            throw new InvalidArgumentException(sprintf('Unknown or inactive tenant "%s".', $slug));
        }

        $this->switcher->activate($tenant);
        $input->setOption('conn', Connections::TENANT);
    }
}
