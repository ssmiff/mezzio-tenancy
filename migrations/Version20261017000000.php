<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Override;

final class Version20261017000000 extends AbstractMigration
{
    #[Override]
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('tenants');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('slug', Types::STRING, ['length' => 64]);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('params', Types::JSON);
        $table->addColumn('active', Types::BOOLEAN);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['slug']);
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenants');
    }
}
