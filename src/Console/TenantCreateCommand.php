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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_intersect_key;
use function array_keys;
use function implode;
use function sprintf;
use function strpos;
use function substr;

#[AsCommand(name: 'tenancy:create', description: 'Register a tenant and create its database, schema or file.')]
final class TenantCreateCommand extends Command
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
            ->addArgument('slug', InputArgument::REQUIRED, 'Lowercase identifier used in the subdomain or header.')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name.')
            ->addOption(
                'param',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Connection parameter as key=value, e.g. --param=dbname=acme.',
            )
            ->addOption(
                'no-provision',
                null,
                InputOption::VALUE_NONE,
                'Register the tenant only; its storage already exists (or no provisioner is configured).',
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

        $explicit    = self::params(Options::strings($input, 'param'));
        $provisioner = $input->getOption('no-provision') === true ? null : $this->provisioning->provisioner;
        $derived     = $this->provisioning->provisioner?->params($slug) ?? [];

        $clash = array_keys(array_intersect_key($explicit, $derived));
        if ($provisioner !== null && $clash !== []) {
            throw new InvalidArgumentException(sprintf(
                '--param=%s would point the tenant away from the storage the provisioner creates; '
                . 'add --no-provision if that storage already exists.',
                implode(', --param=', $clash),
            ));
        }

        // Validates the slug before anything is created.
        $tenant = new Tenant($slug, $name, [...$derived, ...$explicit]);

        if ($provisioner !== null) {
            $provisioner->create($slug);
            $output->writeln(sprintf('Created the storage of tenant <info>%s</info>.', $slug));
        }

        try {
            $this->tenants->add($tenant);
        } catch (Throwable $e) {
            // Leave nothing behind that no tenant points to.
            $provisioner?->drop($slug);

            throw $e;
        }

        $output->writeln(sprintf('Created tenant <info>%s</info>.', $slug));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $pairs
     * @return array<string, string>
     */
    private static function params(array $pairs): array
    {
        $params = [];
        foreach ($pairs as $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                throw new InvalidArgumentException(sprintf('Parameter "%s" must be key=value.', $pair));
            }
            $params[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
        }

        return $params;
    }
}
