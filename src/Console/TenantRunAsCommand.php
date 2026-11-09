<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use InvalidArgumentException;
use LogicException;
use Override;
use Ssmiff\MezzioTenancy\TenantRepository;
use Ssmiff\MezzioTenancy\TenantSwitcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_shift;
use function sprintf;
use function str_starts_with;

/**
 * Runs another console command with a tenant active:
 *
 *     tenancy:run-as acme -- app:send-invoices --since=yesterday
 *
 * The tenant is active for the whole command, so TenantContext and the "tenant" connection and entity manager
 * point at it, and the previous state is restored afterwards. Migrations and ORM commands are given --tenant
 * instead, because they choose their connection from --conn rather than from the active tenant.
 */
#[AsCommand(name: 'tenancy:run-as', description: 'Run a console command with a tenant active.')]
final class TenantRunAsCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantSwitcher $switcher,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'The tenant to run the command as.')
            ->addArgument(
                'cmd',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'The command and its arguments. Put -- before it when it has options of its own.',
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug   = Options::argument($input, 'slug') ?? throw new InvalidArgumentException('A slug is required.');
        $tenant = $this->tenants->findBySlug($slug);

        if ($tenant === null || ! $tenant->active) {
            throw new InvalidArgumentException(sprintf('Unknown or inactive tenant "%s".', $slug));
        }

        $tokens = Options::arguments($input, 'cmd');
        $name   = array_shift($tokens) ?? throw new InvalidArgumentException('Name the command to run.');

        $application = $this->getApplication()
            ?? throw new LogicException('tenancy:run-as needs a console application.');
        $command     = $application->find($name);

        if ($command->getName() === $this->getName()) {
            throw new InvalidArgumentException('tenancy:run-as cannot run itself.');
        }

        $byOption = $command->getDefinition()->hasOption(TenantOption::NAME);

        if ($byOption) {
            foreach ($tokens as $token) {
                if ($token === '--' . TenantOption::NAME || str_starts_with($token, '--' . TenantOption::NAME . '=')) {
                    throw new InvalidArgumentException(sprintf(
                        'Give the tenant once: tenancy:run-as already passes --%s=%s.',
                        TenantOption::NAME,
                        $slug,
                    ));
                }
            }

            $tokens[] = sprintf('--%s=%s', TenantOption::NAME, $slug);
        }

        $inner = new ArgvInput(['tenancy:run-as', (string) $command->getName(), ...$tokens]);
        $inner->setInteractive($input->isInteractive());

        $run = static fn(): int => $application->doRun($inner, $output);

        return $byOption ? $run() : $this->switcher->run($tenant, static fn(): int => $run());
    }
}
