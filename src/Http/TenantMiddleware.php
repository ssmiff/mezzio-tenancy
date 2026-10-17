<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Http;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;
use Ssmiff\MezzioTenancy\Tenant;
use Ssmiff\MezzioTenancy\Resolver\TenantResolver;
use Ssmiff\MezzioTenancy\TenantRepository;
use Ssmiff\MezzioTenancy\TenantSwitcher;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final readonly class TenantMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantResolver $resolver,
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private ResponseFactoryInterface $responses,
        #[ConfigValue('tenancy.required', true)]
        private bool $required,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $slug = $this->resolver->identify($request);

        if ($slug === null) {
            return $this->required ? $this->notFound('No tenant specified') : $handler->handle($request);
        }

        $tenant = $this->tenants->findBySlug($slug);

        if ($tenant === null || ! $tenant->active) {
            return $this->notFound('Unknown tenant');
        }

        $this->switcher->activate($tenant);

        try {
            return $handler->handle($request->withAttribute(Tenant::class, $tenant));
        } finally {
            $this->switcher->deactivate();
        }
    }

    private function notFound(string $reason): ResponseInterface
    {
        $response = $this->responses->createResponse(404)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(['error' => $reason], JSON_THROW_ON_ERROR));

        return $response;
    }
}
