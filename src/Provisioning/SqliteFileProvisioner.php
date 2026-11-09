<?php

declare(strict_types=1);

namespace Ssmiff\MezzioTenancy\Provisioning;

use Override;
use RuntimeException;
use Ssmiff\MezzioDoctrine\Support\Paths;

use function file_exists;
use function is_dir;
use function mkdir;
use function rtrim;
use function sprintf;
use function touch;
use function unlink;

/**
 * An SQLite file per tenant, in tenancy.sqlite_directory. The saved path stays relative when the directory is,
 * so it follows doctrine.base_path rather than the directory the app happened to be deployed to.
 */
final readonly class SqliteFileProvisioner implements TenantProvisioner
{
    public function __construct(
        private string $directory = 'data/tenants',
        private string $base = '.',
        private string $prefix = 'tenant_',
    ) {}

    #[Override]
    public function params(string $slug): array
    {
        return ['path' => rtrim($this->directory, '/') . '/' . TenantName::for($this->prefix, $slug) . '.sqlite'];
    }

    #[Override]
    public function create(string $slug): void
    {
        $file = $this->file($slug);

        if (file_exists($file)) {
            throw new RuntimeException(sprintf('The tenant database "%s" already exists.', $file));
        }

        $directory = Paths::resolve($this->directory, $this->base);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the directory "%s".', $directory));
        }

        if (! touch($file)) {
            throw new RuntimeException(sprintf('Cannot create the tenant database "%s".', $file));
        }
    }

    #[Override]
    public function drop(string $slug): void
    {
        $file = $this->file($slug);

        foreach ([$file, $file . '-wal', $file . '-shm', $file . '-journal'] as $path) {
            if (file_exists($path) && ! unlink($path)) {
                throw new RuntimeException(sprintf('Cannot delete "%s".', $path));
            }
        }
    }

    private function file(string $slug): string
    {
        /** @var string $path */
        $path = $this->params($slug)['path'];

        return Paths::resolve($path, $this->base);
    }
}
