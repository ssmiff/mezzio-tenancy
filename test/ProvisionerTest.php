<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Ssmiff\MezzioTenancy\Provisioning\DatabaseProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\SchemaProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\SqliteFileProvisioner;
use Ssmiff\MezzioTenancy\Provisioning\TenantName;

use function file_exists;
use function str_repeat;
use function touch;

final class ProvisionerTest extends TestCase
{
    /** @var list<string> */
    private array $executed = [];

    /** A connection that records statements in $executed instead of running them. */
    private function connection(AbstractPlatform $platform): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quoteSingleIdentifier')->willReturnCallback($platform->quoteSingleIdentifier(...));
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->executed[] = $sql;

            return 0;
        });

        return $connection;
    }

    public function testTheNameIsThePrefixAndTheSlugWithUnderscores(): void
    {
        self::assertSame('tenant_acme_co', TenantName::for('tenant_', 'acme-co'));
        self::assertSame('acme', TenantName::for('', 'acme'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidNames(): iterable
    {
        yield 'prefix with a quote'    => ['te"nant_', 'acme'];
        yield 'prefix with a dash'     => ['tenant-', 'acme'];
        yield 'uppercase prefix'       => ['Tenant_', 'acme'];
        yield 'slug with a dot'        => ['tenant_', 'ac.me'];
        yield 'slug with a newline'    => ['tenant_', "acme\n"];
        yield 'longer than 63 chars'   => ['tenant_', str_repeat('a', 57)];
    }

    #[DataProvider('invalidNames')]
    public function testUnsafeOrOverlongNamesAreRefused(string $prefix, string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        TenantName::for($prefix, $slug);
    }

    public function testADatabasePerTenantOnPostgres(): void
    {
        $provisioner = new DatabaseProvisioner($this->connection(new PostgreSQLPlatform()), 'tenant_');

        self::assertSame(['dbname' => 'tenant_acme_co'], $provisioner->params('acme-co'));

        $provisioner->create('acme-co');
        $provisioner->drop('acme-co');

        self::assertSame(['CREATE DATABASE "tenant_acme_co"', 'DROP DATABASE "tenant_acme_co"'], $this->executed);
    }

    public function testADatabasePerTenantOnMysql(): void
    {
        new DatabaseProvisioner($this->connection(new MySQLPlatform()), 'tenant_')->create('acme');

        self::assertSame(['CREATE DATABASE `tenant_acme`'], $this->executed);
    }

    public function testADatabasePerTenantIsRefusedOnSqlite(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SqliteFileProvisioner');

        new DatabaseProvisioner($this->connection(new SQLitePlatform()))->create('acme');
    }

    public function testASchemaPerTenantOnPostgres(): void
    {
        $provisioner = new SchemaProvisioner($this->connection(new PostgreSQLPlatform()), 'tenant_');

        self::assertSame(['search_path' => ['tenant_acme']], $provisioner->params('acme'));

        $provisioner->create('acme');
        $provisioner->drop('acme');

        self::assertSame(['CREATE SCHEMA "tenant_acme"', 'DROP SCHEMA "tenant_acme" CASCADE'], $this->executed);
    }

    public function testASchemaPerTenantIsRefusedOutsidePostgres(): void
    {
        $this->expectException(LogicException::class);

        new SchemaProvisioner($this->connection(new MySQLPlatform()))->create('acme');
    }

    public function testAnSqliteFilePerTenant(): void
    {
        $provisioner = new SqliteFileProvisioner('data/tenants', $this->base, 'tenant_');
        $file        = $this->base . '/data/tenants/tenant_acme.sqlite';

        self::assertSame(['path' => 'data/tenants/tenant_acme.sqlite'], $provisioner->params('acme'));

        $provisioner->create('acme');
        self::assertFileExists($file);

        touch($file . '-wal');
        $provisioner->drop('acme');
        self::assertFalse(file_exists($file) || file_exists($file . '-wal'));
    }

    public function testAnSqliteFileThatAlreadyExistsIsNotReused(): void
    {
        $provisioner = new SqliteFileProvisioner('data/tenants', $this->base, 'tenant_');
        $provisioner->create('acme');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $provisioner->create('acme');
    }
}
