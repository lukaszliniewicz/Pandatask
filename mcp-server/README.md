# Pandatask MCP server

A local, stdio-based Model Context Protocol server for the Pandatask WordPress plugin. It authenticates to the existing Pandatask REST API with a dedicated WordPress Application Password and works with Codex, Antigravity, OpenCode, and other MCP clients that can launch stdio servers.

## Why local stdio

The MCP process runs on the same machine as the agent client, while Pandatask remains the authorization source of truth. The WordPress username and Application Password are sent only to the configured HTTPS WordPress origin. There is no second public service, token database, or remote MCP authentication layer to operate.

Requirements:

- Node.js 20.11 or newer (Node.js 24 is used in CI).
- A production Pandatask WordPress site reachable over HTTPS.
- A dedicated WordPress user with the minimum Pandatask permissions needed.
- A WordPress Application Password created for that user under **Users → Profile → Application Passwords**.

## Install and verify

```powershell
cd mcp-server
npm ci
npm run check
```

The executable is `mcp-server/dist/src/index.js`. Build it before configuring a client. `node mcp-server/dist/src/index.js --version` prints the server version without requiring credentials.

## Configuration

Required environment variables:

| Variable | Meaning |
|---|---|
| `PANDATASK_URL` | HTTPS site URL, including a WordPress subdirectory when applicable |
| `PANDATASK_USERNAME` | WordPress login for the dedicated integration user |
| `PANDATASK_APP_PASSWORD` | WordPress Application Password; display spaces are accepted and removed |

Optional variables:

| Variable | Default | Meaning |
|---|---:|---|
| `PANDATASK_DRY_RUN` | `false` | When true, every mutation returns a preview and no mutation reaches WordPress |
| `PANDATASK_TIMEOUT_MS` | `30000` | REST timeout from 1,000 to 120,000 milliseconds |
| `PANDATASK_TOOL_PROFILE` | `full` | `core` for focused workflows, `full` for all non-admin tools, or `admin` for the complete surface |
| `PANDATASK_MAX_CONCURRENCY` | `5` | Maximum concurrent board reads or independent writes, from 1 to 20 |
| `PANDATASK_MAX_COLLECTION_ITEMS` | `1000` | Safety cap for workflow collection scans, from 50 to 5,000 |
| `PANDATASK_API_BASE_URL` | derived | Override for unusual reverse-proxy or WordPress REST paths |
| `PANDATASK_ALLOW_INSECURE_HTTP` | `false` | Permit HTTP only for a trusted local development site |

Use a per-client environment/secret store where possible. Never add a real app password to the repository, an agent prompt, tool arguments, logs, or screenshots.

### Codex

Build the server, export the four environment variables in the process that launches Codex, then add this to `~/.codex/config.toml` (use an absolute path):

```toml
[mcp_servers.pandatask]
command = "node"
args = ["C:/absolute/path/to/Pandatask/mcp-server/dist/src/index.js"]
env_vars = ["PANDATASK_URL", "PANDATASK_USERNAME", "PANDATASK_APP_PASSWORD", "PANDATASK_DRY_RUN", "PANDATASK_TOOL_PROFILE"]
required = true
startup_timeout_sec = 10.0
tool_timeout_sec = 300.0
```

`PANDATASK_TOOL_PROFILE=core` is the recommended default. Codex also supports `enabled_tools` and `disabled_tools` when a per-client allowlist is preferable. Restart Codex and run `/mcp` or call `connection_check`. See [`examples/codex-config.toml`](examples/codex-config.toml).

### Antigravity

Place a server entry in the workspace `.agents/mcp_config.json` or global `~/.gemini/config/mcp_config.json`:

```json
{
  "mcpServers": {
    "pandatask": {
      "command": "node",
      "args": ["C:/absolute/path/to/Pandatask/mcp-server/dist/src/index.js"],
      "env": {
        "PANDATASK_URL": "https://example.com",
        "PANDATASK_USERNAME": "wordpress-user",
        "PANDATASK_APP_PASSWORD": "replace-with-app-password",
        "PANDATASK_DRY_RUN": "true",
        "PANDATASK_TOOL_PROFILE": "core"
      }
    }
  }
}
```

Use Antigravity's MCP settings to reload and inspect the connection. See [`examples/antigravity-mcp_config.json`](examples/antigravity-mcp_config.json).

### OpenCode

Add a local MCP entry to `opencode.json`. OpenCode's `{env:NAME}` substitution keeps secrets out of the config:

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "pandatask": {
      "type": "local",
      "command": ["node", "C:/absolute/path/to/Pandatask/mcp-server/dist/src/index.js"],
      "enabled": true,
      "environment": {
        "PANDATASK_URL": "{env:PANDATASK_URL}",
        "PANDATASK_USERNAME": "{env:PANDATASK_USERNAME}",
        "PANDATASK_APP_PASSWORD": "{env:PANDATASK_APP_PASSWORD}",
        "PANDATASK_DRY_RUN": "{env:PANDATASK_DRY_RUN}",
        "PANDATASK_TOOL_PROFILE": "{env:PANDATASK_TOOL_PROFILE}"
      }
    }
  }
}
```

See [`examples/opencode.json`](examples/opencode.json).

## Safety model

- WordPress capabilities, BuddyPress membership, board ownership, task access, and comment ownership are still enforced by Pandatask on every REST request.
- HTTPS is mandatory unless insecure HTTP is explicitly enabled for local development.
- Cross-origin redirects are rejected so the Basic Authorization header cannot be redirected to another host.
- The Application Password and WordPress login never appear in MCP results. Both text and `structuredContent` are treated as user-visible.
- Every MCP tool declares `readOnlyHint`, `openWorldHint`, and `destructiveHint` accurately.
- Deletes are marked destructive; archive is offered as the reversible alternative for tasks.
- `PANDATASK_DRY_RUN=true` is a one-way global safety lock. A tool call cannot override it.
- Every write tool also accepts `dry_run: true` for a single-call preview.
- Create operations and administrator batches accept `idempotency_key`. WordPress stores successful keyed responses for 24 hours and safely replays retries; conflicting input or a concurrent in-flight use returns HTTP 409.
- Independent workflow reads and writes use bounded concurrency, and task collections use REST pagination plus a configurable scan cap.
- MCP cancellation signals are propagated to REST requests and reported with a stable `pandatask_request_cancelled` error code.

A dry-run performs local schema and workflow preflight and sends no mutation. WordPress permissions, current record state, and cross-record references remain authoritative during execution. Direct previews contain the exact method, URL, query, JSON body, and non-secret idempotency key that would be used, but never the Authorization header:

```json
{
  "ok": true,
  "data": {
    "dry_run": true,
    "validation_scope": "local_schema",
    "would_execute": {
      "method": "PATCH",
      "url": "https://example.com/wp-json/pandatask/v1/tasks/42",
      "body": { "status": "done" }
    }
  }
}
```

Every tool uses the same stable output envelope:

- Success: `{ "ok": true, "data": ... }`
- Failure or partial workflow: `{ "ok": false, "error": { "code": "...", "message": "...", "http_status": 422, "details": ... } }`

Tool output schemas publish this contract to MCP clients. For compatibility, ordinary results also serialize the envelope into text. Text serialization is replaced by a small pointer above 64 KiB while the complete result remains available in `structuredContent`, preventing unusually large collections from being duplicated in model context.

## Optimized workflows

Use these first to reduce model/tool round trips:

- `board_get_context` — tasks, projects, categories, eligible users, archives, and computed attention summary in one call.
- `board_get_summary` — status and attention buckets.
- `board_deadline_review` — overdue and upcoming work.
- `board_get_workload` — per-assignee load.
- `daily_briefing` — cross-board summary for the authenticated user.
- `project_plan` — create a project plus ordered dependency-aware tasks; dry-run previews the entire plan.
- `task_bulk_update` — update up to 100 tasks with per-item results.
- `task_archive_completed` — reversible board cleanup.
- `batch_execute` — administrator-only mixed-action batch endpoint.

`project_plan` validates the complete dependency graph before creating anything and publishes progress for clients that request it. Its dry-run uses explicit `$project.id` and `$tasks[n].id` placeholders. During execution:

- With `idempotency_key`, successful steps are retained and the identical call/key can safely resume after a transient failure.
- Without a key, `rollback_on_failure=true` deletes tasks created by the workflow and then deletes the project if a later task fails.
- Any incomplete workflow returns an MCP error envelope with bounded partial/rollback details.

Because dependency IDs are resolved sequentially, large plans can take longer than ordinary tools; configure a client tool timeout of at least 300 seconds.

## Tool profiles

The server owns its advertised surface so profiles behave consistently across Codex, Antigravity, OpenCode, and other clients:

- `core` — 19 recommended workflows and common read/write tools.
- `full` — 38 non-administrator granular and workflow tools.
- `admin` — the complete 40-tool surface, including `board_list` and `batch_execute`.

Client-side allow/deny lists can narrow these profiles further.

## Granular tool groups

The `admin` profile exposes 40 tools:

- Connection/directory: `connection_check`, `user_search`.
- Boards/workflows: `board_list`, `board_list_writable`, `board_get_context`, `board_get_summary`, `board_deadline_review`, `board_get_workload`, `daily_briefing`.
- Tasks: `task_list`, `task_get`, `task_get_history`, `task_list_potential_parents`, `task_create`, `task_update`, `task_delete`, `task_set_status`, `task_set_archived`, `task_set_assignments`, `task_set_schedule`, `task_set_dependencies`, `task_create_subtask`, `task_move`, `task_bulk_update`, `task_archive_completed`.
- Projects: `project_list`, `project_get`, `project_create`, `project_update`, `project_delete`, `project_plan`.
- Categories: `category_list`, `category_create`, `category_delete`.
- Comments: `comment_list`, `comment_create`, `comment_update`, `comment_delete`.
- Reporting/admin: `report_get`, `batch_execute`.

The `pandatask://guide` resource and the `plan-my-day` / `launch-project` prompts are also available to clients that expose MCP resources and prompts.

## Board lifecycle note

Pandatask board names are access scopes, not standalone database entities. A standard board becomes discoverable after its first task is created. `group_{ID}` and `user_{ID}` boards follow BuddyPress group and WordPress user lifecycle. Consequently, the MCP server manages board contents and context extensively but does not invent unsupported board rename/delete semantics.

## Development

```powershell
npm run typecheck
npm test
npm run audit:production
```

Tests cover configuration hardening, HTTPS/auth behavior, dry-run network suppression, idempotency headers, deterministic summaries/workload, profile-based discovery, output schemas, project preflight/rollback, private-board briefing scope, bounded concurrency, resources, and prompts. The server writes protocol data only to stdout; operational startup/error messages go to stderr as required for stdio interoperability.

`audit:production` fails for every production advisory except the exact moderate Hono Windows static-file advisory currently inherited through MCP SDK v1. Pandatask is stdio-only and does not import or run that static-file adapter. The exception is intentionally narrow and should be removed when the supported v1 SDK receives an upstream fix.

## Primary references

- [Official MCP TypeScript SDK v1.x](https://github.com/modelcontextprotocol/typescript-sdk/tree/v1.x)
- [OpenAI MCP server guidance](https://developers.openai.com/apps-sdk/build/mcp-server)
- [WordPress Application Password authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- [Antigravity MCP configuration](https://antigravity.google/docs/mcp)
- [OpenCode MCP configuration](https://opencode.ai/docs/mcp-servers/)
