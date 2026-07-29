# Pandatask standalone and IARF ecosystem implementation plan — 2026-07-29

## Goal

Release Pandatask as a theme-resilient, accessible, performant WordPress plugin
that works on a plain WordPress installation, enhances itself when BuddyPress is
available, and integrates cleanly with IARF Network without depending on Network
internals.

This release must:

- repair the visibly broken taskboard styling on IARF Network;
- use Lucide icons throughout the React interface instead of relying on
  WordPress Dashicons;
- provide a true in-page full-viewport mode above the Network shell, sidebar,
  admin bar, and other site layers;
- make every subtask inherit its parent task's project, including existing
  inconsistent data and all descendants of a project change;
- include the user's enabled BuddyPress group projects in the profile taskboard
  by default, with an explicit private-only filter;
- preserve permissions, cache correctness, standalone operation, and the
  existing REST/MCP contracts;
- be proven on dev before the production deployment.

## Current-state evidence

### Render and styling

On the dev profile taskboard, the Pandatask stylesheet is present and returns
successfully. The failure is CSS coexistence, not a missing asset:

- `.pandat69-tab-navigation` computes to `list-style-type: square`;
- the list computes to `padding-inline-start: 24px`;
- the resulting bullets are visible before every taskboard tab;
- icon-only controls expose private-use Dashicon glyphs as their accessible
  names;
- the React source uses Dashicons without registering Dashicons as a frontend
  dependency, so a standalone theme is not guaranteed to render them.

The shortcode mount uses `.pandat69-container` as both the host mount and the
React layout root. That creates nested application containers on shortcode
pages, although the Network programmatic mount does not exhibit the nesting.

The coexistence stylesheet also contains globally scoped `.nice-select` and
TinyMCE rules. Those rules can alter unrelated host-theme/plugin UI and violate
the standalone plugin boundary.

### Fullscreen and layering

The header's Fullscreen action currently navigates to
`/pandatask-fullscreen/?board_name=...`. It is not an expansion of the current
board and loses the surrounding route context.

Pandatask modals already portal to `document.body`; on dev they compute to
`z-index: 150000`, above the Network sidebar's observed `z-index: 10000`.
A full-viewport board should use the same portal principle to avoid ancestor
stacking contexts and should retain the current board state.

### Project and hierarchy behavior

- Profile tasks are aggregated across private and assigned/created group tasks.
- Profile projects query only projects whose `board_name` is `user_{ID}`.
  Consequently, a task can appear in the profile board while its group project
  is absent from both the project sidebar and Projects view.
- The existing `private_only` task API option is not connected to the profile
  UI. The generic “Mine” switch sends `assigned_to_me`, which is ignored by the
  user-board branch.
- A subtask can currently be saved with a project different from its parent.
- Changing a parent task's project does not cascade to descendants.
- Task mutations do not invalidate the board's project cache, so project task
  counts/lists can remain stale.

### Performance baseline

The checked-in production build currently contains:

- main JavaScript: 187,303 bytes raw;
- main CSS: 72,503 bytes raw;
- six lazy chunks, with the largest at 50,997 bytes raw;
- React, ReactDOM, and the JSX runtime externalized through WordPress.

Frontend assets are route/shortcode scoped, and large form/report/detail views
are already split. A dedicated Chrome performance-trace service is not
configured in this workspace, so release validation will use bundle deltas,
request/cache headers, REST/server timings, query counts, console errors, and
rendered interaction evidence rather than inventing Core Web Vitals.

## Target architecture

### 1. Stable application boundary

Use a dedicated mount class/attribute that is never also the rendered
`.pandat69-container`. Keep the IARF coexistence attributes as an optional token
bridge, but make every Pandatask structural rule self-sufficient with fallback
tokens.

Scope host-compatibility rules to a Pandatask root or portal. Apply stronger,
explicit resets only to application-owned navigation/list structures so rich
task descriptions can retain semantic lists.

No Pandatask selector may hide or restyle a generic host selector globally.

### 2. Lucide icon system

Add `lucide-react` as a production dependency and expose a small,
plugin-owned icon component backed by an explicit allow-list of named imports.
This keeps icons tree-shakeable, consistent, and independent of theme fonts.

Every icon-only button must have an accessible label. Decorative SVGs must be
hidden from assistive technology. Remove Dashicon font rules and private-use
glyph pseudo-elements from the React surface.

### 3. In-page viewport mode

Render the same stateful board through a React portal into `document.body` when
viewport mode is active:

- `position: fixed; inset: 0`;
- a deliberately top-level z-index below browser chrome but above WordPress and
  Network layers;
- independent scrolling and a sticky board header;
- body scroll lock with clean restoration;
- Escape-to-exit when no task modal is open;
- focus returned to the expansion button;
- Maximize/Minimize icon and `aria-pressed` state;
- state retained across expansion/collapse.

Keep the legacy fullscreen URL for external bookmarks and standalone use, but
enforce the same board policy and emit `noindex, nofollow, noarchive`.

### 4. Authoritative project inheritance

Parent project inheritance is a server invariant, not a form convention:

1. On task create, a selected parent always supplies the canonical
   `project_id`; a conflicting client value is ignored.
2. On task update, a task with an effective parent always uses that parent's
   project. Removing the parent permits an explicit independent project.
3. When a task's canonical project changes, every descendant is updated in the
   same transaction.
4. A task with descendants cannot change boards until those descendants are
   moved or detached, preventing cross-board hierarchy and authorization leaks.
5. Descendant task and user caches, board task/project/report caches, and
   project task summaries are invalidated together.
6. The database upgrade detaches orphan/cross-board legacy parent links and
   reconciles valid descendants to their parents, repeating bounded
   direct-parent passes until the hierarchy is stable.
7. REST, batch, UI, and MCP mutations all pass through the same invariant.

### 5. Profile workspace projects

For `user_{ID}` project collection requests:

- include projects from the user's private board and every enabled BuddyPress
  group board in their writable-board set;
- on WordPress without BuddyPress, naturally return only the private board;
- return each project once by its global project ID;
- include `board_name`, `board_display_name`, private/group scope, and a
  viewer-specific `can_manage` flag;
- include only the user's assigned/created tasks in group project task
  summaries, never every private group task;
- avoid `GROUP_CONCAT` task-name truncation by loading project rows and task
  summaries in two bounded queries;
- bulk-prime referenced WordPress users before hydration;
- support `private_only=true`.

On a profile taskboard, relabel the existing switch to “Private only”, make it
drive both task and project queries, annotate group projects with their source
group, and hide edit/delete controls when `can_manage` is false. On group and
standard boards, retain the “Mine” assignment filter.

### 6. Cache and performance rules

- Invalidate project cache versions for every task create/update/delete that can
  affect a project task list or count.
- Keep user-workspace project responses viewer-specific and uncached at the
  shared-transient level; retain canonical shared caching for ordinary boards.
- Preserve route-scoped enqueues and existing lazy chunks.
- Do not import the complete Lucide namespace.
- Remove duplicate mount wrappers and global compatibility rules.
- Validate raw/gzip bundle deltas and REST/project query counts on dev.
- Check response compression and cache headers for immutable versioned assets.

### 7. Compatibility and security

- WordPress 5.0+ and PHP 7.4 remain the supported floor.
- BuddyPress APIs are feature-detected; no BuddyPress class/function may be
  required for activation or ordinary shortcode boards.
- The Network integration remains the public
  `window.Pandatask.mountBoard(container, props)` API plus REST settings.
- Fullscreen and project aggregation reuse server-side board policies.
- Private/group data is never made indexable or rendered into a public shell.
- The release package must contain every runtime chunk, stylesheet, Lucide
  bundle contribution, floating-reporter asset, and optimized Composer
  autoloader.

## Implementation sequence

1. Add focused regression contracts for mount boundaries, Lucide usage,
   fullscreen portal/layering, profile project aggregation, private-only
   behavior, project cache invalidation, and parent-project inheritance.
2. Implement the mount/CSS boundary and Lucide component migration.
3. Implement state-preserving viewport mode and harden the legacy fullscreen
   route.
4. Implement project inheritance normalization, transactional descendant
   cascade, cache invalidation, and upgrade reconciliation.
5. Implement profile workspace project aggregation and the context-aware
   filter/presentation.
6. Update public documentation, API reference, changelog metadata, plugin
   version, and package metadata.
7. Run JavaScript/Sass lint, unit/contract/security/MCP suites, PHP syntax,
   PHPCS, PHPStan, npm/Composer audits, production build, ZIP inspection, and
   `git diff --check`.
8. Deploy to dev; run server mutation/inheritance/profile-project probes and
   visual desktop/mobile/fullscreen/standalone checks with console inspection.
9. Commit and push `main`.
10. Back up and deploy the exact tested revision to production, then verify
    plugin/schema versions, assets, REST behavior, profile/group/standalone
    boards, viewport layering, and logs.

## Acceptance criteria

- No bullets, theme indentation, duplicate app padding, or host control hiding
  is present on Network or a plain shortcode page.
- No React component renders a Dashicon class or relies on a Dashicon glyph.
- Icon-only controls have human-readable accessible names.
- Viewport mode covers the full visual viewport and the Network/sidebar layers,
  retains the current tab/filter/sidebar state, and exits cleanly.
- Creating a subtask under a project assigns the parent's project even when the
  request conflicts; changing a parent project updates all descendants.
- Moving a parent task with descendants to another board is rejected.
- The data upgrade leaves zero orphaned, cross-board, or project-mismatched
  parent/child links.
- A profile taskboard shows projects from enabled groups the user belongs to,
  disambiguated by group, and “Private only” removes them from both tasks and
  projects.
- A non-manager cannot see project mutation controls or mutate a group project.
- Project summaries refresh immediately after task mutations.
- Plain WordPress activation and shortcode rendering do not require BuddyPress
  or IARF Network.
- All automated checks pass, dev visual/server acceptance passes, `main` is
  pushed, and the production deployment is verified with a recoverable backup.

## Implementation and dev validation record

Implemented in Pandatask 1.0.13:

- dedicated shortcode mount boundary and plugin-scoped host coexistence rules;
- explicit, tree-shakeable Lucide component map across React and vanilla admin
  surfaces, with labelled icon-only controls;
- state-preserving full-viewport portal, modal/sidebar stacking, scroll lock,
  Escape ordering, and focus return;
- policy-protected, non-indexable, non-cacheable legacy fullscreen route;
- authoritative same-board parent-project inheritance, transactional descendant
  cascade, protected board moves, cache invalidation, and upgrade repair;
- private plus enabled-group project aggregation, group source labels,
  permission-aware controls, private-only filtering, and bounded task summaries;
- accessible 50-by-24-pixel checkbox hit target and keyboard focus ring for the
  private/Mine switch.

Automated validation passed:

- JavaScript and Sass lint;
- 11 architecture/contract tests and the PHP security-policy suite;
- PHP syntax, WordPress Coding Standards, and PHPStan across 51 files;
- all 22 MCP tests;
- npm and Composer security audits with no remaining advisories;
- production build, ZIP packaging inspection, and `git diff --check`.

Dev acceptance passed on the deployed plugin/schema version 1.0.13:

- controlled REST mutation coverage for create/update/delete, authorization,
  validation, three-level project inheritance/cascade, unsafe board-move
  rejection, group projects, private-only filtering, and complete fixture
  cleanup;
- zero orphaned, cross-board, or project-mismatched hierarchy links;
- a 143-task board loaded in 54 repository queries; the cold measured range was
  165.1-315.5 ms;
- a personal workspace containing a group project loaded in 3-7 queries; the
  measured range was 0.9-29.6 ms with zero duplicate project IDs;
- Network profile and plain shortcode rendering had one mount, zero Dashicons,
  no host-list markers/indentation, and no shortcode-page horizontal overflow;
- group task/project source presentation and the private-only exclusion were
  exercised with a temporary BuddyPress group;
- full view covered the 1536-by-730 visual viewport, retained the selected tab
  and filter, placed the modal above the Network shell, closed modal-first on
  Escape, restored focus, and released scroll lock;
- versioned JavaScript and CSS were served gzip-compressed from Cloudflare cache
  with immutable caching; the compatibility route returned private/no-store and
  `X-Robots-Tag: noindex, nofollow, noarchive`;
- no Pandatask fatal, uncaught, or parse errors appeared in the inspected dev
  log window;
- all temporary dev page, group, task/project data, test scripts, and the QA
  administrator were removed after acceptance.

Final bundle sizes are 200,797 bytes raw / 63,086 bytes gzip for `main.js` and
75,561 bytes raw / 12,228 bytes gzip for the LTR stylesheet. React,
ReactDOM, and the JSX runtime remain WordPress externals; lazy chunks remain
split. Webpack's generic entrypoint warning adds both LTR and RTL stylesheets,
although WordPress serves only the direction-appropriate file. A formal
DevTools performance trace was unavailable in this environment, so performance
acceptance used server query/timing probes, compiled bundle measurements,
response compression/cache headers, and real rendered-page interaction.
