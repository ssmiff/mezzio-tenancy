<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Override;
use InvalidArgumentException;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;
use Ssmiff\MezzioDoctrine\Registry;

use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function strval;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final readonly class DbalTenantRepository implements TenantRepository
{
    private Connection $landlord;

    public function __construct(
        Registry $registry,
        #[ConfigValue('tenancy.landlord_connection', Connections::LANDLORD)]
        string $connection,
    ) {
        if ($connection === '') {
            throw new InvalidArgumentException('Config "tenancy.landlord_connection" must name a doctrine connection.');
        }

        $this->landlord = $registry->connection($connection);
    }

    #[Override]
    public function findBySlug(string $slug): ?Tenant
    {
        return self::hydrate(
            $this->select()
                ->where('slug = :slug')
                ->setParameter('slug', $slug)
                ->executeQuery()
                ->fetchAssociative(),
        );
    }

    #[Override]
    public function all(): array
    {
        $rows = $this->select()->orderBy('slug')->executeQuery()->fetchAllAssociative();
        $tenants = array_map(self::hydrate(...), $rows);

        return array_values(array_filter($tenants, static fn(?Tenant $tenant): bool => $tenant !== null));
    }

    #[Override]
    public function add(Tenant $tenant): void
    {
        $this->landlord->insert(
            'tenants',
            [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'params' => json_encode($tenant->params, JSON_THROW_ON_ERROR),
                'active' => $tenant->active,
            ],
            ['active' => Types::BOOLEAN],
        );
    }

    #[Override]
    public function remove(string $slug): bool
    {
        return $this->landlord->delete('tenants', ['slug' => $slug]) > 0;
    }

    private function select(): QueryBuilder
    {
        return $this->landlord->createQueryBuilder()->select('slug', 'name', 'params', 'active')->from('tenants');
    }

    private static function hydrate(mixed $row): ?Tenant
    {
        if (! is_array($row)) {
            return null;
        }

        $slug = self::text($row['slug'] ?? null);
        $name = self::text($row['name'] ?? null);
        $params = self::text($row['params'] ?? null);

        if ($slug === null || $name === null || $params === null) {
            return null;
        }

        return new Tenant($slug, $name, self::decode($params), self::truthy($row['active'] ?? null));
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        return self::keyed(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function keyed(mixed $decoded): array
    {
        return is_array($decoded) ? array_combine(array_map(strval(...), array_keys($decoded)), $decoded) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
