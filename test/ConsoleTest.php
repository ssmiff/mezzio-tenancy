<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Laminas\ServiceManager\ServiceManager;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioDoctrine\ConfigProvider as DoctrineConfigProvider;
use Ssmiff\MezzioTenancy\ConfigProvider;
use Ssmiff\MezzioTenancy\Connections;
use Ssmiff\MezzioTenancy\TenantRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PDO;
use PDOStatement;

use function file_put_contents;
use function mkdir;

final class ConsoleTest extends TestCase
{
    private function app(ServiceManager $container): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setCommandLoader(new ContainerCommandLoader($container, [
            ...new DoctrineConfigProvider()->commands(),
            ...new ConfigProvider()->commands(),
        ]));
        $dispatcher = Services::get($container, EventDispatcher::class, DoctrineConfigProvider::CLI_EVENT_DISPATCHER);
        $application->setDispatcher($dispatcher);

        return new ApplicationTester($application);
    }

    public function testTenantOptionSelectsTheTenantConnection(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');
        $app = $this->app($container);

        $app->run(['command' => 'migrations:status', '--tenant' => 'acme'], ['decorated' => false]);

        self::assertStringContainsString('Database\Migrations\Tenant', $app->getDisplay());
    }

    public function testMigrationsRunAgainstTheChosenTenantOnly(): void
    {
        $container = $this->container(['doctrine' => ['migrations' => ['connections' => ['tenant' => [
            'migrations_paths' => ['Tenant' => $this->base . '/migrations'],
        ]]]]]);
        $this->addTenant($container, 'acme');
        $this->addTenant($container, 'globex');
        mkdir($this->base . '/migrations');
        file_put_contents($this->base . '/migrations/Version1.php', <<<'PHP'
            <?php
            namespace Tenant;
            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;
            final class Version1 extends AbstractMigration
            {
                public function up(Schema $schema): void { $this->addSql('CREATE TABLE notes (id INTEGER)'); }
            }
            PHP);

        self::assertSame(
            0,
            $this->app($container)->run(['command' => 'migrations:migrate', '--tenant' => 'acme', '-n' => true]),
        );

        $sqlite = new PDO('sqlite:' . $this->base . '/tenants/acme.sqlite');
        $statement = $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");

        self::assertInstanceOf(PDOStatement::class, $statement);
        self::assertSame(['doctrine_migration_versions', 'notes'], $statement->fetchAll(PDO::FETCH_COLUMN));
        self::assertFileDoesNotExist($this->base . '/tenants/globex.sqlite');
    }

    public function testShippedLandlordMigrationCreatesTheTenantsTable(): void
    {
        $container = $this->container(landlordSchema: false);

        self::assertSame(
            0,
            $this->app($container)->run([
                'command' => 'migrations:migrate',
                '--conn' => Connections::LANDLORD,
                '-n' => true,
            ]),
        );

        $this->addTenant($container, 'acme');
        $tenant = Services::get($container, TenantRepository::class)->findBySlug('acme');

        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->slug);
        self::assertTrue($tenant->active);
        self::assertSame(['path' => $this->base . '/tenants/acme.sqlite'], $tenant->params);
    }

    public function testTenantConnectionRequiresATenant(): void
    {
        $this->expectExceptionMessage('needs --tenant=<slug>');

        $this->app($this->container())->run(['command' => 'migrations:status', '--conn' => Connections::TENANT]);
    }

    public function testTenantOptionConflictsWithOtherConnections(): void
    {
        $container = $this->container();
        $this->addTenant($container, 'acme');

        $this->expectExceptionMessage('--tenant only applies to the "tenant" connection');

        $this->app($container)->run([
            'command' => 'migrations:status',
            '--tenant' => 'acme',
            '--conn' => Connections::LANDLORD,
        ]);
    }

    public function testUnknownTenantIsRejected(): void
    {
        $this->expectExceptionMessage('Unknown or inactive tenant "nope"');

        $this->app($this->container())->run(['command' => 'migrations:status', '--tenant' => 'nope']);
    }

    public function testCreateAndListTenants(): void
    {
        $container = $this->container();
        $app = $this->app($container);

        self::assertSame(
            0,
            $app->run([
                'command' => 'tenancy:create',
                'slug' => 'acme',
                'name' => 'Acme Ltd',
                '--param' => ['dbname=acme', 'host=db'],
            ]),
        );
        self::assertSame(0, $app->run(['command' => 'tenancy:list'], ['decorated' => false]));

        self::assertStringContainsString('Acme Ltd', $app->getDisplay());
        self::assertSame(
            ['dbname' => 'acme', 'host' => 'db'],
            Services::get($container, TenantRepository::class)->findBySlug('acme')?->params,
        );
    }
}
