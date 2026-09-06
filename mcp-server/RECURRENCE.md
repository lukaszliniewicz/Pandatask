# Recurring task occurrences

Pandatask stores each generated occurrence as a real task row. A recurring task is therefore actionable in list, board, and summary workflows by default. The task response exposes the stable linkage snapshot fields `recurrence_series_id`, `recurrence_sequence`, and `recurrence_scheduled_start`; `is_recurring` indicates membership in a recurring schedule. Series revision metadata is available through `task_recurrence_get`.

The legacy REST query name `include_templates` is retained for compatibility. Its default is `true`, which includes concrete recurring rows. Set it to `false` when a list workflow needs to exclude recurring members.

## Reading a series

Call `task_recurrence_get` with a task ID. The optional `limit` defaults to 50 and accepts 1 through 100. `before_sequence` requests older occurrences before the supplied positive sequence number.

```json
{
  "task_id": 42,
  "limit": 25,
  "before_sequence": 18
}
```

The REST response is passed through in this shape:

```json
{
  "series": {
    "id": 9,
    "version": 4,
    "active": true,
    "current_task_id": 42,
    "next_start_date": "2026-09-13",
    "can_edit": true,
    "template": {}
  },
  "occurrences": [],
  "has_more": false,
  "next_before_sequence": null
}
```

The endpoint applies the authenticated user’s task read policy. The MCP server does not filter or rewrite the series metadata or occurrence summaries.

## Editing one occurrence or the future

`task_update` and `task_checklist_update` accept `recurrence_scope`, either `this` or `future`. The default is `this`; the MCP sends that field only when the caller supplied it, so existing REST clients keep their ordinary update payload. Historical occurrences can be edited with `this`. A `future` edit applies to the current occurrence and stored future defaults and is accepted only for the latest occurrence.

Every checklist update still requires the task’s `expected_version`. A `future` update also requires the series `expected_series_version`. The REST API validates both revisions. Future checklist defaults are stored with every future item unchecked, while the current occurrence receives the requested checked state.

```json
{
  "task_id": 42,
  "expected_version": 7,
  "items": [
    {"id": "prepare", "text": "Prepare agenda", "checked": true}
  ],
  "recurrence_scope": "future",
  "expected_series_version": 4
}
```

On HTTP 409, fetch the latest task/checklist or series, reconcile the intended change with that state, and submit a new request with the new revision. The MCP never overwrites a stale revision and never automatically retries with a replacement version.

Changing a recurring schedule or future defaults increments the series version. Each successor receives a new task ID, a separate work occurrence, and checklist version 0 with the saved future steps unchecked. The previous task and its checked state remain intact. Reopening it does not create another successor.

## Deleting recurring work

Ordinary task deletion remains physical deletion. For a recurring row, `task_delete` accepts these scopes:

- `this` archives or skips only the selected occurrence. If it is the latest member, the next occurrence is generated.
- `following` preserves the selected and historical rows and stops future generation after the selected occurrence.
- `all` skips the selected occurrence, stops future generation, and preserves the series history. This is a skip-and-stop operation.

There is no MCP tool for manually advancing a series. Normal completion and the server’s recurrence scheduler create successors according to the stored schedule.
