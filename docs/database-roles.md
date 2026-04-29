# Read-only datastore database role

`DatastoreTools::queryDatastore()` and `queryDatastoreJoin()` are exposed to
LLM agents through `dkan_drupal_ai_query`. The DKAN `DatastoreQuery` DSL is
read-only by construction — it cannot express INSERT/UPDATE/DELETE — but
defense-in-depth says do not hand the agent a connection that *could*
mutate the datastore tables, even via a future bug or new tool.

This document describes the recommended database role and how to wire it
into Drupal. The current code uses Drupal's default connection; switching
to a read-only role is opt-in via `settings.php` and incurs no code
change inside `DatastoreTools` itself.

## Recommended MariaDB role

Create a separate MariaDB user with `SELECT` only on `datastore_*` tables
in the application database. Run as a privileged DB user:

```sql
CREATE USER 'drupal_datastore_read'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON `dkan`.`datastore_%` TO 'drupal_datastore_read'@'%';
-- Schema introspection over information_schema is needed by Drupal to
-- inspect column types; SELECT on the system schema is read-only.
GRANT SELECT ON `information_schema`.* TO 'drupal_datastore_read'@'%';
FLUSH PRIVILEGES;
```

Replace `dkan` with the actual database name. The `datastore_%` wildcard
matches every imported resource table (DKAN names them
`datastore_<md5>`). No grant on any other table — the role cannot
read user data, configuration, or content entities.

To verify the grant is working, log in as the role and try a write:

```sql
mysql -u drupal_datastore_read -p dkan
> INSERT INTO datastore_<md5> (record_number) VALUES (0);
ERROR 1142 (42000): INSERT command denied to user 'drupal_datastore_read'
```

## Drupal settings.php wiring

Add a second connection to `$databases` in `sites/default/settings.php`
(or `settings.local.php` for local dev):

```php
$databases['datastore_read']['default'] = [
  'driver'    => 'mysql',
  'database'  => 'dkan',
  'username'  => 'drupal_datastore_read',
  'password'  => '<strong-password>',
  'host'      => 'db',
  'port'      => '3306',
  'prefix'    => '',
  'collation' => 'utf8mb4_general_ci',
  // Statement timeout: cap any agent-driven query to 10 seconds. Tune for
  // your dataset volumes; lower is safer.
  'init_commands' => [
    'sql_mode' => "SET SESSION sql_mode = 'ANSI,STRICT_ALL_TABLES'",
    'max_execution_time' => "SET SESSION max_statement_time = 10",
  ],
];
```

`datastore_read` is the connection key the service container will look
for (when wired). Until the wiring lands the default connection is used,
which is fine for local development but should not be relied on in a
multi-tenant or production environment.

## Other layers already in place

- **Row cap.** `DatastoreTools::queryDatastore()` clamps `limit` to
  `min(max($limit, 1), 500)`. The 500 ceiling is intentional and should
  not be raised; a result above that returns `sanity_flags.row_cap_hit:
  true` so the agent knows the answer is partial.
- **Statement timeout.** Set per the `init_commands` block above; do not
  rely on the global server setting since DDEV / shared databases often
  default it to unlimited.
- **DSL surface.** No raw SQL is exposed. Every query the agent issues
  passes through `DatastoreQuery`, which validates the structured
  payload before compiling SQL.
- **Unknown column self-correction.** When a query references a column
  the table doesn't have, the response is wrapped as
  `{error: unknown_column, available_columns: [...], ...}` so the agent
  can pick the right name on the next iteration. After 3 consecutive
  unknown-column errors in one turn,
  `UnknownColumnGuardSubscriber` short-circuits the loop into a
  `repeated_unknown_column` refusal.

## Future work

Wiring `DatastoreTools` to switch connections (something like
`Database::getConnection('default', 'datastore_read')` for query
execution while keeping schema introspection on `default`) is left for
a follow-up. The grants + settings.php documented here are sufficient
to operate the read-only role manually today.
