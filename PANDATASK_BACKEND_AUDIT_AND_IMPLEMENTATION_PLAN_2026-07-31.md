# PandaTask backend audit and implementation plan — 2026-07-31

## Scope

This review covers the complete PHP and SQL backend used by PandaTask as a
standalone WordPress plugin and inside IARF Network/BuddyPress:

- database schema, migrations, indexes, query plans, transactions, and cleanup;
- task, hierarchy, dependency, project, recurrence, scheduling, and notification
  semantics;
- REST input normalization, authorization boundaries, idempotency, and batch
  behavior;
- repositories, application services, cache ownership and invalidation;
- protected attachment persistence;
- cron recovery and audit-history durability;
- PHP structure, static analysis, WordPress compatibility, and release tooling.

The React rendering layer is outside this pass except where its API contract
determines backend pagination or task semantics.

## Evidence and baseline

### Local quality gates

The pre-implementation baseline passed:

- all PHP files passed `php -l`;
- the configured PHPCS security rules passed;
- PHPStan level 3 passed;
- all 35 JavaScript/model tests and the PHP security-policy test passed;
- Composer reported no production packages to audit.

A stricter PHPStan level 4 pass found five actionable issues: one unused method,
one idempotency-lock branch that static analysis could not prove safe, and three
always-true pagination conditions. Level 6 reported 947 findings, overwhelmingly
missing parameter/property/return declarations in the legacy WordPress-facing
code rather than demonstrated runtime defects. The release therefore adopts
level 4 as an enforced gate, with typed boundaries added to new components
rather than a risky repository-wide signature rewrite.

The broad WordPress database sniff also treats trusted, plugin-derived table
identifiers as unprepared values. Those reports were reviewed separately from
request-derived values; no SQL injection path was found in the audited
repositories. Integer `IN` lists are normalized with `absint`, and user input is
bound through `$wpdb->prepare()`.

### Production runtime

Read-only checks ran against the active 1.0.17 installation on WordPress 7.0.2,
PHP 8.2, and MariaDB 11.2.6.

- all eight PandaTask tables use InnoDB and `utf8mb4_unicode_520_ci`;
- 165 tasks, 9 projects, 181 task assignments, 21 comments, 429 history rows;
- largest board: 146 total rows; the active 143-row collection hydrated in
  52 queries / 68.3 ms;
- task REST and authorization smoke tests passed;
- zero orphan, cross-board, or project-mismatched parent links;
- zero invalid category/project references;
- zero invalid, cross-board, self-referential, or cyclic dependencies;
- zero hierarchy cycles and zero completion/date-range mismatches.

Production hygiene findings:

- three task assignments reference deleted WordPress users;
- 39 legacy history records reference tasks that no longer exist;
- 18 non-recurring tasks retain recurrence fields that have no effect;
- one recurring task has no usable start/deadline and can never roll over.

The detached history is not read by any endpoint and is only 39 rows. It will be
retained as legacy audit evidence rather than silently destroyed. New task
deletions already remove associated active history transactionally.

### Query-plan observations

- the normal active-board plan uses `board_active_status_deadline`, scans about
  26 candidate rows on the largest board, and filesorts for deadline order;
- scheduled-start uses the single-column `status` index;
- missed-deadline currently performs a full task-table scan;
- recurring rollover uses `is_recurring` and is inexpensive at current scale;
- list queries join assignments/users and aggregate with `GROUP_CONCAT`, even
  though assignment hydration is a separate concern. This adds grouping work,
  permits `group_concat_max_len` truncation, and makes pagination/sorting harder
  to reason about.

### Dependency and toolchain review

- PHPStan was upgraded from 1.12.33 to 2.2.7 and the repository is clean at the
  newly enforced level 4.
- WPCS 3.4.1 requires PHP_CodeSniffer `^3.13.5`; PHP_CodeSniffer 4.0.1 is
  therefore deliberately deferred until WPCS declares compatibility.
- WordPress stubs remain at 6.9.1 (constraint `^6.8`) so static analysis covers
  the plugin's declared compatibility boundary. The available 7.0.1 stubs would
  make WordPress 7 APIs appear valid even where the plugin should continue to
  support older installations.
- Composer has no production dependency surface and both Composer and npm
  production audits report zero known vulnerabilities.
- The JavaScript dependency scan is also current within the supported major
  lines. React/ReactDOM 19 and their type packages are the only available major
  upgrades; they remain on 18.3.1 because WordPress supplies the external React
  runtime and the plugin must remain compatible with that host runtime.

## Semantic model

The intended model is sound and remains authoritative:

1. A parent link represents decomposition/ownership, not execution order.
2. A predecessor link represents an explicit start gate.
3. Sibling subtasks never gain implicit dependencies.
4. A subtask always inherits its parent task's project.
5. Derived parent schedule ranges are display-only; task dates remain canonical.
6. A pending successor may auto-start only after all predecessors are done and
   its own not-before start date has arrived.
7. Recurrence advances one durable task/template row; virtual calendar
   occurrences are presentation data, not hidden duplicate database tasks.

No automatic parent completion or status roll-up will be introduced. That would
collapse hierarchy and sequencing into one rule and would change existing
workflow semantics.

## Findings and decisions

### H1 — Task invariants are enforced too late and too narrowly

Parent/dependency/category/project/user validation is concentrated in
`TaskRouteHandler`. The mutation service is also called from cron and can be
called directly by integrations, so invalid relationships can bypass the REST
adapter. Parent and dependency cycle checks issue one query per visited node.
Create also permits a task to start as `in-progress`/`done` while an incomplete
predecessor blocks it.

Decision:

- introduce one application-level invariant service used by every mutation;
- load board graphs/reference records in bounded set queries and validate cycles
  in memory;
- keep authorization in the REST/security layer, but keep referential and state
  invariants in the application layer;
- reject started/completed tasks whose submitted predecessor set is incomplete;
- respect a successor's future `start_date` when dependency completion occurs.

### H2 — Attachment synchronization can report failure after commit

Task update commits SQL first and then copies/protects the attachment. A copy
failure therefore returns an error even though the task was already changed.
Replacing/clearing protected files also deletes the previous copy too early for
a safe rollback.

Decision:

- validate/copy to generation-specific protected paths during the database
  transaction;
- update the registry in the same transaction;
- retain the previous generation until commit;
- remove stale generations only after commit, and remove unreferenced staged
  generations after rollback;
- never remove the public Media Library source automatically.

### H3 — Personal-workspace cache invalidation is incomplete

User task caches embed project/category names and board display names. Project
rename/delete, category deletion, recurrence rollover, and group rename can
leave those caches stale for up to one hour. Viewer-derived display data should
not be part of canonical cached task rows.

Decision:

- cache canonical cross-board tasks and decorate clones per viewer/request;
- centralize board/task/user invalidation;
- invalidate all task participants/creators affected by project/category
  metadata changes and recurrence;
- expose one canonical user-cache version API.

### H4 — Scheduled workflows ignore failures and have incomplete recovery

Recurring rollover writes directly, ignores update failures, and omits project
and personal-workspace invalidation. Scheduled-start reports candidates rather
than successful transitions. Dependency completion starts future-dated work.
Deadline reminders are neither catch-up-safe nor idempotent.

Decision:

- extract recurrence/date calculation from the mutation orchestrator;
- make rollover outcomes explicit and invalidate every affected cache;
- count only successful scheduled starts;
- preserve future not-before dates during dependency cascades;
- add a per-deadline reminder marker, reset it when deadline/notification/
  assignment state changes, and use an indexed catch-up query;
- retain missed-deadline one-shot behavior but route flag writes through checked
  persistence operations.

### H5 — Buffered audit history can be lost or misreported

Buffered changes live in expiring transients. Processing deletes the buffer
before the history insert succeeds, and aggregation uses the last change's
`from` value rather than the first. A missed single WP-Cron event can lose the
audit entry completely.

Decision:

- add an InnoDB task/actor change-buffer table;
- append the buffer inside the task mutation transaction;
- flush under a row lock, write history before deleting the buffer, and retain
  the row on failure;
- aggregate first `from` and last `to`;
- keep the per-buffer single event and add an hourly recovery sweep;
- retain a one-release fallback for already scheduled transient buffers.

### M1 — List-query aggregation and pagination are needlessly fragile

Task lists aggregate assignments with `GROUP_CONCAT`; individual task lookup
does the same and selects a non-deterministic creator row. `has_more` is inferred
from `returned === limit`, which is a false positive when a page exactly exhausts
the result set.

Decision:

- fetch task rows without assignment aggregation;
- bulk-load assignments and dependencies for the returned IDs;
- use deterministic earliest creator history;
- fetch `limit + 1`, return at most `limit`, and derive exact `has_more`;
- retain the current 500 public maximum for compatibility with the existing
  frontend while making the contract correct for incremental clients.

### M2 — Schema verification and hot-path indexes are incomplete

The migration version can advance after `dbDelta()` without proving required
tables/indexes exist. Existing indexes do not match cron, default deadline-list,
project-active-task, or ordered-history access patterns.

Decision:

- bump the schema and verify every required table/index before recording it;
- add composite indexes for default lists, scheduled starts, deadline
  notifications, recurrence, project tasks, comments, and history;
- repair invalid parent/reference/dependency/recurrence metadata
  transactionally;
- remove assignments to deleted users;
- preserve detached legacy history as documented audit evidence.

### M3 — Idempotency locks can leak and anonymous scope is broader than stated

Locks are durable non-autoloaded options and only expire when the same key is
retried. An interrupted request can leave an option indefinitely. The
middleware describes authenticated idempotency but also accepts anonymous public
bug submissions under the shared user-zero scope.

Decision:

- restrict stored response idempotency to authenticated mutations;
- add periodic cleanup for expired lock options;
- preserve atomic `add_option()` acquisition and the 24-hour replay cache.

### M4 — Oversized classes obscure ownership

`TaskMutationService` is 1,130 lines and `TaskRouteHandler` is 808 lines.
Recurrence arithmetic, history buffering, relationship invariants, persistence
orchestration, REST normalization, and notification dispatch are interleaved.

Decision:

- extract task input normalization from the REST handler;
- extract invariant/graph validation;
- extract recurrence calculation/workflow and durable history buffering;
- keep `TaskMutationService` as the transaction/orchestration boundary;
- raise the enforced PHPStan level from 3 to 4 and type new boundaries where
  PHP 7.4/WordPress interoperability permits.

Implemented decomposition reduced `TaskRouteHandler` from 808 to 391 lines and
`TaskMutationService` from 1,130 to 974 lines. The remaining mutation service is
intentionally the transaction boundary; further splitting would divide one
atomic workflow across multiple owners. Extracted components now own input
normalization, graph/invariant validation, recurrence calculation, cache
invalidation, durable history buffering, and deadline persistence.

### M5 — Comments and history have deterministic-read gaps

Comments use an inner user join, so comments disappear when their author account
is deleted. Comment/history rows with identical timestamps have unstable order.

Decision:

- use a left user join and a translated deleted-user fallback;
- order by timestamp and primary key;
- add matching ordered indexes.

### L1 — Recurring deletion documentation overstates the API

The UI and backend support `single` (advance past this occurrence) and `all`
(delete the recurring task). API documentation advertises unsupported `this`
and `following` values.

Decision:

- document the implemented two-scope model and accept `this` as a compatibility
  alias for `single`;
- reject unknown recurring delete scopes instead of silently deleting all.

## Implementation plan

| Phase | Work | Acceptance |
|---|---|---|
| 1 | Add graph/invariant, input-normalization, recurrence, cache-invalidation, and history-buffer boundaries | Direct and REST mutations share the same rules; oversized classes shrink |
| 2 | Rewrite task list hydration and exact pagination; add deterministic reads | Existing response shape remains compatible; exact page boundary tests pass |
| 3 | Add schema 1.0.14 tables/indexes, schema verification, and transactional repairs | Dev migration succeeds; audit reports no active referential/recurrence defects |
| 4 | Make attachments rollback-safe; harden cron/reminders/idempotency recovery | Failure paths do not claim rollback after commit; scheduled jobs are retryable |
| 5 | Expand unit, contract, mutation-smoke, integrity, and query-plan checks | PHPStan 4, PHPCS, syntax, all tests, dev mutation smoke, and server smoke pass |
| 6 | Release 1.0.18, push `main`, deploy atomically, and verify production | Exact hashes, active version/schema, REST, integrity, queries, assets, and rollback backup pass |

## Deployment/data policy

- Production deployment remains atomic at the plugin-directory level and keeps
  the previous plugin archive.
- Before the first schema-1.0.14 load, the affected PandaTask tables will be
  exported to a dated, access-restricted backup on the server.
- Automatic repair may remove the three assignments to deleted users, clear
  ineffective recurrence residue, and disable recurrence on records that cannot
  produce an occurrence. It will not delete detached legacy history.
- The post-deploy audit will report every repair count and exact production
  schema/index state.

## Execution record

### Implementation

All six implementation phases are complete in release 1.0.18:

- task reads now hydrate assignments/dependencies in bounded bulk queries
  without `GROUP_CONCAT`, and REST pagination uses an exact `limit + 1` probe;
- every mutation path shares application-level graph, board-reference, user,
  status/dependency, schedule, recurrence, and parent-project invariants;
- task and attachment changes share a transaction-safe staging/finalization
  lifecycle, with public Media Library sources retained;
- canonical cached task rows are viewer-neutral and cache invalidation is
  centralized across task, metadata, recurrence, notification, and workspace
  mutations;
- recurrence, scheduled starts, dependency release, deadline reminders,
  idempotency locks, and audit buffers have explicit checked persistence and
  recovery behavior;
- schema 1.0.14 adds the durable buffer table, recurrence/deadline markers, hot
  indexes, guarded migration verification, and transactional data repair;
- comments/history remain readable after user deletion and have deterministic
  ordering; API and release documentation match the implemented recurrence
  deletion model.

### Local release gate

The complete release gate passed after implementation:

- syntax checks for every non-vendor PHP file;
- Composer strict validation, PHPCS, PHPStan 2.2.7 at level 4, backend domain
  tests, security-policy tests, and zero Composer production advisories;
- TypeScript, ESLint, Stylelint, 121-file accessibility scan, all feature and
  contract suites, and zero npm production advisories;
- production build and budgets: main JavaScript 210.1 KiB / 66.4 KiB gzip
  (260 KiB budget), CSS 95.9 KiB / 15.5 KiB gzip (100 KiB budget);
- deployment-contract tests, packaging checks, and `git diff --check`.

### Development acceptance

The atomic dev deployment activated plugin 1.0.18 and migrated schema 1.0.14.
The server smoke suite passed with all required tables, columns, indexes, assets,
authorization checks, content-rendering checks, REST pagination, and hierarchy
checks present.

The mutation acceptance suite exercised category/project CRUD, partial updates,
three-level hierarchy/project inheritance, exact terminal pagination,
cross-board descendant protection, invalid status rejection, dependency and
hierarchy cycle rejection, blocked completion, durable first-old/final-new
history buffering, future-dated successor release, recurrence validation and
series deletion, comments, and group/private workspaces. Every expected response
passed and the suite left zero task, project, category, comment, or group
workspace residue.

The post-migration dev audit found zero active referential, assignment, comment,
recurrence, date, completion, role, dependency-type, reminder-marker, blocked
status, or change-buffer defects and zero hierarchy/dependency cycles. All hot
cron plans use the intended indexes. The 39 detached legacy history rows remain
intentionally preserved. The largest 143-task active board hydrated in 55
queries / 100 ms on dev.

Commit, production deployment, and post-deploy measurements remain pending and
will be appended after the production verification gate.
