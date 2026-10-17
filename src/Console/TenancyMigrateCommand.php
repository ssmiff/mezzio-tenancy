<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use InvalidArgumentException;
use Override;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function sprintf;

use const PHP_BINARY;

#[AsCommand(name: 'tenancy:migrate', description: 'Run migrations:migrate for every active tenant, one process each.')]
final class TenancyMigrateCommand extends Command
{
    public function __construct(private readonly TenantRepository $tenants)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Migrate just this tenant slug.');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $only = Options::string($input, 'only');
        $tenants = array_values(array_filter(
            $this->tenants->all(),
            static fn(Tenant $tenant): bool => $tenant->active && ($only === null || $tenant->slug === $only),
        ));

        if ($tenants === []) {
            throw new InvalidArgumentException('No matching active tenants.');
        }

        $script = self::script($_SERVER['argv'] ?? null);
        $failed = 0;

        foreach ($tenants as $tenant) {
            $output->writeln(sprintf('<info>Migrating tenant %s</info>', $tenant->slug));

            $process = new Process([
                PHP_BINARY,
                $script,
                'migrations:migrate',
                '--tenant=' . $tenant->slug,
                '--no-interaction',
            ]);
            $process->setTimeout(null);

            if ($process->run(static fn(string $type, string $buffer) => $output->write($buffer)) !== 0) {
                ++$failed;
                $output->writeln(sprintf('<error>Tenant %s failed</error>', $tenant->slug));
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private static function script(mixed $argv): string
    {
        if (! is_array($argv) || ! isset($argv[0]) || ! is_string($argv[0])) {
            throw new InvalidArgumentException('Cannot find the console script to re-run.');
        }

        return $argv[0];
    }
}
