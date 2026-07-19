# Pandatask audit — 2026-07-18

## Scope and environment

The audit covered the complete Pandatask WordPress plugin repository, including the React application, REST API, WordPress/BuddyPress integration, database lifecycle, caching, cron handlers, notifications, protected attachments, packaging, dependencies, and engineering-quality configuration.

The code was compared with the active installations on:

- live: `/home/iarf/htdocs/iarf.net/wp-content/plugins/pandatask`
- dev: `/home/iarf-dev/htdocs/dev.iarf.net/wp-content/plugins/pandatask`

Audited files and the compiled frontend bundle matched the deployed versions. Runtime checks were read-only, apart from an intentionally unknown no-op REST batch action on dev that exercised permission handling without changing data.

Environment observed during the audit:

- plugin version: 1.0.10
- live WordPress: 7.0
- dev WordPress: 7.0.2
- PHP CLI: 8.2.31
- MariaDB: 11.2.6
- live/dev database schema option: 1.0.9

## Executive result

Pandatask was not ready for an unqualified production release at the time of the audit. The main concerns were:

- an authentication-only administrative batch endpoint that bypassed object and board authorization;
- stored XSS and untrusted shortcode execution through unsanitized task updates;
- an unsafe public-bug attachment path capable of manipulating arbitrary Media Library files;
- a severe task-list N+1 query problem (1,867 SQL queries for 143 active tasks);
- viewer-specific authorization state stored in shared task caches;
- non-transactional multi-table mutations and ignored persistence failures;
- multiple concrete cron, recurrence, assignment, board-targeting, packaging, React Query, hierarchy, accessibility, and timezone defects;
- almost no behavioral regression coverage and weak static-analysis/lint enforcement.

## Findings

### Critical

1. **Batch REST authorization bypass**
   - `/pandatask/v1/batch` required only a logged-in user.
   - It directly invoked create/update/delete handlers for tasks, projects, categories, and comments without the normal permission callbacks.
   - A safe dev-server probe confirmed that a non-administrator reached the handler and received HTTP 200.

2. **Stored XSS and shortcode execution through task updates**
   - The task update route did not attach the existing REST schema.
   - Update data copied the request body wholesale and persisted raw names/descriptions/status values.
   - Descriptions were passed through `do_shortcode()` and rendered with React `dangerouslySetInnerHTML`.

3. **Unsafe public bug submission and attachment migration**
   - The public permission check verified only the configured board and `task_type=bug`.
   - Anonymous input could still control assignments, relationships, recurrence, status, and `attachment_post_id`.
   - Protected-attachment synchronization could copy an arbitrary Media Library file and delete its public original and derivatives.

### High

4. **Read permission reused for task update and delete**
   - Any group member could modify or delete every group task unless that broad collaboration model was explicitly intended.
   - A task could be moved to a destination board without checking access to that destination.
   - Category, project, parent, predecessor, and user references were not validated against the destination board.

5. **Severe task-list N+1 query behavior**
   - The largest deployed board returned 143 active tasks using 1,867 SQL queries.
   - Server-side repository time was approximately 607 ms live and 552 ms dev, before transfer or React rendering.
   - Per-task predecessor, blocked-state, user, and avatar hydration caused the majority of the work.

6. **Viewer-specific state in a shared 12-hour task cache**
   - Cached task objects included comment `can_manage` flags and viewer-dependent board display names.
   - The first viewer could determine which controls later viewers saw.

7. **Non-transactional multi-table writes and ignored failures**
   - Task create/update/delete operations spanned tasks, assignments, history, relationships, files, cache invalidation, and notifications without transactions.
   - Task updates ignored the repository result and returned success.
   - Task deletion left dependency rows behind and wrote a deletion-history entry immediately before deleting all task history.

8. **Partial assignment updates cleared an omitted role**
   - Updating assignees without supervisors cleared all supervisors, and vice versa.
   - Project assignments had the same behavior.

9. **Failed or blocked task updates returned HTTP 200**
   - React treated blocked transitions and persistence failures as successful mutations, closed forms, and temporarily retained optimistic state.

10. **Missed scheduled starts were never recovered**
    - Cron selected only `start_date = today`.
    - Four deployed pending tasks already had start dates in the past and would never be picked up by this query.

11. **Release ZIP omitted required floating-reporter assets**
    - Runtime referenced `floating-bug-reporter.css` and `.js`.
    - The ZIP script copied only the two administrative assets.

### React and product logic

12. **React Query v5 called with v4 invalidation signatures**
    - Array arguments invalidated every cached query rather than the intended key.
    - This created unnecessary refetches and masked missing detail-query invalidations.
    - `keepPreviousData: true` also used the pre-v5 API.

13. **“Create in Board” from My Tasks posted to the original board**
    - The form placed the selected target in the payload, while the mutation URL and PHP route continued to use the context board.

14. **Deadline notification settings were discarded on task creation**
    - React submitted the values and the service supported them, but the REST create DTO omitted both fields.

15. **Bi-weekly recurrence degraded to weekly after editing**
    - Storage normalized it to weekly with interval 2.
    - Form hydration showed weekly and the next save reset the interval to 1.
    - Completed recurring tasks did not roll forward until after their deadline.

16. **Hierarchy cycles and missing deeper descendants**
    - Drag-and-drop and the API allowed a parent to be placed below a descendant.
    - Potential-parent filtering excluded only direct children.
    - Cyclic tasks could disappear from rooted views.
    - The normal list rendered only one level of children.

17. **Accessibility gaps**
    - Modals lacked dialog semantics, focus trapping, initial/return focus, and Escape handling.
    - Several tabs, suggestions, and removal controls were non-semantic clickable elements.
    - Drag-and-drop omitted a keyboard sensor.

18. **Frontend and directory scaling issues**
    - Task and user searches requested on every keystroke.
    - BuddyPress group searches loaded all matching members.
    - Standard user results were capped at 50, causing selected users outside the result to disappear visually.
    - Board views always loaded the WordPress editor and Media Library.
    - Several declared production dependencies were unused.

19. **Additional correctness and privacy issues**
    - Logged-in users could enumerate private `user_ID` board names and arbitrary group member directories.
    - BuddyPress membership hooks invalidated the wrong writable-board cache key.
    - Unset group-task metadata was treated inconsistently as enabled or disabled.
    - Selecting a custom report immediately issued an invalid request before dates were entered.
    - Date-only strings were parsed inconsistently as UTC or local time.
    - Reports mixed UTC database timestamps with site-local date ranges and wrapped indexed columns in `DATE()`.
    - Nested modals could remove the body scroll lock while a parent remained open.

## Performance and data observations

- tasks: 166
- assignments: 182
- comments: 21
- projects: 9
- categories: 11
- task relationships: 0
- largest board: `group_10`, 146 total / 143 non-archived tasks
- invalid status or priority values: 0
- current orphan assignments/comments/history: 0
- current cross-board parent/category/project references: 0
- current hierarchy/dependency two-cycles: 0

The main board query used a full task-table scan and filesort for the deployed filter/order combination. The task table had only single-column indexes; no useful board/filter/order composite index was present.

## Engineering-quality baseline

- `npm test`: passed, but consisted of six source-pattern contract checks rather than behavioral tests.
- `npm run build`: passed with 19 Sass deprecation warnings.
- `npm audit --omit=dev`: no known production vulnerabilities.
- Composer audit: no known vulnerabilities.
- PHP syntax and configured PHPCS: passed, but PHPCS enabled only `Generic.PHP.Syntax` rather than WordPress Coding Standards.
- PHPStan: passed at level 1 over only a small subset of PHP.
- JavaScript lint: 454 errors across 14 files (422 Prettier, 28 `curly`, and four smaller issues).
- Style lint: 3,487 errors, mostly formatting.
- No CI configuration was present.
- Compiled bundle: 289.4 KiB raw / 88.2 KiB gzip.
- Compiled CSS: 71.8 KiB raw / 11.7 KiB gzip.

## Remediation order

1. Lock down batch actions, canonicalize REST validation, close stored XSS, and isolate the public bug endpoint.
2. Replace task-list N+1 hydration and viewer-dependent caching.
3. Split read/create/update/delete/move authorization and validate every cross-entity reference.
4. Add transactions, failure propagation, and relationship cleanup.
5. Fix cron catch-up, assignment patch semantics, task notification creation, recurrence, and board targeting.
6. Correct React Query v5 usage, hierarchy behavior, modal accessibility, search, and dates.
7. Repair packaging, dependencies, BuddyPress cache/settings behavior, and metadata drift.
8. Add focused security, service, REST, React, cron, and packaging regression coverage and enforce meaningful quality gates.

## Remediation and validation result

Status on 2026-07-18: **all audit findings were addressed in the 1.0.11 working tree and the resulting release package was verified on the dev installation.** Production was deliberately left unchanged.

Implemented remediation includes:

- administrator-only batch execution; separate task read/update/delete policies; destination-board and cross-entity validation; constrained public bug-submission policy;
- REST create/update schemas, input whitelisting, enum/date/recurrence validation, stored-content sanitization, inert shortcode rendering, and DOM-safe administrative output;
- transactional task/project/category writes, persistence-error propagation, relationship cleanup, partial-assignment patch semantics, and corrected HTTP failure statuses;
- bulk task relationship/user hydration, per-request avatar hydration reuse, viewer-neutral task caching, targeted cache versions, and composite database indexes;
- scheduled-start catch-up, recurring-task rollover corrections, deadline-notification preservation, report timezone/index fixes, and no-recipient deadline handling;
- corrected React Query v5 APIs and invalidation keys, target-board mutations, hierarchy cycle prevention and recursive rendering, bi-weekly hydration, debounced searches, stable clients, lazy-loaded views, semantic controls, keyboard drag-and-drop, and modal focus/scroll management;
- BuddyPress visibility/default consistency, selected-user directory retention, bounded group-member searches, corrected cache invalidation, conditional Media Library loading, and removal of unused packages;
- complete release asset copying, version/schema bump to 1.0.11, updated public API documentation, meaningful PHP security/static-analysis configuration, clean JavaScript/Sass linting, runtime policy and server smoke tests, and CI enforcement.

Dev-server validation (`https://dev.iarf.net`):

- plugin/schema versions: 1.0.11 / 1.0.11;
- non-administrator `POST /batch`: HTTP 403 (pre-fix: HTTP 200 and handler execution);
- malicious task rendering: script removed, registered shortcode not executed;
- largest board repository load: 143 tasks in 52-54 SQL queries (pre-fix: 1,867; approximately 97% fewer); isolated warm probe: 75.6 ms;
- task REST collection: HTTP 200;
- isolated REST mutation lifecycle: category/project/task/dependency/comment create, partial update, and delete all returned the expected 2xx statuses; invalid status returned 400, blocked completion and hierarchy cycle returned 409; cleanup left zero temporary board rows;
- all required task, assignment, comment, and history indexes: present;
- main bundle and every lazy chunk, stylesheet, and floating-reporter asset: HTTP 200;
- production remained active on 1.0.10 with schema 1.0.9.

Final local verification:

- `npm test`: 6/6 source contracts plus runtime security-policy tests passed;
- `npm run lint`: JavaScript and Sass passed with zero errors;
- `npm run build` and `npm run zip`: passed; main JavaScript reduced from 289.4 KiB to 179.4 KiB (55.7 KiB gzip);
- PHP syntax: 61 files passed;
- PHPCS WordPress security rules: passed;
- PHPStan level 3 across `src`: passed;
- npm and Composer vulnerability audits: zero advisories;
- package inspection: 124 entries, correct 1.0.11 metadata, all required split and floating assets present;
- `git diff --check`: passed.

Remaining non-blocking architecture note: protected task files are copied behind Pandatask's authorized delivery path, but an original WordPress Media Library URL can remain public. The unsafe behavior found in this audit (accepting arbitrary public attachment IDs and deleting originals/derivatives) is removed. Sites requiring strict confidentiality should use non-public object storage or web-server-denied originals with signed/authorized delivery.

## Production release record

Deployed on 2026-07-18 after the final GitHub Quality workflow passed on frontend, PHP 7.4, and PHP 8.2.

- pushed `main` commits: `168e54f` (audit remediation), `77cdeb7` (CI path/runtime correction), and `e2bc6db` (PHP 7.4-compatible security fixture);
- deployed package SHA-256: `db24b125d5440a158981e8c7b90612b0343f09b133ae00d0b91035d28587c30f`;
- production plugin/schema: 1.0.11 / 1.0.11, active;
- production code backup: `/home/iarf/pandatask-backups/pandatask-before-1.0.11-20260718T173200Z.tar.gz`;
- production database backup: `/home/iarf/pandatask-backups/iarf-before-pandatask-1.0.11-20260718T173200Z.sql.gz` (gzip integrity verified);
- production smoke result: subscriber batch request 403, sanitization invariant passed, task REST request 200, all expected indexes present, and 143-task repository probe used 52 queries in 157.6 ms;
- production home page, main bundle, all six lazy chunks, main stylesheet, and both floating-reporter assets returned HTTP 200;
- temporary deployment uploads were removed; the recoverable backups were retained.
