<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Resolver;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;

use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;

final readonly class SubdomainResolver implements TenantResolver
{
    public function __construct(
        #[ConfigValue('tenancy.base_domain')]
        private ?string $baseDomain = null,
    ) {}

    #[Override]
    public function identify(ServerRequestInterface $request): ?string
    {
        if ($this->baseDomain === null) {
            return null;
        }

        $suffix = '.' . strtolower($this->baseDomain);
        $host = strtolower($request->getUri()->getHost());

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        return $subdomain === '' || str_contains($subdomain, '.') ? null : $subdomain;
    }
}
