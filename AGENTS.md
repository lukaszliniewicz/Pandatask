# Pandatask Agent Notes

This repository inherits the guidance from the surrounding IARF Development workspace. The rules below are Pandatask-specific.

## MCP server lifecycle

- After changing `mcp-server/src`, rebuilding `mcp-server/dist`, changing MCP dependencies, or changing tool-profile membership, restart or reconnect every long-lived Pandatask MCP process that should use the change. Rebuilding `dist/` does not update a process that already loaded the old bundle.
- Validate MCP-facing changes from a freshly started server. When tools are added, removed, renamed, or re-profiled, inspect the fresh tool list and add or update an automated profile-discovery assertion.
- `PANDATASK_TOOL_PROFILE=core` is the normal interactive profile. First-class work/time tools (`task_complete`, `task_time_log`, `task_time_resolve`, `work_log`, `work_list`, `work_report`) are part of `core` and must remain covered by profile tests.
- Prefer restarting/reconnecting through the client or host that owns an MCP process. Do not indiscriminately kill unrelated Node processes.

## Verification

- For MCP changes, run `npm run check` from `mcp-server/` before committing. This includes type checking, tests, and the production dependency audit.
- A deployment or local rollout that changes the MCP surface is incomplete until a fresh process advertises the expected tools.
