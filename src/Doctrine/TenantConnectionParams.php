<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Doctrine;

use Override;
use Ssmiff\MezzioContainerHelpers\Attribute\ConfigValue;
use Ssmiff\MezzioDoctrine\ParamsProvider;
use Ssmiff\MezzioTenancy\TenantContext;

final readonly class TenantConnectionParams implements ParamsProvider
{
    /** @param array<string, mixed> $template */
    public function __construct(
        private TenantContext $context,
        #[ConfigValue('tenancy.connection_template', [])]
        private array $template,
    ) {}

    #[Override]
    public function params(): array
    {
        return [...$this->template, ...$this->context->require()->params];
    }
}
