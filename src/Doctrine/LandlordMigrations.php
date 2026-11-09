<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Doctrine;

use Override;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;
use Ssmiff\MezzioDoctrine\Migrations\MigrationsPathsProvider;
use Ssmiff\MezzioTenancy\ConfigProvider;
use Ssmiff\MezzioTenancy\Connections;

/** Supplies the landlord migrations to whichever connection tenancy.landlord_connection names. */
final readonly class LandlordMigrations implements MigrationsPathsProvider
{
    public function __construct(
        #[ConfigValue('tenancy.landlord_connection', Connections::LANDLORD)]
        private string $landlord,
    ) {}

    #[Override]
    public function migrationsPaths(string $connection): array
    {
        return $connection === $this->landlord ? ConfigProvider::MIGRATIONS_PATHS : [];
    }
}
