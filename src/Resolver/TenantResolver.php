<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Resolver;

use Psr\Http\Message\ServerRequestInterface;

interface TenantResolver
{
    public function identify(ServerRequestInterface $request): ?string;
}
