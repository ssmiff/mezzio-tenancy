<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use InvalidArgumentException;
use Laminas\ServiceManager\ServiceManager;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\ConfigProvider as DoctrineConfigProvider;
use Ssmiff\MezzioDoctrine\Registry;
use Ssmiff\MezzioTenancy\ConfigProvider;
use Ssmiff\MezzioTenancy\TenantContext;
use Ssmiff\MezzioTenancy\Test\Fixture\WhoAmICommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RunAsTest extends TestCase
{
    private ServiceManager $container;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->container();
        $this->container->setService(WhoAmICommand::class, new WhoAmICommand(
            Services::get($this->container, TenantContext::class),
            Services::get($this->container, Registry::class),
        ));
        $this->addTenant($this->container, 'acme');
        $this->addTenant($this->container, 'dormant', active: false);
    }

    /** @param array<string, mixed> $input */
    private function console(array $input): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setCommandLoader(new ContainerCommandLoader($this->container, [
            ...new DoctrineConfigProvider()->commands(),
            ...new ConfigProvider()->commands(),
            'test:whoami' => WhoAmICommand::class,
        ]));
        $application->setDispatcher(
            Services::get($this->container, EventDispatcher::class, DoctrineConfigProvider::CLI_EVENT_DISPATCHER),
        );

        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false, 'decorated' => false]);

        return $tester;
    }

    public function testTheCommandRunsWithTheTenantActiveAndItsDatabase(): void
    {
        $tester = $this->console(['command' => 'tenancy:run-as', 'slug' => 'acme', 'cmd' => ['test:whoami']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('tenant=acme file=acme.sqlite', $tester->getDisplay());
    }

    public function testTheTenantIsNoLongerActiveAfterwards(): void
    {
        $this->console(['command' => 'tenancy:run-as', 'slug' => 'acme', 'cmd' => ['test:whoami']]);

        self::assertNull(Services::get($this->container, TenantContext::class)->current());
    }

    public function testArgumentsOptionsAndTheExitCodeGoThrough(): void
    {
        $tester = $this->console([
            'command' => 'tenancy:run-as',
            'slug'    => 'acme',
            'cmd'     => ['test:whoami', 'hello', '--exit=3'],
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('word=hello interactive=no', $tester->getDisplay());
    }

    public function testACommandLineWithADoubleDashPassesTheInnerOptionsThrough(): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setCommandLoader(new ContainerCommandLoader($this->container, [
            ...new ConfigProvider()->commands(),
            'test:whoami' => WhoAmICommand::class,
        ]));
        $output = new BufferedOutput();

        $input = new ArgvInput(['laminas', 'tenancy:run-as', 'acme', '--', 'test:whoami', 'hello', '--exit=4']);
        $input->setInteractive(false);

        self::assertSame(4, $application->run($input, $output));
        self::assertStringContainsString('tenant=acme file=acme.sqlite word=hello', $output->fetch());
    }

    public function testAnAbbreviatedCommandNameIsResolved(): void
    {
        $tester = $this->console(['command' => 'tenancy:run-as', 'slug' => 'acme', 'cmd' => ['test:who']]);

        self::assertStringContainsString('tenant=acme', $tester->getDisplay());
    }

    public function testMigrationsCommandsAreGivenTheTenantOption(): void
    {
        $tester = $this->console(['command' => 'tenancy:run-as', 'slug' => 'acme', 'cmd' => ['migrations:status']]);

        self::assertSame(0, $tester->getStatusCode());
        // The tenant connection's migrations, not the default (landlord) connection's.
        self::assertStringContainsString('Database\Migrations\Tenant', $tester->getDisplay());
    }

    public function testATenantOptionOfItsOwnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Give the tenant once');

        $this->console([
            'command' => 'tenancy:run-as',
            'slug'    => 'acme',
            'cmd'     => ['migrations:status', '--tenant=dormant'],
        ]);
    }

    public function testAnUnknownOrInactiveTenantIsRefused(): void
    {
        foreach (['nope', 'dormant'] as $slug) {
            try {
                $this->console(['command' => 'tenancy:run-as', 'slug' => $slug, 'cmd' => ['test:whoami']]);
                self::fail('Expected ' . $slug . ' to be refused.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Unknown or inactive tenant', $e->getMessage());
            }
        }
    }

    public function testItCannotRunItself(): void
    {
        $this->expectExceptionMessage('cannot run itself');

        $this->console([
            'command' => 'tenancy:run-as',
            'slug'    => 'acme',
            'cmd'     => ['tenancy:run-as', 'acme', 'test:whoami'],
        ]);
    }
}
