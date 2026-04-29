# DatastoreTools response shapes

`DatastoreTools::queryDatastore()` and `queryDatastoreJoin()` return
structured arrays — never throw on user-driven errors — so LLM agent
consumers can read the result and self-correct in the next iteration. This
doc is the contract.

## Success response

```json
{
  "results": [
    {"city": "Houston", "violent_crimes": 22008}
  ],
  "result_count": 1,
  "total_rows": 1,
  "limit": 100,
  "offset": 0,
  "sanity_flags": {
    "zero_rows": false,
    "all_null_columns": [],
    "row_cap_hit": false,
    "coverage_warning": null
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `results` | array<object> | Rows returned by `DatastoreQuery`. |
| `result_count` | int | `count(results)` after pagination. |
| `total_rows` | int | Matching row count reported by DKAN (pre-limit). |
| `limit` | int | The clamped row cap (`min(max($limit, 1), 500)`). |
| `offset` | int | Pagination offset passed through. |
| `sanity_flags` | object | See "Sanity flags" below. |

`queryDatastoreJoin()` returns the same shape; the join is opaque to the
caller.

## Sanity flags

Every result carries a `sanity_flags` block. The dkan_drupal_ai_query
prompt requires the agent to acknowledge any non-default flag in its final
answer; downstream UIs render them in the provenance panel.

| Flag | Type | Set when |
|---|---|---|
| `zero_rows` | bool | `result_count === 0`. |
| `all_null_columns` | string[] | Columns where every returned row has a NULL or empty value. Empty when `results` is empty (would falsely flag every column). |
| `row_cap_hit` | bool | `result_count >= limit && total_rows > result_count` — the answer is partial. |
| `coverage_warning` | string\|null | Free-text warning when a date-like column was filtered and produced zero rows. Suggests calling `getDatastoreStats` to verify the dataset's coverage window. |

Implementation lives in `DatastoreTools::buildSuccessResponse()`; the
coverage heuristic in `maybeBuildCoverageWarning()`.

## Error responses

Errors are returned as values, not exceptions. The two shapes:

### Unknown column (self-correctable)

```json
{
  "error": "unknown_column",
  "column": "violent_crime_count",
  "available_columns": ["city", "violent_crimes", "homicides", "..."],
  "resource_id": "abc__1700000000",
  "message": "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'violent_crime_count' in 'field list'"
}
```

Returned when MySQL or DKAN's QueryFactory complains about a missing
column. `available_columns` lets the agent retry with a real name in the
next iteration. Pattern matching lives in
`DatastoreTools::extractUnknownColumn()` and covers MySQL's
`Unknown column 'X'`, generic `column 'X' does not exist`, and DKAN's
`Bad query property` (the column name is not in the message; falls back
to `"(unknown)"`).

In `dkan_drupal_ai_query` an event subscriber
(`UnknownColumnGuardSubscriber`) counts these per agent turn and trips a
structured refusal at the third one. See that module's
[refusal-flow.md](../../dkan_drupal_ai_query/docs/refusal-flow.md).

### Generic error

```json
{
  "error": "<exception message>",
  "resource_id": "abc__1700000000"
}
```

Catch-all for anything that is not an unknown-column. Surfaces the raw
exception message; the agent typically refuses or retries with different
parameters.

## Why return values, not exceptions

The Drupal AI agents loop only sees what a `FunctionCall` plugin writes
to its tool output. Thrown exceptions surface to the controller, not the
model. Returning a structured payload via `setOutput()` is the only path
that lets the agent observe and react to the failure mode.

This is a tool-layer contract, not a `dkan_query_tools` invariant. A
plain PHP caller can still throw if it prefers; that's the caller's
choice. But the production callers (MCP server tool wrappers, Drupal AI
FunctionCall plugins) all want the structured form.

## Phase 2 introspection methods

Two zero-arg-friendly methods added in the accuracy effort, also
returning plain arrays:

- `sampleRows(string $resourceId, int $n = 5): array` — first N rows
  after a deterministic sort on column 1. Reproducible (not random) so
  eval runs are stable.
- `distinctValues(string $resourceId, string $column, int $limit = 50): array`
  — `{values: [...], truncated: bool}`. Lets the agent discover code
  lists before issuing a filter.

Errors from these follow the same generic-error shape above.

## See also

- [database-roles.md](database-roles.md) — read-only MariaDB role for
  query execution.
- The `dkan_drupal_ai_query` module's [provenance doc](../../dkan_drupal_ai_query/docs/provenance.md)
  for how a downstream consumer renders these flags to end users.
