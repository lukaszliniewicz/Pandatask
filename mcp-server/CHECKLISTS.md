# Task checklists through MCP

Pandatask checklists are small ordered steps attached to a task. Checklist item
completion is independent from the task's workflow status and time tracking.
Use the dedicated MCP tools rather than putting checklist data in generic
`task_create` or `task_update` payloads.

## Read a checklist

Call `task_checklist_get` with a positive `task_id`:

```json
{
  "task_id": 42
}
```

The successful `data` value contains the complete ordered checklist and its
revision metadata:

```json
{
  "checklist": [
    { "id": "prepare", "text": "Prepare agenda", "checked": true },
    { "id": "send-notes", "text": "Send notes", "checked": false }
  ],
  "checklist_version": 3,
  "checklist_total": 2,
  "checklist_checked": 1,
  "can_edit_checklist": true
}
```

`task_get` also returns these fields when the task response includes checklist
data. `checklist`, `checklist_version`, `checklist_total`, and `checklist_checked`
are available in the `task_list` `fields` projection. Editability is returned
on task details and dedicated checklist responses.

## Replace a checklist

`task_checklist_update` replaces the complete ordered state in one request.
Send the revision returned by `task_checklist_get` or `task_get` as
`expected_version`:

```json
{
  "task_id": 42,
  "expected_version": 3,
  "items": [
    { "id": "prepare", "text": "Prepare agenda", "checked": true },
    { "text": "Send notes", "checked": false }
  ],
  "idempotency_key": "checklist-42-v3-a",
  "response_mode": "full"
}
```

Each item has a trimmed 1-500 character `text` and a boolean `checked` value.
An existing item `id` is stable; omit `id` for a new item and the server will
assign one. Array order controls display order. Any existing ID omitted from
the replacement is deleted, and an empty `items` array clears the checklist.
At most 100 items are accepted, and duplicate or malformed IDs are rejected.

The successful response has the same checklist shape as the read response. An
executed mutation uses the normal MCP minimal response by default, which keeps
`task_id`, `checklist_version`, `checklist_total`, `checklist_checked`, and
`can_edit_checklist` so a client can identify the new revision. Set
`response_mode` to `full` when the updated item list is needed. `dry_run=true`
returns the planned POST without sending it. An `idempotency_key` is forwarded
using the normal MCP mutation convention.

## Revision conflicts and recurrence

The server compares `expected_version` atomically. If another writer changed
the checklist first, the update returns HTTP 409. Do not retry with a guessed
or automatically substituted version: fetch the latest checklist, reconcile
the intended change with that state, and submit a new explicit update.

Each recurring occurrence has its own task record and checklist version. The
next task starts at version 0 with the saved future steps unchecked; the earlier
task's checklist stays unchanged. To update future steps, send
`recurrence_scope: "future"` and `expected_series_version` from
`task_recurrence_get`, together with the checklist's `expected_version`.
See [recurrence semantics](RECURRENCE.md).
