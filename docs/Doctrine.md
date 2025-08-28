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

Schema migrations reside in the `migrations/` directory. Execute them with the
Doctrine migration tool:

```bash
php vendor/bin/doctrine-migrations migrate --configuration=migrations.php --db-configuration=migrations-db.php
```

The command reads migration paths from `migrations.php` and database credentials from `migrations-db.php`.

## Persisting an Account

Account changes are persisted through Doctrine when calling
`Lotgd\Accounts::saveUser()`. This method loads the current `Account` entity,
updates it with session data and flushes the entity manager.

```php
Lotgd\Accounts::saveUser();
```

This keeps the `$session['user']` array in sync with the database using the
entity defined in `src/Lotgd/Entity/Account.php`.
