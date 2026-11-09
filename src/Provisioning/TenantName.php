<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * The name of a tenant's database, schema or file: tenancy.prefix followed by the slug, with dashes turned into
 * underscores. Built only from a validated slug, so it never needs escaping beyond identifier quoting. Slugs
 * cannot contain underscores, so two slugs never give the same name.
 */
final readonly class TenantName
{
    /** PostgreSQL's identifier limit; MySQL allows 64. */
    private const int MAX_LENGTH = 63;

    /** @return non-empty-string */
    public static function for(string $prefix, string $slug): string
    {
        if (preg_match('/^[a-z0-9_]*$/D', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Config "tenancy.prefix" must be lowercase letters, digits and underscores; "%s" given.',
                $prefix,
            ));
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid tenant slug.', $slug));
        }

        $name = $prefix . str_replace('-', '_', $slug);

        if ($name === '' || strlen($name) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The storage name "%s" is longer than %d characters; use a shorter slug or prefix.',
                $name,
                self::MAX_LENGTH,
            ));
        }

        return $name;
    }
}
