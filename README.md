# ssmiff/mezzio-tenancy

Database-per-tenant multi-tenancy for Mezzio, built on [ssmiff/mezzio-doctrine](https://github.com/ssmiff/mezzio-doctrine).

- Finds the tenant of each request (header, subdomain, or your own resolver).
- Points a `tenant` connection and entity manager at that tenant's database for the duration of the request.
- Adds `--tenant=<slug>` to every Doctrine migrations and ORM console command, and `tenancy:migrate` to migrate every tenant.

Requires PHP 8.5. Licensed under the [MIT licence](LICENSE).

## Install

```
composer require ssmiff/mezzio-tenancy
```

It depends on `ssmiff/mezzio-doctrine` and `ssmiff/mezzio-container-helpers`; `laminas-component-installer` registers all three config providers.
You also need a PSR-17 `ResponseFactoryInterface` in the container (Mezzio provides one).

## Concepts

- The **landlord** database holds the list of tenants. It is a normal mezzio-doctrine connection called `landlord`, which you define. Tenants are plain `Tenant` value objects read and written with DBAL by `DbalTenantRepository`; no ORM entity or entity manager is involved. Replace it by providing your own `TenantRepository`.
- Each **tenant** has its own database. The connection and entity manager for it are both called `tenant`. The module defines the connection; you map your tenant entities and migrations onto it.
- `tenant` is a *dynamic* connection: its parameters come from the active tenant, so one name serves every tenant.

## Configure

```php
return [
    'doctrine' => [
        'connections' => [
            'landlord' => ['driver' => 'pdo_mysql', 'host' => '...', 'dbname' => 'landlord', 'user' => '...', 'password' => '...'],
        ],
        'entity_managers' => [
            'tenant' => ['mapping' => ['paths' => ['src/Tenant/Entity']]],
        ],
        'migrations' => [
            'connections' => [
                'tenant' => ['migrations_paths' => ['Database\\Migrations\\Tenant' => 'database/migrations/tenant']],
            ],
        ],
    ],
    'tenancy' => [
        'resolvers' => [Ssmiff\MezzioTenancy\Resolver\HeaderResolver::class, Ssmiff\MezzioTenancy\Resolver\SubdomainResolver::class],
        'header' => 'X-Tenant',
        'base_domain' => 'example.com',
        'connection_template' => ['driver' => 'pdo_mysql', 'host' => '...', 'user' => '...', 'password' => '...'],
        'required' => true,
    ],
];
```

| Key | Meaning |
| --- | --- |
| `tenancy.resolvers` | resolver service ids, tried in order; the first to name a tenant wins |
| `tenancy.header` | header read by `HeaderResolver` (default `X-Tenant`) |
| `tenancy.base_domain` | `SubdomainResolver` takes the single label below it: `acme.example.com` is tenant `acme`. Unset disables it |
| `tenancy.connection_template` | DBAL parameters shared by every tenant; each tenant's own `params` are merged over them |
| `tenancy.required` | when `true` (default) a request with no tenant gets a 404; when `false` it passes through with no tenant active |

The module itself configures: the `tenant` connection, the shipped landlord migration, and the console listener.

## Setting up the landlord database

```
vendor/bin/laminas migrations:migrate --conn=landlord
vendor/bin/laminas tenancy:create acme "Acme Ltd" --param=dbname=acme_db
vendor/bin/laminas tenancy:list
```

A tenant is a slug (lowercase letters, digits, dashes), a name, an `active` flag and `params`: the DBAL parameters for its database, merged over `connection_template`.
Create the tenant's database itself yourself (SQLite files are created on first use).
Database credentials live in the landlord table, so protect that database accordingly.

## Handling requests

Add `Ssmiff\MezzioTenancy\Http\TenantMiddleware` to the pipeline before routing to tenant routes. For every request it:

1. asks the resolvers for a slug (no slug: 404, or pass through when `required` is `false`),
2. looks the tenant up in the landlord database (unknown or inactive: 404 `{"error":"Unknown tenant"}`),
3. activates it, sets the `Ssmiff\MezzioTenancy\Tenant` request attribute, runs the handler, then deactivates it.

Inject the tenant database like any other, using the attributes from mezzio-container-helpers:

```php
public function __construct(
    #[UseEntityManager('tenant')]
    private EntityManagerInterface $entityManager,
) {}
```

For handlers built once per request (PHP-FPM), the injected entity manager belongs to that request's tenant.
The `tenant` services are not shared in the container, so asking the container again always gives the current tenant's.

### Custom resolvers

Implement `Ssmiff\MezzioTenancy\Resolver\TenantResolver` and add its service id to `tenancy.resolvers`:

```php
final readonly class JwtTenantResolver implements TenantResolver
{
    public function identify(ServerRequestInterface $request): ?string
    {
        return /* a slug from a claim, a session, a path segment... */;
    }
}
```

## Running code for a tenant

```php
$switcher->run($tenant, function (Tenant $tenant) use ($registry) {
    $registry->entityManager('tenant')->persist(...);
});
```

`TenantSwitcher::run()` activates the tenant, runs the callback, and restores whichever tenant (or none) was active before.
Inside the callback, get the entity manager from the `Registry` or the container; an object injected earlier still points at the earlier tenant.

## Console

Every migrations and ORM command gets `--tenant=<slug>`, which implies `--conn=tenant`:

```
vendor/bin/laminas migrations:migrate --tenant=acme
vendor/bin/laminas migrations:diff --tenant=acme
vendor/bin/laminas orm:validate-schema --tenant=acme
```

`--conn=tenant` without `--tenant`, and `--tenant` with another connection, are errors.

| Command | |
| --- | --- |
| `tenancy:create <slug> <name> [--param=key=value ...]` | register a tenant |
| `tenancy:list` | list tenants |
| `tenancy:migrate [--only=<slug>]` | run `migrations:migrate --tenant=<slug>` for each active tenant, one process per tenant |

Tenants are migrated in separate processes because Doctrine Migrations keeps one connection per process.

## Development

```
composer install
composer check
```
