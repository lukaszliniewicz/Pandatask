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
npm test
npm run build
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
| `PANDATASK_API_BASE_URL` | derived | Override for unusual reverse-proxy or WordPress REST paths |
| `PANDATASK_ALLOW_INSECURE_HTTP` | `false` | Permit HTTP only for a trusted local development site |

Use a per-client environment/secret store where possible. Never add a real app password to the repository, an agent prompt, tool arguments, logs, or screenshots.

### Codex

Build the server, export the four environment variables in the process that launches Codex, then add this to `~/.codex/config.toml` (use an absolute path):

```toml
[mcp_servers.pandatask]
command = "node"
args = ["C:/absolute/path/to/Pandatask/mcp-server/dist/src/index.js"]
env_vars = ["PANDATASK_URL", "PANDATASK_USERNAME", "PANDATASK_APP_PASSWORD", "PANDATASK_DRY_RUN"]
required = true
startup_timeout_sec = 10.0
tool_timeout_sec = 60.0
```

Restart Codex and run `/mcp` or call `connection_check`. See [`examples/codex-config.toml`](examples/codex-config.toml).

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
        "PANDATASK_DRY_RUN": "true"
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
        "PANDATASK_DRY_RUN": "{env:PANDATASK_DRY_RUN}"
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
- Credentials never appear in MCP results. Both text and `structuredContent` are treated as user-visible.
- Every MCP tool declares `readOnlyHint`, `openWorldHint`, and `destructiveHint` accurately.
- Deletes are marked destructive; archive is offered as the reversible alternative for tasks.
- `PANDATASK_DRY_RUN=true` is a one-way global safety lock. A tool call cannot override it.
- Every write tool also accepts `dry_run: true` for a single-call preview.

A dry-run response contains the exact method, URL, query, and JSON body that would be sent, but never the Authorization header:

```json
{
  "dry_run": true,
  "would_execute": {
    "method": "PATCH",
    "url": "https://example.com/wp-json/pandatask/v1/tasks/42",
    "body": { "status": "done" }
  }
}
```

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

`project_plan` is intentionally sequential because later dependency IDs are resolved from earlier create responses. If a step fails, the result reports partial progress and stops; it does not pretend to provide a database transaction across independent REST calls.

## Granular tool groups

The server exposes 40 tools:

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
npm audit
```

Tests cover configuration hardening, HTTPS/auth behavior, dry-run network suppression, WordPress error conversion, summaries/workload, tool discovery, annotations, resources, and prompts. The server writes protocol data only to stdout; operational startup/error messages go to stderr as required for stdio interoperability.

## Primary references

- [Official MCP TypeScript SDK server guide](https://github.com/modelcontextprotocol/typescript-sdk/blob/main/docs/server.md)
- [OpenAI MCP server guidance](https://developers.openai.com/apps-sdk/build/mcp-server)
- [WordPress Application Password authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
- [Antigravity MCP configuration](https://antigravity.google/docs/mcp)
- [OpenCode MCP configuration](https://opencode.ai/docs/mcp-servers/)
