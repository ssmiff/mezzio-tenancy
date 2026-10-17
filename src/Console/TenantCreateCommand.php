<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use InvalidArgumentException;
use Override;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;
use function strpos;
use function substr;

#[AsCommand(name: 'tenancy:create', description: 'Register a tenant in the landlord database.')]
final class TenantCreateCommand extends Command
{
    public function __construct(private readonly TenantRepository $tenants)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Lowercase identifier used in the subdomain or header.')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name.')
            ->addOption(
                'param',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Connection parameter as key=value, e.g. --param=dbname=acme.',
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = Options::argument($input, 'slug') ?? throw new InvalidArgumentException('A slug is required.');
        $name = Options::argument($input, 'name') ?? throw new InvalidArgumentException('A name is required.');

        if ($this->tenants->findBySlug($slug) !== null) {
            throw new InvalidArgumentException(sprintf('Tenant "%s" already exists.', $slug));
        }

        $params = [];
        foreach (Options::strings($input, 'param') as $pair) {
            $separator = strpos($pair, '=');

            if ($separator === false) {
                throw new InvalidArgumentException(sprintf('Parameter "%s" must be key=value.', $pair));
            }

            $params[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
        }

        $this->tenants->add(new Tenant($slug, $name, $params));
        $output->writeln(sprintf('Created tenant <info>%s</info>.', $slug));

        return Command::SUCCESS;
    }
}
