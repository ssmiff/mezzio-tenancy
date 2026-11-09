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

- The **landlord** database holds the list of tenants. It is a normal mezzio-doctrine connection, called `landlord` unless you set `tenancy.landlord_connection`, which you define. Tenants are plain `Tenant` value objects read and written with DBAL by `DbalTenantRepository`; no ORM entity or entity manager is involved. Replace it by providing your own `TenantRepository`.
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
        'landlord_connection' => 'landlord',
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
| `tenancy.landlord_connection` | the `doctrine.connections` entry holding the tenants table (default `landlord`) |
| `tenancy.resolvers` | resolver service ids, tried in order; the first to name a tenant wins |
| `tenancy.header` | header read by `HeaderResolver` (default `X-Tenant`) |
| `tenancy.base_domain` | `SubdomainResolver` takes the single label below it: `acme.example.com` is tenant `acme`. Unset disables it |
| `tenancy.connection_template` | DBAL parameters shared by every tenant; each tenant's own `params` are merged over them |
| `tenancy.required` | when `true` (default) a request with no tenant gets a 404; when `false` it passes through with no tenant active |

The module itself configures: the `tenant` connection, the shipped landlord migration, and the console listener.

### Another landlord connection name

Name the connection what you like and point `tenancy.landlord_connection` at it. The landlord migrations follow the
setting, so there is nothing else to register:

```php
return [
    'doctrine' => [
        'connections' => [
            'central' => ['driver' => 'pdo_pgsql', 'host' => '...', 'dbname' => 'central', 'user' => '...', 'password' => '...'],
        ],
    ],
    'tenancy' => [
        'landlord_connection' => 'central',
    ],
];
```

```
vendor/bin/laminas migrations:migrate --conn=central
```

Migrations of your own for that connection go under `doctrine.migrations.connections.central.migrations_paths` as usual;
the landlord migrations are added to them, not replaced.

## Setting up the landlord database

```
vendor/bin/laminas migrations:migrate --conn=landlord      # or your landlord_connection
vendor/bin/laminas tenancy:create acme "Acme Ltd" --param=dbname=acme_db
vendor/bin/laminas tenancy:list
```

A tenant is a slug (lowercase letters, digits, dashes), a name, an `active` flag and `params`: the DBAL parameters for its database, merged over `connection_template`.
Without a provisioner, create the tenant's database yourself (SQLite files are created on first use).
Database credentials live in the landlord table, so protect that database accordingly.

## Provisioning tenant storage

Set `tenancy.provisioner` and `tenancy:create` creates each tenant's storage, named `<prefix><slug>` (dashes become
underscores), and saves the parameters that point at it; `tenancy:delete --drop` destroys it. Three provisioners ship:

**A database per tenant** (MySQL, MariaDB, PostgreSQL). `CREATE DATABASE tenant_acme`; the tenant gets `dbname`:

```php
'tenancy' => [
    'provisioner'             => Ssmiff\MezzioTenancy\Provisioning\DatabaseProvisioner::class,
    'prefix'                  => 'tenant_',
    'provisioning_connection' => 'admin', // a user allowed to CREATE/DROP DATABASE; null uses the landlord connection
    'connection_template'     => ['driver' => 'pdo_pgsql', 'host' => 'db', 'user' => 'app', 'password' => '...'],
],
```

**A schema per tenant** (PostgreSQL). `CREATE SCHEMA tenant_acme` in one database; the tenant gets a `search_path`
of its schema, so its tables and migrations version table live there. `provisioning_connection` and
`connection_template` both point at that database:

```php
'tenancy' => [
    'provisioner'         => Ssmiff\MezzioTenancy\Provisioning\SchemaProvisioner::class,
    'connection_template' => ['driver' => 'pdo_pgsql', 'host' => 'db', 'dbname' => 'app', 'user' => 'app', 'password' => '...'],
],
```

**An SQLite file per tenant**. `data/tenants/tenant_acme.sqlite`; the tenant gets `path`:

```php
'tenancy' => [
    'provisioner'         => Ssmiff\MezzioTenancy\Provisioning\SqliteFileProvisioner::class,
    'sqlite_directory'    => 'data/tenants', // relative to doctrine.base_path
    'connection_template' => ['driver' => 'pdo_sqlite'],
],
```

Then:

```
vendor/bin/laminas tenancy:create acme "Acme Ltd"        # creates tenant_acme and registers it
vendor/bin/laminas tenancy:migrate --only=acme           # builds its tables
vendor/bin/laminas tenancy:delete acme                   # unregisters it; the data stays
vendor/bin/laminas tenancy:delete acme --drop            # unregisters it and destroys tenant_acme (asks for the slug)
```

- `tenancy:create --no-provision` registers the tenant under the same name without creating anything, for storage that
  already exists. `--param` adds or overrides parameters; one that would point away from the created storage needs
  `--no-provision`.
- `--drop` asks you to type the slug, or takes `--force` (required when not interactive). It only destroys a tenant
  whose saved parameters still match what the provisioner would create for it; anything else is refused.
- If registering fails after the storage was created, the storage is dropped again.

### Your own provisioner

Implement `Ssmiff\MezzioTenancy\Provisioning\TenantProvisioner` (`params()`, `create()`, `drop()`), register it as a
service and name it in `tenancy.provisioner`. `TenantName::for($prefix, $slug)` gives the usual safe name:

```php
final readonly class ManagedDatabaseProvisioner implements TenantProvisioner
{
    public function __construct(private CloudDatabases $api, private string $prefix = 'tenant_') {}

    public function params(string $slug): array
    {
        return ['dbname' => TenantName::for($this->prefix, $slug)];
    }

    public function create(string $slug): void
    {
        $this->api->createDatabase(TenantName::for($this->prefix, $slug));
    }

    public function drop(string $slug): void
    {
        $this->api->deleteDatabase(TenantName::for($this->prefix, $slug));
    }
}
```

| Key | Meaning |
| --- | --- |
| `tenancy.provisioner` | service id of the `TenantProvisioner`; `null` (default) for none |
| `tenancy.prefix` | put in front of the slug in storage names (default `tenant_`); lowercase letters, digits, `_` |
| `tenancy.provisioning_connection` | connection the database and schema provisioners run DDL on (default: the landlord connection) |
| `tenancy.sqlite_directory` | where `SqliteFileProvisioner` puts files, relative to `doctrine.base_path` (default `data/tenants`) |

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
| `tenancy:create <slug> <name> [--param=key=value ...] [--no-provision]` | register a tenant and, with a provisioner, create its storage |
| `tenancy:delete <slug> [--drop [--force]]` | unregister a tenant; with `--drop`, destroy its storage |
| `tenancy:list` | list tenants |
| `tenancy:migrate [--only=<slug>]` | run `migrations:migrate --tenant=<slug>` for each active tenant, one process per tenant |

Tenants are migrated in separate processes because Doctrine Migrations keeps one connection per process.

## Development

```
composer install
composer check
```
