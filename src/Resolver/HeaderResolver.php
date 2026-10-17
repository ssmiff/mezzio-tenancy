<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Resolver;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;

final readonly class HeaderResolver implements TenantResolver
{
    public function __construct(
        #[ConfigValue('tenancy.header', 'X-Tenant')]
        private string $header,
    ) {}

    #[Override]
    public function identify(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine($this->header);

        return $value === '' ? null : $value;
    }
}
