<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Test\Fixture;

use Closure;
use Laminas\Diactoros\Response\EmptyResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CallbackHandler implements RequestHandlerInterface
{
    /** @param Closure(ServerRequestInterface): void $callback */
    public function __construct(private Closure $callback, private int $status = 204) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ($this->callback)($request);

        return new EmptyResponse($this->status);
    }
}
