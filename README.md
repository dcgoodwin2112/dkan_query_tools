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
- `dkan_nl_query` — invokes them from the LLM agentic loop.
- `dkan_drupal_ai_query` — wraps each method in a Drupal AI FunctionCall plugin.

## Requirements

- Drupal 10.2+ or 11
- DKAN (`metastore`, `datastore` modules enabled)

## Installation

Add as a Composer path repo or VCS dependency, then enable:

```bash
drush en dkan_query_tools
```

The three services become available immediately:

```php
$datastore = \Drupal::service('dkan_query_tools.datastore');
$rows = $datastore->queryDatastore(resourceId: 'abc__1700000000', limit: 10);
```

## Tests

```bash
cd web/modules/custom/dkan_query_tools && vendor/bin/phpunit
```

86 unit tests using standalone stubs in `tests/stubs/` (no Drupal bootstrap).
