# Pandatask

A WordPress plugin that renders task management boards via shortcode, with optional BuddyPress group integration. The front end is a React SPA backed by a custom REST API.

**Version:** 1.0.11
**License:** GPL v2 or later  
**Requires:** WordPress 5.0+, PHP 7.4+  
**Tested up to:** WordPress 7.0
**Contributors:** lukaszliniewicz

---

## Shortcodes

### `[task_board board_name="..."]`

Renders a full task board. Every board is identified by a unique `board_name` string (lowercase letters, numbers, underscores). Multiple boards can coexist on the same site.

Optional attributes:
- `group_id` – BuddyPress group ID (for permission context).
- `page_name` – BuddyPress nav slug.

### `[pandatask_bug_tracker board_name="..." default_assignee_id="..."]`

Renders a bug-tracker view showing only tasks of type `bug` for the given board, with a form to submit new issues.

---

## Board Types

| Type | Identifier pattern | Access control |
|---|---|---|
| Standard | Any string (`project_alpha`) | User must have `edit_posts` capability |
| BuddyPress group | `group_{ID}` | Group membership required |
| Private user | `user_{ID}` | Only the board owner |
| Auto (user private) | Created at `user_{ID}` on BuddyPress profile | Only the profile owner and admins |

---

## Views

The board offers four task display modes, switchable from the header:

- **Compact** – Flat list with expandable subtrees, drag-and-drop reparenting, kebab-menu actions, grouped by source board for user boards.
- **List** – Traditional table-style rows with inline status dropdown, show/hide description, archive/unarchive.
- **Kanban** – Three columns (Pending, In Progress, Done). Tasks can be dragged between columns or dropped onto another task to make it a child.
- **Calendar** – Month grid showing tasks on their deadline date, with navigation.

There are also five tabs in the main navigation:

| Tab | Content |
|---|---|
| All Tasks | Filterable task list in the selected view mode |
| Projects | Project overview with associated tasks; "Add Project" button |
| Overview | Week or month timeline showing all tasks (start/deadline), with recurring instance generation |
| Archive | Archived tasks with unarchive/delete actions |
| Report | Statistical report for a selected period |

---

## Task Model

Each task has these fields:

| Field | Type | Description |
|---|---|---|
| `name` | string (required) | Task title |
| `description` | string (HTML) | Rich-text description |
| `status` | enum | `pending`, `in-progress`, `done` |
| `priority` | integer (1–10) | Default 5 |
| `task_type` | enum | `task` (default) or `bug` |
| `bug_url` | string | URL associated with a bug report |
| `start_date` | date (YYYY-MM-DD) | When work begins. Auto-set to today when status becomes `in-progress` if empty. |
| `deadline` | date (YYYY-MM-DD) | Absolute deadline |
| `deadline_days_after_start` | integer | Relative deadline: N days after start date. Mutually exclusive with fixed deadline. |
| `category_id` | integer | Reference to a board category |
| `project_id` | integer | Reference to a board project |
| `parent_task_id` | integer | Makes this task a child (subtask) of another task |
| `predecessors` | array of integers | Task IDs that must finish before this one can start |
| `is_recurring` | boolean | Whether the task is a recurring template |
| `recurrence_frequency` | string | `weekly`, `bi-weekly`, `monthly`, `custom_weekly` |
| `recurrence_days` | string | Comma-separated ISO day numbers for `custom_weekly` |
| `recurrence_ends_on` | date | Optional end date for recurrence |
| `notify_deadline` | boolean | Enable deadline reminder |
| `notify_days_before` | integer | Days before deadline to send reminder (1–30) |
| `archived` | boolean | Soft-delete / hide from active views |
| `attachment_type` | string | `file` (WP media library) or `link` (external URL) |
| `attachment_url` | string | File or link URL |
| `attachment_filename` | string | Display name for the attachment |
| `assigned_persons` | array of integers | User IDs assigned as assignees |
| `supervisor_persons` | array of integers | User IDs assigned as supervisors |
| `completed_at` | datetime | Set automatically when status becomes `done` |

---

## Task Actions (from UI)

From the task list or detail modal:

- **View details** – Opens a modal with full metadata, description, subtasks list, comment thread, and collapsible audit log.
- **Edit** – Pre-filled modal form with three tabs (General, Schedule & Rules, People & Files).
- **Add subtask** – Opens the task form with `parent_task_id` pre-set.
- **Delete** – Confirms then removes the task and its assignments, comments, and history.
- **Archive / Unarchive** – Toggles the `archived` flag.
- **Change status** – Click the status pill/badge to open an inline dropdown.
- **Quick actions (compact view)** – Kebab menu with Edit, View, Add Subtask, Google Calendar export, Archive, Delete.
- **Google Calendar export** – Opens a `google.com/calendar/render` URL with the task name and deadline.

---

## Task Dependencies

A task can list one or more predecessors. When all predecessors are `done`, the task is unblocked. If a blocked task's status is changed to `in-progress` or `done` while still blocked, the update is rejected.

When a task is marked `done`, the plugin finds all direct successors and:
- Sets their status to `in-progress`.
- Sets their `start_date` to today.
- Recalculates their `deadline` from `deadline_days_after_start` if configured.

This creates a basic cascading Gantt-chart behaviour.

---

## Recurring Tasks

When a task has `is_recurring = 1`, it becomes a template. The daily cron job `pandat69_check_recurring_tasks` rolls over completed or past-due recurring tasks:

- The `start_date` advances according to the frequency/interval/days setting.
- The `deadline` is recalculated (either via `deadline_days_after_start` or by preserving the original duration).
- Status resets to `pending`, `completed_at` is cleared.

In calendar and overview views, future virtual instances are generated client-side via `generateFutureOccurrences()` so users can see upcoming occurrences.

---

## Categories

Categories are simple name strings scoped to a board (unique per board). They can be created and deleted from:
- The "Manage Categories" button in the board header (opens a modal with add/delete UI).
- Inline within the task form (type a name and confirm to create and assign in one step).

Deleting a category sets `category_id` to null on all tasks that used it.

---

## Projects

Projects group tasks within a board. Each project has a name, description, optional deadline, and its own assignees/supervisors.

The project sidebar lists all projects; clicking one filters the task list to that project. The sidebar also shows task counts per project and has an "Unassigned" filter for tasks without a project.

The Projects tab shows a full project list with inline tasks, plus a "Tasks without a project" section at the bottom.

---

## Comments

Every task has a threaded comment section. Comments support @mentions via the `react-mentions` library:

- Type `@` followed by a name to search users (scoped to board context).
- Mentions generate email and BuddyPress notifications.
- Comments can be edited or deleted by the author, group admin/mod, or site admin.

The comment textarea uses a dedicated `MentionTextarea` component that renders a `MentionsInput` from `react-mentions`.

---

## User Assignment

Two roles can be assigned to a task:

| Role | Purpose | Notifications |
|---|---|---|
| Assignee | Responsible for completing the work | Assignment, comment, mention, deadline |
| Supervisor | Oversight role | Same set of notifications |

Both use the same autocomplete user selector (`UserSelect` component) that queries the REST API.

For BuddyPress group boards, the user search returns only group members plus site administrators. For standard boards, it searches all WP users.

When a task is created on a `user_{ID}` board, the board owner is automatically added as an assignee.

---

## Notifications

### Email

Sent via `wp_mail` with HTML formatting. Notification types:

- Task assignment (with role context)
- New comment on a task you are assigned to or supervise
- @mention in a comment or description
- Deadline approaching (configurable days before)
- Deadline missed
- Aggregated update (combines multiple field changes made within a 5-minute window into one digest email)

### BuddyPress

If the BuddyPress Notifications component is active, the plugin registers a `pandatask` component and adds notifications for:

- `task_assignment` / `task_supervision` – You were assigned (as assignee/supervisor)
- `task_comment` – Someone commented on a task you are involved with
- `task_mention` / `task_description_mention` – You were @mentioned
- `task_deadline` – A deadline is approaching

Clicking a notification marks it read and navigates to the task board (with `open_task` query parameter for deep-linking).

---

## Reports

The Report tab provides per-period statistics:

- **Tasks added** – Tasks created within the selected period.
- **Tasks completed** – Tasks moved to `done` within the selected period.
- **Missed deadlines** – Active tasks past their deadline.
- **Open tasks per person** – Workload distribution across assignees.

Periods: This Week, Last Week, Last 7 Days, This Month, Last Month, Last 30 Days, or a custom date range.

---

## Bug Tracker

The plugin includes a bug-tracking subsystem built on top of the task model (tasks with `task_type = 'bug'`).

### Floating Bug Reporter

A draggable floating button rendered in the footer. Configuration via **Settings > Pandatask AI > Settings**:

- **Visibility:** Disabled, Logged-in Users Only, Logged-out Users Only, Everyone.
- **Target Board:** Which board to create bug tasks in.
- **Default Assignee:** Optional user to auto-assign.

The floating reporter opens a modal with the task form pre-filled with `task_type = 'bug'`, the current page URL as `bug_url`, and system info (user agent, screen resolution, viewport) appended to the description. The widget position is persisted in `localStorage`.

### Bug Tracker Shortcode

`[pandatask_bug_tracker board_name="..." default_assignee_id="..."]` renders a standalone bug list and submission form scoped to the given board.

### BuddyPress Bug Tracker Tab

If the Bug Tracker group extension is registered, groups can optionally enable a "Bug Tracker" tab (separate from the "Tasks" tab) with its own settings (enable/disable, default assignee).

---

## AI Assistant (Admin)

Accessible via the **Pandatask AI** admin menu item. A four-step workflow:

1. Select a board and write a plain-English request (e.g., "Create a task called Review Q3 report, assign it to user 5, deadline next Friday").
2. The system generates a structured prompt containing the available users, projects, categories, and API schema for the selected board.
3. Copy the prompt to any LLM; the LLM should return a JSON array of actions.
4. Paste the JSON response and execute. The backend processes actions via the batch REST API.

The action types supported: `create_task`, `update_task`, `delete_task`, `create_project`, `update_project`, `delete_project`, `create_category`, `delete_category`, `create_comment`, `update_comment`, `delete_comment`.

---

## Full-Screen Mode

Adds a virtual page at `/pandatask-fullscreen/?board_name=...` via a WordPress rewrite rule. The template renders the board without any theme chrome. Permission checks are enforced for BuddyPress group boards.

---

## REST API

Base: `/wp-json/pandatask/v1/`

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/boards` | List available boards |
| GET | `/users` | Search users (scoped to group board if `board_name` provided) |
| GET | `/users/me/boards` | Boards the current user can write to |
| POST | `/batch` | Execute multiple actions in one request |
| GET | `/boards/{board_name}/tasks` | List tasks with filters |
| POST | `/boards/{board_name}/tasks` | Create a task |
| GET | `/tasks/{id}` | Get single task |
| POST | `/tasks/{id}` | Update task |
| DELETE | `/tasks/{id}` | Delete task |
| GET | `/tasks/{id}/history` | Task audit log |
| GET | `/boards/{board_name}/potential-parents` | Eligible parent tasks |
| GET | `/boards/{board_name}/projects` | List projects |
| POST | `/boards/{board_name}/projects` | Create project |
| GET/POST/DELETE | `/projects/{id}` | Single project CRUD |
| GET | `/boards/{board_name}/categories` | List categories |
| POST | `/boards/{board_name}/categories` | Create category |
| DELETE | `/categories/{id}` | Delete category |
| GET | `/tasks/{task_id}/comments` | List comments |
| POST | `/tasks/{task_id}/comments` | Add comment |
| POST/DELETE | `/comments/{id}` | Update or delete comment |
| GET | `/boards/{board_name}/report` | Report data for a period |
| POST | `/ai/generate-prompt` | Generate an LLM prompt (admin only) |

See [`API_REFERENCE.md`](API_REFERENCE.md) for detailed schemas and examples.

---

## Permissions

| Resource | Requirement |
|---|---|
| Standard board (read) | Logged in + `edit_posts` |
| Standard board (write) | Logged in + `edit_posts` |
| Group board | Group membership (or `bp_moderate`) |
| User private board | Board owner (or `manage_options`) |
| Task (via API) | Board access + assigned/supervisor/creator |
| Comment management | Comment author, group admin/mod, or `manage_options` |
| AI assistant | `manage_options` |
| Batch execution | `manage_options` |
| Public bug submission | Enabled in settings + configured visibility allows the requester session |

---

## Database

The plugin creates the following tables (prefixed with `wp_pandat69_`):

- `tasks` – Core task data, all field columns
- `categories` – Board-scoped category names
- `assignments` – Task-to-user mapping with role (assignee/supervisor)
- `comments` – Task comments with timestamps
- `projects` – Board-scoped projects
- `project_assignments` – Project-to-user mapping
- `task_history` – Audit log of field changes
- `task_relationships` – Predecessor/successor links

Cache versioning uses WordPress transients suffixed with incrementing integers, invalidated on every create/update/delete operation.

---

## Cron Events

| Hook | Schedule | Purpose |
|---|---|---|
| `pandat69_daily_task_start_check` | Daily | Sets `in-progress` for tasks whose `start_date` is today |
| `pandat69_check_recurring_tasks` | Daily | Rolls over completed/past-due recurring tasks to next occurrence |
| `pandat69_check_deadlines` | Daily | Sends approaching-deadline and missed-deadline notifications |
| `pandatask_process_buffered_changes` | Single event (5 min delay) | Flushes aggregated change history and sends digest email |

---

## JavaScript API

The plugin exposes `window.Pandatask.mountBoard(container, props)` for external React integration. Props: `boardName`, `apiSettings`, `currentUser`.

---

## Requirements

- WordPress 5.0+
- PHP 7.4+
- BuddyPress (optional, for group/profiles/notifications features)
