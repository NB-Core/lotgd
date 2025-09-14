# MySQL Namespace

The `\Lotgd\MySQL` namespace contains the database abstraction layer.  All SQL queries issued by the engine ultimately pass through these classes.  They provide a thin wrapper over PHP's `mysqli` extension while recording statistics and offering convenience helpers.

## Database

`Database` exposes static methods that operate on a singleton `DbMysqli` instance.  Typical usage:

```php
$result = Lotgd\MySQL\Database::query('SELECT * FROM ' . Lotgd\MySQL\Database::prefix('accounts'));
while ($row = Lotgd\MySQL\Database::fetchAssoc($result)) {
    // work with $row
}
```

Important methods include:

- `query()` – execute a SQL statement and track execution time.
- `queryCached()` – wrapper that caches results through `DataCache`.
- `fetchAssoc()` – fetch a single row as an associative array.
- `insertId()` – retrieve the last auto increment value.
- `affectedRows()` – number of rows changed by the last query.
- `prefix()` – prepend the configured table prefix.

The wrapper also stores various counters in the static `$dbinfo` property, such as the total query time. The number of executed queries for the current request can be retrieved via `Database::getQueryCount()`.

## DbMysqli

This class is a lightweight container around `mysqli` providing instance methods like `connect`, `escape` and `tableExists`.  It is normally only used via the higher level `Database` class but can be instantiated directly for custom connections during the installer.

## TableDescriptor

`TableDescriptor` is used by the installer and maintenance scripts to synchronise database schemas.  Given a descriptor array it can generate the SQL needed to create or modify tables.  Modules rarely call this directly unless they need to create additional tables.

### Example – Creating a Table

```php
$desc = [
    'id'   => ['name' => 'id', 'type' => 'int unsigned auto_increment'],
    'name' => ['name' => 'name', 'type' => 'varchar(255)'],
    'key-id' => ['type' => 'primary key', 'columns' => 'id'],
];
Lotgd\MySQL\Database::query(TableDescriptor::tableCreateFromDescriptor('mytable', $desc));
```

The engine uses these tools throughout installation and whenever modules call `synctable()` to ensure their schema is up to date.

### Connection Flow

1. `LocalConfig` loads the database credentials from `config/local.php`.
2. `Database::prefix()` is used by nearly every query to apply the table prefix.
3. When `Database::query()` is called for the first time, it lazily constructs a `DbMysqli` object and connects to the database server.
4. The connection resource is reused for subsequent queries, reducing overhead.

```php
$db = Lotgd\MySQL\DbMysqli::fromDefault();
$db->connect();
```

### Caching Results

`queryCached()` stores the result of SELECT statements in `DataCache` to reduce load. The cache key is based on the SQL string and the specified timeout.

```php
$rows = Lotgd\MySQL\Database::queryCached('SELECT * FROM '.Lotgd\MySQL\Database::prefix('armor'), 3600);
```

### Error Handling

SQL errors throw exceptions with detailed messages from `DbMysqli`. In debug mode `ErrorHandler` will display the failing query along with a stack trace from `Backtrace`.

### Using TableDescriptor

Modules may ship schema descriptions for their tables. `TableDescriptor::schematize()` converts descriptor arrays into CREATE or ALTER statements during `install.php`.

Modules that define their own tables **must** call `TableDescriptor::synctable()` during install and upgrade routines. This keeps schemas in sync and guarantees the `utf8mb4` charset and `utf8mb4_unicode_ci` collation are applied.

#### Detecting default values

`TableDescriptor::tableCreateDescriptor()` reads column metadata using
`DESCRIBE` and stores each column's default value in the descriptor under the
`default` key. Defaults such as `'0'` are preserved, and an explicit `NULL`
default results in `default => null` so schema updates generate a `DEFAULT NULL`
clause when required.

### Collation and Encoding

All core tables are created using the `utf8mb4` character set and `utf8mb4_unicode_ci` collation.
`synctable()` now enforces the `utf8mb4` character set and
`utf8mb4_unicode_ci` collation for tables and columns by default. This
combination supports the full Unicode range, including emoji, and in
practice is the only sensible choice. Other collations may break foreign
text or symbols, so only override them when you fully understand the
consequences:

```php
$desc = [
    // Apply a different charset and collation to the whole table (discouraged)
    'charset'   => 'latin1',
    'collation' => 'latin1_swedish_ci',
    'id'   => ['name' => 'id', 'type' => 'int unsigned auto_increment'],
    // Column‑specific override back to utf8mb4
    'name' => [
        'name' => 'name',
        'type' => 'varchar(255)',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'key-id' => ['type' => 'primary key', 'columns' => 'id'],
];
synctable('mytable', $desc);
```

Both `charset` and `collation` keys can appear at the top level to affect the
entire table or within individual column definitions to override the defaults.
If you omit `charset` but provide `collation`, the engine will infer the
character set from the collation name automatically. If only `charset` is
provided, `synctable()` assumes `<charset>_unicode_ci` when supported or falls
back to the server's default collation for that charset. Unsupported
combinations must be specified explicitly. Because `utf8mb4_unicode_ci` sorts
and compares virtually all scripts and emoji correctly, choosing a different
collation is rarely sensible. If you change it, be certain you understand why.

Ensure that the application's output encoding matches the database encoding to
avoid memory errors when strings are converted between PHP and MySQL. The engine
always outputs UTF-8, so configure your database with UTF-8 compatible encodings
such as `utf8mb4`.

`synctable()` and `TableDescriptor::tableCreateFromDescriptor()` validate the
provided charset and collation. They throw an `InvalidArgumentException` for
unknown or incompatible pairs, and a `RuntimeException` if SQL execution fails.
Installation scripts can catch these exceptions to surface clearer error
messages:

```php
try {
    synctable('mytable', $desc);
} catch (\InvalidArgumentException $e) {
    // unsupported charset/collation
} catch (\RuntimeException $e) {
    // SQL query failed
}
```

## Database Migrations

The project uses [Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html) to manage schema changes. The migration classes live in the `migrations/` directory and are configured through `src/Lotgd/Config/migrations.php` and `src/Lotgd/Config/migrations-db.php`.

`src/Lotgd/Config/migrations.php` defines the migration paths. With a single database connection it contains only `migrations_paths` and all connection parameters reside in `src/Lotgd/Config/migrations-db.php`. If multiple connections are required, add a `connection` key in `src/Lotgd/Config/migrations.php` and return an array of credentials keyed by name from `src/Lotgd/Config/migrations-db.php`.

### Running Migrations

Execute pending migrations with the Doctrine command line tool:

```bash
php bin/doctrine migrations:migrate
```

The command uses the default `src/Lotgd/Config/migrations.php` and `src/Lotgd/Config/migrations-db.php` files. If your configuration lives elsewhere, provide their paths explicitly:

```bash
php bin/doctrine \
    --configuration=src/Lotgd/Config/migrations.php \
    --db-configuration=src/Lotgd/Config/migrations-db.php migrations:migrate
```

This will apply all new migrations to the configured database. During development you can generate additional migrations using `migrations:diff` or `migrations:generate`.

## Session Persistence

`Lotgd\Accounts::saveUser()` now persists account updates through Doctrine when the
EntityManager is available.  The `$session['user']` array is still populated for
legacy code, but changes are flushed to the database via Doctrine whenever
possible.


