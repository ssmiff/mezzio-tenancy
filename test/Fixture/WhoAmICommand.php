<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test\Fixture;

use Override;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\Console\Options;
use Ssmiff\MezzioTenancy\TenantContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function is_string;
use function sprintf;

/** Reports the active tenant, the file its connection opens, and what it was given. */
final class WhoAmICommand extends Command
{
    public function __construct(private readonly TenantContext $context, private readonly Registry $registry)
    {
        parent::__construct('test:whoami');
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('word', InputArgument::OPTIONAL, '', 'none')
            ->addOption('exit', null, InputOption::VALUE_REQUIRED, '', '0');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $this->context->current();

        $output->writeln(sprintf(
            'tenant=%s file=%s word=%s interactive=%s',
            $tenant?->slug ?? '-',
            $tenant === null ? '-' : $this->tenantFile(),
            Options::argument($input, 'word') ?? '?',
            $input->isInteractive() ? 'yes' : 'no',
        ));

        return (int) (Options::string($input, 'exit') ?? '0');
    }

    private function tenantFile(): string
    {
        $connection = $this->registry->connection(Connections::TENANT);

        return self::name($connection->executeQuery('PRAGMA database_list')->fetchAssociative()['file'] ?? null);
    }

    private static function name(mixed $file): string
    {
        return is_string($file) ? basename($file) : '?';
    }
}
