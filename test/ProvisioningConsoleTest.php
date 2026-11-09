<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use InvalidArgumentException;
use Laminas\ServiceManager\ServiceManager;
use LogicException;
use Override;
use RuntimeException;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\ConfigProvider as DoctrineConfigProvider;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\ConfigProvider;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\Provisioning\SqliteFileProvisioner;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\TenantRepository;
use Ssmiff\MezzioTenancy\TenantSwitcher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ProvisioningConsoleTest extends TestCase
{
    private const string FILE = '/files/t_acme_co.sqlite';

    private function provisioned(): ServiceManager
    {
        return $this->container(['tenancy' => [
            'provisioner'      => SqliteFileProvisioner::class,
            'prefix'           => 't_',
            'sqlite_directory' => 'files',
        ]]);
    }

    private function app(ServiceManager $container): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setCommandLoader(new ContainerCommandLoader($container, [
            ...new DoctrineConfigProvider()->commands(),
            ...new ConfigProvider()->commands(),
        ]));
        $application->setDispatcher(
            Services::get($container, EventDispatcher::class, DoctrineConfigProvider::CLI_EVENT_DISPATCHER),
        );

        return new ApplicationTester($application);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $answers
     */
    private function console(
        ServiceManager $container,
        array $input,
        bool $interactive = false,
        array $answers = [],
    ): int {
        $app = $this->app($container);
        $app->setInputs($answers);

        return $app->run($input, ['interactive' => $interactive]);
    }

    /** @param array<string, mixed> $options */
    private function create(ServiceManager $container, string $slug = 'acme-co', array $options = []): int
    {
        $input = ['command' => 'tenancy:create', 'slug' => $slug, 'name' => 'Acme', ...$options];

        return $this->console($container, $input);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $answers
     */
    private function delete(
        ServiceManager $container,
        string $slug = 'acme-co',
        array $options = [],
        array $answers = [],
    ): int {
        return $this->console(
            $container,
            ['command' => 'tenancy:delete', 'slug' => $slug, ...$options],
            $answers !== [],
            $answers,
        );
    }

    private function tenantParams(ServiceManager $container, string $slug = 'acme-co'): ?array
    {
        return Services::get($container, TenantRepository::class)->findBySlug($slug)?->params;
    }

    public function testCreateMakesTheStorageAndSavesItsParameters(): void
    {
        $container = $this->provisioned();

        self::assertSame(0, $this->create($container));

        self::assertFileExists($this->base . self::FILE);
        self::assertSame(['path' => 'files/t_acme_co.sqlite'], $this->tenantParams($container));
    }

    public function testTheTenantConnectionOpensTheProvisionedFile(): void
    {
        $container = $this->provisioned();
        $this->create($container);

        $tenant = Services::get($container, TenantRepository::class)->findBySlug('acme-co');
        self::assertNotNull($tenant);

        $file = Services::get($container, TenantSwitcher::class)->run(
            $tenant,
            fn(): string => $this->databaseFile(
                Services::get($container, Registry::class)->connection(Connections::TENANT),
            ),
        );

        self::assertSame('t_acme_co.sqlite', $file);
    }

    public function testNoProvisionRegistersTheTenantUnderTheSameNameWithoutCreatingAnything(): void
    {
        $container = $this->provisioned();

        $this->create($container, options: ['--no-provision' => true]);

        self::assertFileDoesNotExist($this->base . self::FILE);
        self::assertSame(['path' => 'files/t_acme_co.sqlite'], $this->tenantParams($container));
    }

    public function testAParamThatWouldPointAwayFromTheProvisionedStorageIsRefused(): void
    {
        $container = $this->provisioned();

        try {
            $this->create($container, options: ['--param' => ['path=elsewhere.sqlite']]);
            self::fail('Expected the clashing --param to be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('--no-provision', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->base . self::FILE);
        self::assertNull(Services::get($container, TenantRepository::class)->findBySlug('acme-co'));
    }

    public function testTheStorageIsDroppedAgainWhenRegisteringTheTenantFails(): void
    {
        $container = $this->provisioned();
        $container->setAllowOverride(true);
        $container->setService(TenantRepository::class, new class implements TenantRepository {
            #[Override]
            public function findBySlug(string $slug): ?Tenant
            {
                return null;
            }

            #[Override]
            public function all(): array
            {
                return [];
            }

            #[Override]
            public function add(Tenant $tenant): void
            {
                throw new RuntimeException('The landlord database is unavailable.');
            }

            #[Override]
            public function remove(string $slug): bool
            {
                return false;
            }
        });

        try {
            $this->create($container);
            self::fail('Expected registering to fail.');
        } catch (RuntimeException $e) {
            self::assertSame('The landlord database is unavailable.', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->base . self::FILE);
    }

    public function testWithoutAProvisionerCreateOnlyRegistersTheTenant(): void
    {
        $container = $this->container();

        $this->create($container, 'acme', ['--param' => ['path=x.sqlite']]);

        self::assertSame(['path' => 'x.sqlite'], $this->tenantParams($container, 'acme'));
    }

    public function testDeleteWithoutDropKeepsTheStorage(): void
    {
        $container = $this->provisioned();
        $this->create($container);

        self::assertSame(0, $this->delete($container));

        self::assertNull(Services::get($container, TenantRepository::class)->findBySlug('acme-co'));
        self::assertFileExists($this->base . self::FILE);
    }

    public function testDeleteWithDropAndForceDestroysTheStorage(): void
    {
        $container = $this->provisioned();
        $this->create($container);

        self::assertSame(0, $this->delete($container, options: ['--drop' => true, '--force' => true]));

        self::assertNull(Services::get($container, TenantRepository::class)->findBySlug('acme-co'));
        self::assertFileDoesNotExist($this->base . self::FILE);
    }

    public function testDropAsksForTheSlugAndDoesNothingOnAWrongAnswer(): void
    {
        $container = $this->provisioned();
        $this->create($container);

        $wrong = $this->delete($container, options: ['--drop' => true], answers: ['acme']);
        self::assertSame(Command::FAILURE, $wrong);
        self::assertFileExists($this->base . self::FILE);
        self::assertNotNull(Services::get($container, TenantRepository::class)->findBySlug('acme-co'));

        $right = $this->delete($container, options: ['--drop' => true], answers: ['acme-co']);
        self::assertSame(Command::SUCCESS, $right);
        self::assertFileDoesNotExist($this->base . self::FILE);
    }

    public function testDropNeedsForceWhenNotInteractive(): void
    {
        $container = $this->provisioned();
        $this->create($container);

        $this->expectExceptionMessage('--force');

        $this->delete($container, options: ['--drop' => true]);
    }

    public function testDropRefusesATenantWhoseStorageTheProvisionerDidNotName(): void
    {
        $container = $this->provisioned();
        $this->addTenant($container, 'acme'); // its own path, not files/t_acme.sqlite
        $own = $this->base . '/tenants/acme.sqlite';
        @mkdir($this->base . '/tenants', 0775, true);
        touch($own);

        try {
            $this->delete($container, 'acme', ['--drop' => true, '--force' => true]);
            self::fail('Expected the drop to be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('does not use the storage', $e->getMessage());
        }

        self::assertFileExists($own);
        self::assertNotNull(Services::get($container, TenantRepository::class)->findBySlug('acme'));
    }

    public function testDropNeedsAProvisioner(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tenancy.provisioner');

        $this->delete($container, 'acme', ['--drop' => true, '--force' => true]);
    }
}
