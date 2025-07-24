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

The wrapper also stores various counters in the static `$dbinfo` property, such as `queriesthishit` and the total query time.

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

## Database Migrations

The project uses [Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html) to manage schema changes. The migration classes live in the `migrations/` directory defined in `config/doctrine.php`.

### Running Migrations

Execute pending migrations with the Doctrine command line tool:

```bash
vendor/bin/doctrine-migrations migrations:migrate
```

This will apply all new migrations to the configured database. During development you can generate additional migrations using `migrations:diff` or `migrations:generate`.


