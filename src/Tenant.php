<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

final readonly class Tenant
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $slug,
        public string $name,
        public array $params,
        public bool $active = true,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid tenant slug; use lowercase letters, digits and dashes.',
                $slug,
            ));
        }
    }
}
