# Doctrine DBAL 4 Migration Guide Notes

This summary is based on the Doctrine DBAL 4 migration guide (`UPGRADE.md`) from the upstream Doctrine DBAL repository.

## Breaking changes called out for DBAL 4.0

* Removed legacy execute and fetch APIs: `Result::fetch()`, `Result::fetchAll()`, `Connection::exec()`, `Connection::executeUpdate()`, and `Connection::query()`. The `FetchMode` class is also removed.
* Removed lock-related platform helpers: `AbstractPlatform::getReadLockSQL()`, `getWriteLockSQL()`, and `getForUpdateSQL()`. Use `QueryBuilder::forUpdate()` instead of `getForUpdateSQL()`.
* Removed `AbstractMySQLPlatform::getColumnTypeSQLSnippets()` and `getDatabaseNameSQL()`.
* BIGINT values are now cast to `int` when they fit within PHP's integer range (previously always `string`).
* `DateTime`-related type changes: mutable `DateTime` types no longer accept or return `DateTimeImmutable`; `*ImmutableType` classes no longer extend the mutable types.
* The `url` connection parameter is removed. Use `DsnParser` to parse database URLs for `DriverManager` instead.
* Removed `Connection::PARAM_*_ARRAY` constants in favor of the `ArrayParameterType` enum.
* `serverVersion` must include full `major.minor.patch` numbers; partial versions are rejected.
* MariaDB `serverVersion` values must not be prefixed with `mariadb-` anymore.
* `SchemaDiff::$orphanedForeignKeys` support is removed.
* DBAL no longer registers SQLite user-defined functions (`locate`, `mod`, `sqrt`) or the `userDefinedFunctions` driver option; register functions on the native SQLite connection instead.
