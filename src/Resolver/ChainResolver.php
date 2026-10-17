<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Resolver;

use Override;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ChainResolver implements TenantResolver
{
    /** @param list<TenantResolver> $resolvers */
    public function __construct(private array $resolvers) {}

    #[Override]
    public function identify(ServerRequestInterface $request): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $slug = $resolver->identify($request);

            if ($slug !== null) {
                return $slug;
            }
        }

        return null;
    }
}
