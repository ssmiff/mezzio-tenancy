<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Console;

use InvalidArgumentException;
use Override;
use Ssmiff\MezzioTenancy\Provisioning\Provisioning;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function array_key_exists;
use function array_keys;
use function sprintf;

#[AsCommand(name: 'tenancy:delete', description: 'Remove a tenant; with --drop also destroy its storage.')]
final class TenantDeleteCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly Provisioning $provisioning,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'The tenant to remove.')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Also destroy its storage and all its data.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Drop without asking for the slug to be typed.');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug   = Options::argument($input, 'slug') ?? throw new InvalidArgumentException('A slug is required.');
        $tenant = $this->tenants->findBySlug($slug)
            ?? throw new InvalidArgumentException(sprintf('Unknown tenant "%s".', $slug));

        if ($input->getOption('drop') !== true) {
            self::removed($this->tenants->remove($slug), $slug);
            $output->writeln(sprintf('Removed tenant <info>%s</info>; its storage was left in place.', $slug));

            return Command::SUCCESS;
        }

        $provisioner = $this->provisioning->require();

        if (! self::matches($tenant, $provisioner->params($slug))) {
            throw new InvalidArgumentException(sprintf(
                'Tenant "%s" does not use the storage the provisioner would create for it, so it is not dropped. '
                . 'Remove it without --drop and destroy its storage yourself.',
                $slug,
            ));
        }

        if ($input->getOption('force') !== true && ! $this->confirmed($input, $output, $slug)) {
            $output->writeln('<comment>Nothing was changed.</comment>');

            return Command::FAILURE;
        }

        // Storage first: if dropping fails, the tenant is still registered and the command can be run again.
        $provisioner->drop($slug);
        self::removed($this->tenants->remove($slug), $slug);
        $output->writeln(sprintf('Removed tenant <info>%s</info> and destroyed its storage.', $slug));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $provisioned */
    private static function matches(Tenant $tenant, array $provisioned): bool
    {
        foreach (array_keys($provisioned) as $key) {
            if (! array_key_exists($key, $tenant->params) || $tenant->params[$key] !== $provisioned[$key]) {
                return false;
            }
        }

        return true;
    }

    private static function removed(bool $removed, string $slug): void
    {
        if (! $removed) {
            throw new InvalidArgumentException(sprintf('Tenant "%s" was removed by someone else meanwhile.', $slug));
        }
    }

    private function confirmed(InputInterface $input, OutputInterface $output, string $slug): bool
    {
        if (! $input->isInteractive()) {
            throw new InvalidArgumentException('--drop needs --force when the command is not interactive.');
        }

        $helper = $this->getHelper('question');
        if (! $helper instanceof QuestionHelper) {
            return false;
        }

        return $helper->ask($input, $output, new Question(sprintf(
            '<question>This destroys all data of tenant "%s". Type the slug to confirm:</question> ',
            $slug,
        ))) === $slug;
    }
}
