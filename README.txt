=== Pandatask ===
Contributors: l.liniewicz
Tags: task management, project management, buddypress, kanban, todo, tasks, calendar, subtasks, recurring tasks, gantt, bug tracker
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.24
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that renders task management boards via shortcode, with optional BuddyPress integration. Includes list, Kanban, calendar, and dependency-aware Gantt views, subtasks, recurring tasks, and a bug tracker.

== Description ==

Pandatask adds a fully interactive task management interface to WordPress using the `[task_board]` shortcode. The front end is a React SPA with support for multiple boards, user assignments, categories, projects, comments with @mentions, and an audit log.

When BuddyPress is active, boards can be attached to BuddyPress groups (with per-group enable/disable) and user profiles, and notifications appear in the BuddyPress notification centre.

= Core features =

*   **Multiple boards** – Each shortcode instance with a unique `board_name` creates a separate board with its own tasks, categories, and projects.
*   **Five view modes** – Compact list (with subtask tree and drag-and-drop reparenting), full list (with inline actions), Kanban (drag between columns), monthly calendar, and a dependency-aware Gantt with parent roll-ups and an unscheduled-work tray.
*   **Five tabs** – All Tasks, Projects (grouped task view), Overview (week/month timeline), Archive (soft-deleted tasks), Report (per-period statistics).
*   **Task hierarchy** – Tasks can have same-board parent-child relationships (subtasks). Subtasks inherit the parent's project, including descendant cascades when the project changes; parent board moves are blocked until children are moved or detached.
*   **Task dependencies** – Tasks can list predecessors. A task is blocked until all predecessors are done. Completing a task auto-starts its successors.
*   **Recurring tasks** – Weekly, bi-weekly, custom weekdays, or monthly. A daily cron rolls over completed instances to the next occurrence.
*   **Deadline management** – Fixed dates or relative duration (days after start). Per-task deadline reminders with configurable lead time.
*   **User roles** – Assignees (responsible) and supervisors (oversight). Both receive notifications.
*   **Categories** – Named groupings scoped to each board, manageable from the board header or inline in the task form.
*   **Projects** – Named groupings that cross-cut categories. Personal workspaces include enabled group projects by default and offer a private-only filter.
*   **Comments with @mentions** – Autocomplete user search, email and BuddyPress notifications for mentions.
*   **Attachments** – Upload via the WordPress media library or attach an external URL.
*   **Audit log** – Every field change is recorded. Multiple rapid changes are aggregated into a single history entry with a digest email.
*   **Google Calendar export** – One-click export of task deadlines to Google Calendar.
*   **Full view** – Expand the current board above the WordPress/theme shell without losing state; the protected legacy fullscreen URL remains available for bookmarks.
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

Standard boards are accessible to logged-in users with `edit_posts`. BuddyPress group boards require group membership, and private user boards (`user_{ID}`) are accessible only to the owner. Participants may update ordinary task fields; only creators, supervisors, board managers, or administrators may change task roles, and board moves require board-manager authority. The batch endpoint is administrator-only.

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

= 1.0.26 =

* Add complete MCP work-entry management with fetch, edit, delete, task attachment, detachment, reassignment, and custom work-type tools.
* Add a one-call visible-task listing across readable boards with permission-aware REST pagination and explicit truncation feedback.
* Preserve historical recurring-task occurrence snapshots and creator provenance during work-entry edits, and reject allocation/duration inconsistencies.

= 1.0.24 =

* Add explicit, member-controlled sharing of complete personal Work Logs with selected BuddyPress groups, protected by group-level enablement and live membership checks.
* Add a read-only group Work Logs view with member summaries, full entry history, and privacy-safe shared data projections.
* Consolidate date presets and export actions into compact menus, and make CSV exports include every entry in the selected period with spreadsheet-injection protection.
* Seed Development as a built-in work type and preserve the improved Other task time presentation for reconciliation entries.

= 1.0.23 =

* Make Work Log a default-on module with server-enforced disablement, task completion fallback, and consistent desktop and compact navigation.
* Add a task-or-work quick action and shared work-entry modal, including board defaults, task refinement, editable entries, and responsive feedback states.
* Add user-managed work types with stable keys, rename, archive, and restore while keeping task categories distinct.
* Redesign the dedicated Work Log and report summary with paginated history, readable labels, actionable unresolved completions, and clearer allocation breakdowns.
* Present generated reconciliation residuals as task-linked "Other task time", exclude them from work-type and capacity classifications, and correct last-month report boundaries.

= 1.0.22 =

* Add incremental task-time logging for active tasks through MCP while preserving final cumulative reconciliation on completion.
* Make the personal Work Log reachable from desktop, compact navigation, and direct tab URLs.
* Add task-or-board work allocation and editable factual work entries, including MCP board allocation support.
* Integrate personal work totals into Reports and distinguish task-linked, board-only, unallocated, residual, unresolved, and dimensional breakdowns without double-counting.
* Clarify task time controls for active versus completed tasks.
* Keep incremental task-time logging in the core MCP profile with regression coverage.

= 1.0.21 =

* Add a generic work-suggestion provider boundary with quiet confirm, adjust, and dismiss review in the personal Work Log.
* Keep suggestions outside accounting until confirmation, persist idempotent provider decisions, and protect source provenance behind a trusted import path.
* Allow work allocations to target a board without inventing a task, enabling optional integrations such as IARF Network group meetings.

= 1.0.20 =

* Add first-class Work Log entries with split task allocations, activity/capacity classification, private notes, and personal cross-board reporting.
* Add durable task work occurrences and cumulative time resolution so recurring, skipped, reopened, and deleted tasks preserve distinct work history without double-counting residual time.
* Centralize human task completion through time-aware REST, React, and MCP boundaries; add effort estimates, audit persistence, access policy, schema 1.0.16 migration/backfill, and Work Log UI.
* Repair personal report scope, recurring MCP semantics, creator persistence, schema/smoke drift, PHPStan stubs, accessibility findings, and production backup coverage for Work Log tables.

= 1.0.19 =

* Group Compact and List tasks by project by default, with an accessible flat/grouped toggle and project-name sorting; preserve source-board headings in personal workspaces.
* Refine navigation, buttons, calendar contrast and mobile dates, project-selection reset behavior, report controls and collapsible report sections.
* Move task context into the detail-modal heading and add a keyboard-accessible full-viewport Gantt mode.

= 1.0.18 =

* Enforce task hierarchy, dependency, board-reference, assignment, schedule, attachment, and recurrence invariants in the application layer for REST, batch, cron, and integration callers.
* Replace aggregate-heavy task reads with bounded bulk hydration, deterministic ordering, exact look-ahead pagination, and cache-safe per-viewer decoration.
* Add schema 1.0.14 with verified hot-path indexes, transactional legacy-data repair, durable task-change buffers, catch-up-safe deadline reminders, and recovery jobs.
* Make protected attachment replacement rollback-safe, preserve monthly recurrence anchors, respect future successor dates, and report scheduled-workflow outcomes.
* Upgrade PHPStan to 2.2.7 at enforced level 4, expand domain/integrity/mutation coverage, and add a restricted pre-migration production database backup.

= 1.0.17 =

* Collapse project subtasks into accessible, expandable parent rows while preserving orphaned and cyclic data safely.
* Repair the Overview timeline crash and add regression coverage for populated timeline rows.
* Hide completed dependency candidates by default and add one-click selection of dated project or board tasks.
* Normalize project-row spacing, task status pills and circles, filter menus, task-form tabs, and the responsive project sidebar across standalone and IARF Network mounts.

= 1.0.16 =

* Clear the static React accessibility backlog with native dialog, label/control, button, keyboard, stable-key, and combobox semantics, backed by a zero-finding CI gate.
* Split the board shell/controller/dialog layer, Gantt model/renderers, task details, and task form into focused modules with behavior and architecture contract tests.
* Remove avoidable render allocations, lookup scans, prop-sync effects, stale hook dependencies, and ambiguous WordPress media subscriptions.
* Upgrade WordPress Scripts 34, bestzip 3, and the TypeScript 7 native compiler while retaining TypeScript 6 for ESLint's compiler API; keep React 18 aligned with WordPress.

= 1.0.15 =

* Make embedded boards container-responsive so group pages use an overlay project navigator when their real content slot is narrow, while standalone and profile mounts retain the wide layout.
* Add URL-backed tab, view, and task-detail navigation with browser back/forward support; make the public mount API idempotent and preserve other IARF namespace integrations.
* Centralize React Query keys and retry policy, propagate cancellation, add typed TypeScript API/query boundaries, recover lazy-load failures, and decompose the board workspace and modal layer.
* Bound Gantt rendering and recurring-task catch-up, cap list and protected-file workloads, lazy-load dimensioned avatars, and enforce production bundle budgets.
* Harden task role changes, cross-board moves, comment access, signed downloads, anonymous bug reports, directory search, AI reference data, and MCP credential handling/defaults.
* Pin CI actions, refresh safe package versions, validate release assets and PHP before deployment, and keep the dev rollback until WordPress verification succeeds.

= 1.0.14 =

* Add a dependency-aware Gantt view with explicit predecessor arrows, parent/subtask roll-ups, conflict warnings, three zoom levels, completed-context handling, and an unscheduled-work tray.
* Keep subtasks and dependencies semantically separate: sibling order never creates an implicit dependency, and derived parent dates never overwrite authoritative task dates.
* Open the project sidebar by default on desktop and group personal-workspace projects into Private and per-group sections.
* Improve compact-list grouping and drag affordances, add subtle tab separators, and give sidebar counters safe edge spacing.
* Redesign project cards around active compact task rows, hide completed tasks from summaries, and move project deadlines into subdued header metadata.

= 1.0.13 =

* Replace interface Dashicons with Lucide SVG icons and accessible controls.
* Isolate shortcode styling from host themes and add a state-preserving full-viewport board above host UI.
* Enforce same-board parent-project inheritance, cascade project changes to descendants, reject unsafe parent board moves, and repair legacy mismatch/orphan links on upgrade.
* Include enabled group projects in personal workspaces with source labels, permission-aware controls, and a private-only filter.
* Keep project summaries cache-correct and harden the legacy fullscreen route against unauthorized access, caching, and indexing.

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
