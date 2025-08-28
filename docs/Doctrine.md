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
through two files:

* `migrations.php` – defines migration paths and the **connection name**.
* `migrations-db.php` – provides database credentials keyed by connection name.

In `migrations.php` the `connection` value is only a label. The actual
credentials belong in `migrations-db.php`:

```php
<?php
// migrations.php
return [
    'connection' => 'lotgd',
    'migrations_paths' => [
        'Lotgd\\Migrations' => __DIR__ . '/../migrations',
    ],
];
```

```php
<?php
// migrations-db.php
return [
    'lotgd' => [
        'driver' => 'pdo_mysql',
        'host' => 'localhost',
        'dbname' => 'lotgd',
        'user' => 'lotgd_user',
        'password' => 'secret',
        'charset' => 'utf8mb4',
    ],
];
```

Run pending migrations:

```bash

php vendor/bin/doctrine-migrations migrate
```

The command automatically reads `migrations.php` and `migrations-db.php` from
the project root. If you store them elsewhere, pass the paths explicitly:

```bash
php vendor/bin/doctrine-migrations \
    --configuration=config/migrations.php \
    --db-configuration=config/migrations-db.php migrate
```

### Upgrade Notes

Earlier versions used a single `config/doctrine.php`. Replace it with the split
configuration above and run `php vendor/bin/doctrine-migrations migrate` when
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
