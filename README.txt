=== Pandatask ===
Contributors: l.liniewicz
Tags: task management, project management, buddypress, kanban, todo, tasks, calendar, subtasks, recurring tasks, gantt, bug tracker
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.12
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that renders task management boards via shortcode, with optional BuddyPress integration. Includes list, kanban, and calendar views, subtasks, dependencies, recurring tasks, and a bug tracker.

== Description ==

Pandatask adds a fully interactive task management interface to WordPress using the `[task_board]` shortcode. The front end is a React SPA with support for multiple boards, user assignments, categories, projects, comments with @mentions, and an audit log.

When BuddyPress is active, boards can be attached to BuddyPress groups (with per-group enable/disable) and user profiles, and notifications appear in the BuddyPress notification centre.

= Core features =

*   **Multiple boards** – Each shortcode instance with a unique `board_name` creates a separate board with its own tasks, categories, and projects.
*   **Four view modes** – Compact list (with subtask tree and drag-and-drop reparenting), full list (with inline actions), Kanban (drag between columns), and monthly calendar.
*   **Five tabs** – All Tasks, Projects (grouped task view), Overview (week/month timeline), Archive (soft-deleted tasks), Report (per-period statistics).
*   **Task hierarchy** – Tasks can have parent-child relationships (subtasks). Drag a task onto another to make it a child.
*   **Task dependencies** – Tasks can list predecessors. A task is blocked until all predecessors are done. Completing a task auto-starts its successors.
*   **Recurring tasks** – Weekly, bi-weekly, custom weekdays, or monthly. A daily cron rolls over completed instances to the next occurrence.
*   **Deadline management** – Fixed dates or relative duration (days after start). Per-task deadline reminders with configurable lead time.
*   **User roles** – Assignees (responsible) and supervisors (oversight). Both receive notifications.
*   **Categories** – Named groupings scoped to each board, manageable from the board header or inline in the task form.
*   **Projects** – Named groupings that cross-cut categories. The project sidebar filters the task list; the Projects tab shows a full overview with inline tasks.
*   **Comments with @mentions** – Autocomplete user search, email and BuddyPress notifications for mentions.
*   **Attachments** – Upload via the WordPress media library or attach an external URL.
*   **Audit log** – Every field change is recorded. Multiple rapid changes are aggregated into a single history entry with a digest email.
*   **Google Calendar export** – One-click export of task deadlines to Google Calendar.
*   **Full-screen mode** – Visit `/pandatask-fullscreen/?board_name=...` for a distraction-free board view.
*   **Floating bug reporter** – A draggable button (configurable visibility: logged-in, logged-out, everyone, or off) that opens a bug submission form pre-filled with the current URL and system info.
*   **Bug tracker shortcode** – `[pandatask_bug_tracker board_name="..."]` renders a standalone bug list and submission form.
*   **Reports** – Tab showing tasks added, completed, and missed deadlines for configurable periods, plus workload distribution per user.
*   **AI assistant** – Admin page that generates structured prompts for LLMs based on board context (projects, categories, users, API schema). Paste the LLM's JSON response to execute batch operations.
*   **REST API** – Full CRUD for tasks, projects, categories, and comments, plus batch execution and report endpoints.
*   **Caching** – Transient-based with version invalidation per board and per user. All mutations clear relevant caches.

= BuddyPress integration =

*   **Group boards** – A "Tasks" tab (and optionally a separate "Bug Tracker" tab) can be enabled per group. Access is controlled by group membership.
*   **Profile boards** – A "My Tasks" tab on user profiles showing tasks across all boards the user is assigned to.
*   **Notifications** – BuddyPress in-app notifications for assignments, comments, @mentions, and approaching deadlines. Clicking a notification marks it read and deep-links to the task.

= Notifications =

*   **Email** – Assignment, comment, @mention, deadline approaching, deadline missed, and aggregated update notifications sent via `wp_mail` (HTML format).
*   **BuddyPress** – In-app notifications for the same events, managed via the `bp_notifications` API.

= Requirements =

*   WordPress 5.0 or higher (tested up to 7.0)
*   PHP 7.4 or higher
*   BuddyPress (optional – required for group boards, profile tab, and BP notifications)

== Installation ==

1. Upload the `pandatask` folder to `/wp-content/plugins/`, or upload and activate the plugin ZIP via Plugins > Add New.
2. Activate the plugin through the Plugins menu.
3. Place the shortcode `[task_board board_name="your_unique_id"]` on any page or post.

== Frequently Asked Questions ==

= How do I use the shortcode? =

Add `[task_board board_name="project_alpha"]` to any page or post. Replace `"project_alpha"` with a unique identifier for the board. Each board name must be unique.

= How do permissions work? =

Standard boards are accessible to logged-in users with `edit_posts`. BuddyPress group boards require group membership, and private user boards (`user_{ID}`) are accessible only to the owner. Task updates and deletions additionally require task participation, creation/ownership, board-management capability, or administrator privileges. The batch endpoint is administrator-only.

= Can I have multiple boards? =

Yes. Each unique `board_name` in the shortcode creates a separate board with its own tasks, categories, and projects.

= Does it work with BuddyPress? =

Yes. When BuddyPress is active with the Groups component, the plugin adds configurable Tasks and Bug Tracker tabs to groups, a My Tasks tab to user profiles, and BuddyPress notifications.

= Is there a REST API? =

Yes. All operations are available via the `pandatask/v1` REST API, including a batch endpoint for executing multiple actions in one request. See `API_REFERENCE.md` for details.

== Screenshots ==

1. Compact list view showing task hierarchy and filters
2. Kanban board with drag-and-drop columns
3. Calendar view
4. Task detail modal with comments and history
5. Project sidebar filtering
6. BuddyPress group Tasks tab

== Changelog ==

= 1.0.12 =

* Add REST metadata, task pagination, and 24-hour per-user idempotency for authenticated mutations.
* Make MCP summaries site-timezone aware, exclude recurring templates from actionable totals, and prevent duplicate private-board briefing counts.
* Add project-plan dependency preflight, retry/resume keys, rollback on unkeyed failures, progress notifications, bounded concurrency, and response-size limits.
* Add stable MCP output envelopes and schemas, typed administrator batches, richer tool guidance, and core/full/admin tool profiles.

= 1.0.11 =

* Harden REST authorization, public bug submission, content sanitization, and attachment handling.
* Make task, project, and category mutations transactional and preserve omitted assignment fields on partial updates.
* Remove task-list N+1 queries, add targeted cache invalidation and database indexes, and make report date filters index-friendly.
* Improve React Query cache behavior, task hierarchy handling, accessibility, responsive listeners, date parsing, and modal focus management.
* Split the frontend bundle into lazy-loaded chunks and remove unused dependencies and editor assets.
* Add policy regression tests, JavaScript and Sass linting, WordPress security checks, PHPStan analysis, and continuous integration.
