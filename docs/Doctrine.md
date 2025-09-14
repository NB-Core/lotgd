# Doctrine Namespace

The `\Lotgd\Doctrine` namespace integrates Doctrine ORM into the game. It reads
connection settings from `dbconnect.php` and exposes a helper to create an
`EntityManager` for database operations.

## Obtaining an EntityManager

Call `Lotgd\Doctrine\Bootstrap::getEntityManager()` to create a configured
`EntityManager` instance. This method sets up annotation metadata and caching
based on your database configuration.

```php
use Lotgd\Doctrine\Bootstrap;

$entityManager = Bootstrap::getEntityManager();
```

## Entities and Repositories

Entity classes live under `src/Lotgd/Entity`. Each entity usually has a
corresponding repository class in `src/Lotgd/Repository`. You can retrieve these
repositories from the entity manager.

```php
$repo = $entityManager->getRepository(Lotgd\Entity\Account::class);
```

## Running Migrations

Schema migrations reside in the `migrations/` directory and are configured
through two files stored in `src/Lotgd/Config/`:

* `src/Lotgd/Config/migrations.php` – defines migration paths. When using a single
  connection no additional options are required. For multiple connections you
  may specify a `connection` key to select which database configuration to use.
* `src/Lotgd/Config/migrations-db.php` – provides the database credentials. With one
  connection it returns the parameters directly. To support multiple connections
  return an array keyed by connection name.

In a single connection setup `src/Lotgd/Config/migrations.php` only lists
`migrations_paths` and `src/Lotgd/Config/migrations-db.php` contains the connection
details:

```php
<?php
// src/Lotgd/Config/migrations.php
return [
    'migrations_paths' => [
        'Lotgd\\Migrations' => dirname(__DIR__, 3) . '/migrations',
    ],
];
```

```php
<?php
// src/Lotgd/Config/migrations-db.php
return [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'lotgd',
    'user' => 'lotgd_user',
    'password' => 'secret',
    'charset' => 'utf8mb4',
];
```

If you need more than one connection, add a `connection` key in
`src/Lotgd/Config/migrations.php` and return an array of credentials keyed by name from
`src/Lotgd/Config/migrations-db.php`.

Run pending migrations:

```bash
php bin/doctrine migrations:migrate
```

The command automatically reads `src/Lotgd/Config/migrations.php` and
`src/Lotgd/Config/migrations-db.php`. If you store them elsewhere, pass the paths
explicitly:

```bash
php bin/doctrine \
    --configuration=src/Lotgd/Config/migrations.php \
    --db-configuration=src/Lotgd/Config/migrations-db.php migrations:migrate
```

### Upgrade Notes

Earlier versions used a single `config/doctrine.php`. Replace it with the split
configuration above and run `php bin/doctrine migrations:migrate` when
upgrading.

## Persisting an Account

Account changes are persisted through Doctrine when calling
`Lotgd\Accounts::saveUser()`. This method loads the current `Account` entity,
updates it with session data and flushes the entity manager.

```php
Lotgd\Accounts::saveUser();
```

This keeps the `$session['user']` array in sync with the database using the
entity defined in `src/Lotgd/Entity/Account.php`.
