<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use Override;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_keys;
use function implode;

#[AsCommand(name: 'tenancy:list', description: 'List the tenants in the landlord database.')]
final class TenantListCommand extends Command
{
    public function __construct(private readonly TenantRepository $tenants)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Slug', 'Name', 'Active', 'Connection parameters']);

        foreach ($this->tenants->all() as $tenant) {
            $table->addRow([
                $tenant->slug,
                $tenant->name,
                $tenant->active ? 'yes' : 'no',
                implode(', ', array_keys($tenant->params)),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
