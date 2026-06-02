# DKAN Query Tools

Shared library of DKAN catalog and datastore query tool classes used by AI-agent and MCP-server modules.

Provides three Drupal services that wrap DKAN's metastore, datastore, and search APIs in agent-friendly method signatures:

| Service ID | Class | Purpose |
|---|---|---|
| `dkan_query_tools.metastore` | `MetastoreTools` | List/get datasets and distributions |
| `dkan_query_tools.datastore` | `DatastoreTools` | Query datastore tables, schema, stats, joins, column search |
| `dkan_query_tools.search` | `SearchTools` | Keyword search via DKAN's `/api/1/search` endpoint |

## Consumers

- `dkan_mcp` — exposes these methods as MCP tools.
- `dkan_drupal_ai_query` — wraps each method in a Drupal AI FunctionCall plugin.
- `dkan_nl_query` — _deprecated_ in favor of `dkan_drupal_ai_query`; previously invoked these from a bespoke LLM agentic loop.

## Requirements

- Drupal 10.2+ or 11
- DKAN (`metastore`, `datastore` modules enabled)

## Installation

1. Add as a Composer dependency. Use either a VCS repository (recommended for shared sites) or a local path repo (for development inside this monorepo):

   **VCS:**
   ```json
   {
     "repositories": {
       "dkan_query_tools": {
         "type": "vcs",
         "url": "https://github.com/dcgoodwin2112/dkan_query_tools.git"
       }
     },
     "require": {
       "dcgoodwin2112/dkan_query_tools": "dev-main"
     }
   }
   ```

   **Path repo:**
   ```json
   {
     "repositories": {
       "dkan_query_tools_local": {
         "type": "path",
         "url": "./docroot/modules/custom/dkan_query_tools",
         "options": { "symlink": true }
       }
     },
     "require": {
       "dcgoodwin2112/dkan_query_tools": "@dev"
     }
   }
   ```

2. Resolve and install:

   ```bash
   composer update dcgoodwin2112/dkan_query_tools
   ```

3. Enable the module:

   ```bash
   drush en dkan_query_tools
   ```

The three services become available immediately:

```php
$datastore = \Drupal::service('dkan_query_tools.datastore');
$rows = $datastore->queryDatastore(resourceId: 'abc__1700000000', limit: 10);
```

## Selected methods

`DatastoreTools` carries the bulk of the query surface. The full method
list is in [src/Tool/DatastoreTools.php](src/Tool/DatastoreTools.php); the
ones that matter for agent-style consumers:

| Method | Purpose |
|---|---|
| `queryDatastore()` | Structured query (filters, sort, pagination, aggregation). Returns rows + `sanity_flags`. |
| `queryDatastoreJoin()` | Two-resource join with the same response shape. |
| `getDatastoreSchema()` | Field names and types for one resource. Per-column `dictionary_title` / `dictionary_description` / `dictionary_type` and root-level `dictionary_url` are merged in when the distribution links a data dictionary. Pass `includeDictionary: false` to skip the lookup. |
| `getDataDictionary()` | On `MetastoreTools`. Resolve a dataset UUID or `resource_id` to its linked data dictionary item(s); returns curated field titles, descriptions, and declared types. |
| `getDatastoreStats()` | Min/max/null/distinct stats per column. |
| `sampleRows()` | Deterministic first-N rows for grounding. |
| `distinctValues()` | Code-list discovery for one column. |
| `searchColumns()` | Catalog-wide column search (on `SearchTools`). |

`queryDatastore()` and `queryDatastoreJoin()` never throw on
user-driven errors — they return structured `error` payloads that an
agent can read and self-correct from. See
[docs/tool-responses.md](docs/tool-responses.md) for the full contract.

## Documentation

- [docs/tool-responses.md](docs/tool-responses.md) — success / error /
  sanity-flag shapes returned by `DatastoreTools` query methods.
- [docs/database-roles.md](docs/database-roles.md) — read-only MariaDB
  role for agent query execution.

## Tests

Use the module-local PHPUnit runner:

```bash
cd docroot/modules/custom/dkan_query_tools && vendor/bin/phpunit
```

Unit tests use standalone stubs in `tests/stubs/` (no Drupal bootstrap). The
test bootstrap registers only this module's PSR-4 namespaces — it does not load
the module's `vendor/autoload.php` — so the suite also runs under the site-level
PHPUnit binary (`../../../../vendor/bin/phpunit`) without mixing PHPUnit major
versions.
