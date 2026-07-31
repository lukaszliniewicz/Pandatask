import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function methodBody(source, methodName) {
	const start = source.indexOf(`function ${methodName}(`);
	assert.notEqual(start, -1, `Expected method ${methodName} to exist`);

	const openBrace = source.indexOf('{', start);
	let depth = 0;
	for (let index = openBrace; index < source.length; index++) {
		if (source[index] === '{') {
			depth++;
		} else if (source[index] === '}') {
			depth--;
			if (depth === 0) {
				return source.slice(openBrace + 1, index);
			}
		}
	}

	assert.fail(`Could not parse method body for ${methodName}`);
}

test('Pandatask exposes a plugin-owned token bridge', () => {
	const variables = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_variables.scss'), 'utf8');

	assert.match(variables, /\.pandat69-root/);
	assert.match(variables, /--pandatask-primary:\s*var\(--iarf-color-primary/);
	assert.match(variables, /--pandatask-font-family:\s*var\(--iarf-font-body/);
});

test('Pandatask shortcode uses a dedicated mount boundary and IARF aliases', () => {
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');
	const frontend = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/FrontendRegistrar.php'), 'utf8');
	const entry = fs.readFileSync(path.join(repoRoot, 'src/index.jsx'), 'utf8');

	assert.match(shortcode, /pandat69-mount pandat69-root/);
	assert.match(shortcode, /data-pandatask-board-root/);
	assert.match(shortcode, /pandat69-bug-tracker-container pandat69-root/);
	assert.match(entry, /querySelectorAll\(\s*'\[data-pandatask-board-root\]'/);
	assert.match(entry, /classList\.remove\(\s*'pandat69-container',\s*'pandat69-viewport-shell'\s*\)/);
	for (const source of [shortcode, frontend, entry]) {
		assert.match(source, /iarf-app--pandatask/);
		assert.match(source, /iarf-plugin--pandatask/);
		assert.match(source, /data-iarf-product/);
		assert.match(source, /data-iarf-app/);
		assert.match(source, /data-iarf-plugin/);
	}
	assert.match(shortcode, /data-iarf-product-kind="react-plugin"/);
	assert.match(frontend, /data-iarf-product-kind="react-plugin"/);
});

test('Pandatask frontend CSS includes a scoped coexistence contract', () => {
	const entry = fs.readFileSync(path.join(repoRoot, 'assets/scss/main.scss'), 'utf8');
	const coexistence = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_coexistence.scss'), 'utf8');
	const base = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_base.scss'), 'utf8');

	assert.match(entry, /base\/coexistence/);
	assert.match(coexistence, /\.pandat69-root,\s*\n\.pandat69-container/);
	assert.match(coexistence, /--pandatask-ui-font-family:\s*var\(--pandatask-font-family/);
	assert.match(coexistence, /\.pandat69-root :where\(button, input, select, textarea\)/);
	assert.match(coexistence, /\.pandat69-button,/);
	assert.match(coexistence, /\.pandat69-icon-button,/);
	assert.match(coexistence, /\.pandat69-icon/);
	assert.match(coexistence, /UI lists/);
	assert.match(base, /font-family:\s*var\(--pandatask-ui-font-family/);
	assert.match(base, /color:\s*var\(--pandatask-primary/);
	assert.doesNotMatch(coexistence, /--iarf-color-primary\s*:/);
	assert.doesNotMatch(coexistence, /--iarf-font-body\s*:/);

	const forms = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_forms.scss'), 'utf8');
	assert.doesNotMatch(forms, /^\.nice-select/m);
	assert.doesNotMatch(forms, /^\.mce-/m);
});

test('Pandatask registers assets globally but only enqueues on owned surfaces', () => {
	const assets = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/AssetRegistrar.php'), 'utf8');
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');
	const registerFrontendAssetHandles = methodBody(assets, 'registerFrontendAssetHandles');
	const enqueueFrontendAssetHandles = methodBody(assets, 'enqueueFrontendAssetHandles');
	const registerFloatingReporterAssetHandles = methodBody(assets, 'registerFloatingReporterAssetHandles');
	const maybeEnqueueFloatingReporterAssets = methodBody(assets, 'maybeEnqueueFloatingReporterAssets');
	const shouldEnqueueFrontendAssets = methodBody(assets, 'shouldEnqueueFrontendAssets');
	const floatingLauncher = fs.readFileSync(path.join(repoRoot, 'assets/js/floating-bug-reporter.js'), 'utf8');
	const groupTasks = fs.readFileSync(path.join(repoRoot, 'src/Integration/BuddyPress/GroupTasksExtension.php'), 'utf8');
	const groupBugTracker = fs.readFileSync(path.join(repoRoot, 'src/Integration/BuddyPress/GroupBugTrackerExtension.php'), 'utf8');
	const profileTasks = fs.readFileSync(path.join(repoRoot, 'src/Integration/BuddyPress/ProfileTasksPage.php'), 'utf8');

	assert.match(assets, /add_action\(\s*'wp_enqueue_scripts',\s*array\(\s*\$this,\s*'registerFrontendAssets'\s*\)\s*\)/);
	assert.match(assets, /add_action\(\s*'wp_enqueue_scripts',\s*array\(\s*\$this,\s*'registerFloatingReporterAssets'\s*\)\s*\)/);
	assert.match(assets, /add_action\(\s*'wp_enqueue_scripts',\s*array\(\s*\$this,\s*'maybeEnqueueFrontendAssets'\s*\),\s*20\s*\)/);
	assert.doesNotMatch(assets, /add_action\(\s*'bp_enqueue_scripts',\s*array\(\s*\$this,\s*'maybeEnqueueFrontendAssets'/);
	assert.match(assets, /add_action\(\s*'wp_enqueue_scripts',\s*array\(\s*\$this,\s*'maybeEnqueueFloatingReporterAssets'\s*\),\s*30\s*\)/);
	assert.match(registerFrontendAssetHandles, /wp_register_style\(\s*'pandat69-style'/);
	assert.match(registerFrontendAssetHandles, /wp_register_script\(\s*'pandat69-bundle'/);
	assert.doesNotMatch(registerFrontendAssetHandles, /wp_enqueue_style\(/);
	assert.doesNotMatch(registerFrontendAssetHandles, /wp_enqueue_script\(/);
	assert.doesNotMatch(registerFrontendAssetHandles, /wp_enqueue_editor\(/);
	assert.doesNotMatch(registerFrontendAssetHandles, /wp_enqueue_media\(/);
	assert.match(assets, /public static function enqueueFrontendAssetHandles\(\)/);
	assert.match(enqueueFrontendAssetHandles, /wp_enqueue_style\(\s*'pandat69-style'\s*\)/);
	assert.match(enqueueFrontendAssetHandles, /wp_enqueue_script\(\s*'pandat69-bundle'\s*\)/);
	assert.match(registerFloatingReporterAssetHandles, /wp_register_style\(\s*'pandat69-floating-reporter-style'/);
	assert.match(registerFloatingReporterAssetHandles, /wp_register_script\(\s*'pandat69-floating-reporter'/);
	assert.match(registerFloatingReporterAssetHandles, /fullScriptUrl/);
	assert.match(registerFloatingReporterAssetHandles, /fullStyleUrl/);
	assert.doesNotMatch(registerFloatingReporterAssetHandles, /wp_enqueue_style\(/);
	assert.doesNotMatch(registerFloatingReporterAssetHandles, /wp_enqueue_script\(/);
	assert.match(maybeEnqueueFloatingReporterAssets, /floatingBugReporterIsVisible\(\)/);
	assert.match(maybeEnqueueFloatingReporterAssets, /shouldEnqueueFrontendAssets\(\)/);
	assert.match(maybeEnqueueFloatingReporterAssets, /enqueueFloatingReporterAssetHandles\(\)/);
	assert.match(assets, /function shouldEnqueueFrontendAssets\(\)/);
	assert.match(assets, /has_shortcode\(\s*\$post->post_content,\s*'task_board'\s*\)/);
	assert.match(assets, /has_shortcode\(\s*\$post->post_content,\s*'pandatask_bug_tracker'\s*\)/);
	assert.match(assets, /get_query_var\(\s*'pandatask_fullscreen_page'\s*\)/);
	assert.match(assets, /preg_match\(\s*'#\/\(groups\|members\)\/\[\^\/\]\+\/\(tasks\|bug-tracker\)\(\/\|\$\)#'/);
	assert.match(assets, /bp_is_current_action\(\s*'tasks'\s*\)/);
	assert.match(assets, /bp_is_current_action\(\s*'bug-tracker'\s*\)/);
	assert.doesNotMatch(shouldEnqueueFrontendAssets, /floatingBugReporterIsVisible/);
	assert.match(floatingLauncher, /mountFloatingBugReporter/);
	assert.match(floatingLauncher, /fullScriptUrl/);
	assert.match(shortcode, /function enqueue_assets\(\)/);
	assert.match(shortcode, /AssetRegistrar::enqueueFrontendAssetHandles\(\)/);
	assert.match(groupTasks, /AssetRegistrar::enqueueFrontendAssetHandles\(\)/);
	assert.match(groupBugTracker, /AssetRegistrar::enqueueFrontendAssetHandles\(\)/);
	assert.match(profileTasks, /AssetRegistrar::enqueueFrontendAssetHandles\(\)/);
});

test('Pandatask uses an explicit Lucide icon boundary without Dashicons', () => {
	const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');
	const assets = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/AssetRegistrar.php'), 'utf8');
	const assetManifest = fs.readFileSync(path.join(repoRoot, 'build/main.asset.php'), 'utf8');
	const icon = fs.readFileSync(path.join(repoRoot, 'src/components/Icon.jsx'), 'utf8');
	const componentSources = fs
		.readdirSync(path.join(repoRoot, 'src/components'))
		.filter((file) => file.endsWith('.jsx'))
		.map((file) => fs.readFileSync(path.join(repoRoot, 'src/components', file), 'utf8'))
		.join('\n');
	const scssSources = fs
		.readdirSync(path.join(repoRoot, 'assets/scss'), { recursive: true })
		.filter((file) => file.endsWith('.scss'))
		.map((file) => fs.readFileSync(path.join(repoRoot, 'assets/scss', file), 'utf8'))
		.join('\n');

	assert.match(packageJson.dependencies['lucide-react'], /^\^1\.\d+\.\d+$/);
	assert.match(icon, /from 'lucide-react'/);
	assert.doesNotMatch(icon, /import\s+\*\s+as/);
	assert.doesNotMatch(componentSources, /dashicons/i);
	assert.doesNotMatch(scssSources, /dashicons/i);
	assert.doesNotMatch(JSON.stringify(packageJson), /@fortawesome|font-awesome|fontawesome/i);
	assert.doesNotMatch(shortcode, /font-awesome|fontawesome|fortawesome/i);
	assert.doesNotMatch(assets, /font-awesome|fontawesome|fortawesome/i);
	assert.doesNotMatch(assetManifest, /font-awesome|fontawesome|fortawesome/i);
});

test('Pandatask full view is state-preserving, host-covering, and modal-safe', () => {
	const layout = fs.readFileSync(path.join(repoRoot, 'src/components/Layout.jsx'), 'utf8');
	const fullscreen = fs.readFileSync(path.join(repoRoot, 'src/hooks/useBoardFullscreen.js'), 'utf8');
	const base = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_base.scss'), 'utf8');
	const header = fs.readFileSync(path.join(repoRoot, 'assets/scss/layouts/_header.scss'), 'utf8');
	const modal = fs.readFileSync(path.join(repoRoot, 'assets/scss/components/_modal.scss'), 'utf8');
	const sidebar = fs.readFileSync(path.join(repoRoot, 'assets/scss/components/_sidebar.scss'), 'utf8');

	assert.match(layout, /createPortal\(board, document\.body\)/);
	assert.match(fullscreen, /pandat69-viewport-open/);
	assert.match(fullscreen, /pandat69-viewport-shell/);
	assert.match(fullscreen, /event\.key !== 'Escape'/);
	assert.match(base, /z-index:\s*var\(--pandatask-viewport-z,\s*2147483000\)/);
	assert.match(modal, /--pandatask-modal-z,\s*2147483600/);
	assert.match(sidebar, /--pandatask-sidebar-z,\s*2147483400/);
	assert.match(sidebar, /position:\s*fixed/);
	assert.match(header, /pandat69-fullscreen-body[\s\S]*#iarf-network-shell-root/);
});

test('Personal workspaces aggregate group projects with a private-only escape hatch', () => {
	const service = fs.readFileSync(path.join(repoRoot, 'src/Application/Project/ProjectService.php'), 'utf8');
	const repository = fs.readFileSync(path.join(repoRoot, 'src/Infrastructure/Persistence/ProjectRepository.php'), 'utf8');
	const route = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/ProjectRouteHandler.php'), 'utf8');
	const hook = fs.readFileSync(path.join(repoRoot, 'src/hooks/useProjects.js'), 'utf8');

	assert.match(service, /getUserWritableBoards\(\s*\$user_id\s*\)/);
	assert.match(service, /findForUserWorkspace/);
	assert.match(service, /board_scope/);
	assert.match(repository, /findForUserWorkspace/);
	assert.match(repository, /EXISTS \(\s*SELECT 1 FROM .*assignments/s);
	assert.doesNotMatch(repository, /GROUP_CONCAT\(DISTINCT CONCAT\(t\./);
	assert.match(route, /private_only/);
	assert.match(hook, /private_only/);
});

test('Subtask projects are authoritative, cascaded, and repaired on upgrade', () => {
	const handler = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/TaskRouteHandler.php'), 'utf8');
	const mutations = fs.readFileSync(path.join(repoRoot, 'src/Application/Task/TaskMutationService.php'), 'utf8');
	const lifecycle = fs.readFileSync(path.join(repoRoot, 'src/Infrastructure/Setup/DatabaseLifecycle.php'), 'utf8');

	assert.match(handler, /applyParentProjectInheritance/);
	assert.match(handler, /\$data\['project_id'\]\s*=\s*\$parent->project_id/);
	assert.match(mutations, /findDescendantProjectRecords/);
	assert.match(mutations, /updateProjectForTasks/);
	assert.match(mutations, /Inherited from the parent task project/);
	assert.match(mutations, /pandatask_task_has_children/);
	assert.match(lifecycle, /DB_VERSION = '1\.0\.13'/);
	assert.match(lifecycle, /repairProjectInheritance/);
	assert.match(lifecycle, /child\.project_id <=> parent\.project_id/);
	assert.match(lifecycle, /child\.board_name <> parent\.board_name/);
	assert.match(lifecycle, /SET child\.parent_task_id = NULL/);
});

test('Legacy fullscreen compatibility route enforces policy and blocks indexing', () => {
	const frontend = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/FrontendRegistrar.php'), 'utf8');
	const template = fs.readFileSync(path.join(repoRoot, 'templates/fullscreen-template.php'), 'utf8');

	assert.match(frontend, /BoardAccessPolicy/);
	assert.match(frontend, /canReadBoard/);
	assert.match(frontend, /X-Robots-Tag: noindex, nofollow, noarchive/);
	assert.match(frontend, /nocache_headers/);
	assert.match(template, /true === \$pandatask_fullscreen_access/);
	assert.doesNotMatch(template, /any logged-in user can see non-group boards/);
});

test('Pandatask protects task attachments without web-server-specific delivery', () => {
	const plugin = fs.readFileSync(path.join(repoRoot, 'src/Plugin.php'), 'utf8');
	const media = fs.readFileSync(path.join(repoRoot, 'src/Infrastructure/Media/ProtectedAttachmentService.php'), 'utf8');
	const tasks = fs.readFileSync(path.join(repoRoot, 'src/Application/Task/TaskService.php'), 'utf8');
	const mutations = fs.readFileSync(path.join(repoRoot, 'src/Application/Task/TaskMutationService.php'), 'utf8');
	const policy = fs.readFileSync(path.join(repoRoot, 'src/Application/Security/BoardAccessPolicy.php'), 'utf8');

	assert.match(plugin, /ProtectedAttachmentService::registerHooks\(\)/);
	assert.match(media, /pandatask\/v1.*protected-attachments/s);
	assert.match(media, /dirname\(\s*rtrim\(\s*ABSPATH/);
	assert.match(media, /pathIsPublic/);
	assert.match(media, /hash_hmac\(\s*'sha256'/);
	assert.match(media, /hash_equals/);
	assert.match(media, /\$viewer_id !== \$current_user_id/);
	assert.match(media, /PANDATASK_MAX_PROTECTED_ATTACHMENT_BYTES/);
	assert.match(media, /Accept-Ranges: bytes/);
	assert.match(media, /Cache-Control: private, no-store/);
	assert.match(media, /normalizePermissions/);
	assert.match(media, /migrate-protected-attachments/);
	assert.match(media, /isset\(\s*\$assoc_args\['write'\]\s*\)/);
	assert.match(media, /findProtectedSourceForAttachment/);
	assert.doesNotMatch(media, /X-Accel-Redirect/);
	assert.match(tasks, /set_transient\([\s\S]*ProtectedAttachmentService::prepareTasks/);
	assert.match(tasks, /ProtectedAttachmentService::prepareTask/);
	assert.match(mutations, /ProtectedAttachmentService::syncTask\(\s*\$task_id\s*\)/);
	assert.match(mutations, /ProtectedAttachmentService::deleteTaskFiles\(\s*\$task_id\s*\)/);
	assert.match(policy, /user_can\(\s*\$user_id,\s*'manage_options'\s*\)/);
	assert.match(policy, /user_can\(\s*\$user_id,\s*'edit_posts'\s*\)/);
	assert.doesNotMatch(policy, /current_user_can/);
});

test('Pandatask REST API exposes bounded pagination, site metadata, and mutation idempotency', () => {
	const restApi = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/RestApi.php'), 'utf8');
	const registrar = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/RouteRegistrar.php'), 'utf8');
	const handler = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/TaskRouteHandler.php'), 'utf8');
	const middleware = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/IdempotencyMiddleware.php'), 'utf8');

	assert.match(restApi, /IdempotencyMiddleware::register\(\)/);
	assert.match(registrar, /'\/meta'/);
	assert.match(registrar, /'limit'\s*=>\s*array/);
	assert.match(registrar, /'offset'\s*=>\s*array/);
	assert.match(handler, /'pagination'\s*=>\s*array/);
	assert.match(handler, /:\s*500;/);
	assert.match(middleware, /rest_request_before_callbacks/);
	assert.match(middleware, /rest_request_after_callbacks/);
	assert.match(middleware, /pandatask_idempotency_conflict/);
	assert.match(middleware, /pandatask_idempotency_in_progress/);
	assert.match(middleware, /add_option\(/);
	assert.match(middleware, /DAY_IN_SECONDS/);
	assert.match(middleware, /get_current_user_id\(\)/);
});

test('Frontend navigation, mounting, and embedded layout are host-safe', () => {
	const entry = fs.readFileSync(path.join(repoRoot, 'src/index.jsx'), 'utf8');
	const navigation = fs.readFileSync(path.join(repoRoot, 'src/hooks/useBoardNavigation.js'), 'utf8');
	const containerMode = fs.readFileSync(path.join(repoRoot, 'src/hooks/useContainerMode.js'), 'utf8');
	const responsive = fs.readFileSync(path.join(repoRoot, 'assets/scss/layouts/_responsive.scss'), 'utf8');

	assert.match(entry, /new WeakMap\(\)/);
	assert.match(entry, /\.\.\.\( window\.Pandatask \|\| \{\} \)/);
	assert.match(entry, /document\.readyState === 'loading'/);
	assert.match(entry, /queueMicrotask\( bootstrapStandaloneMounts \)/);
	assert.match(navigation, /pandatask_tab/);
	assert.match(navigation, /pandatask_view/);
	assert.match(navigation, /open_task/);
	assert.match(navigation, /popstate/);
	assert.match(containerMode, /ResizeObserver/);
	assert.match(containerMode, /COMPACT_BOARD_WIDTH = 1080/);
	assert.match(responsive, /\.pandat69-container\.is-container-narrow/);
});

test('Frontend cache policy and typed API boundary are centralized', () => {
	const keys = fs.readFileSync(path.join(repoRoot, 'src/query/queryKeys.ts'), 'utf8');
	const client = fs.readFileSync(path.join(repoRoot, 'src/api/client.ts'), 'utf8');
	const queryClient = fs.readFileSync(path.join(repoRoot, 'src/query/createQueryClient.ts'), 'utf8');
	const taskHook = fs.readFileSync(path.join(repoRoot, 'src/hooks/useTasks.js'), 'utf8');

	assert.match(keys, /export const queryKeys/);
	assert.match(client, /class PandataskApiError/);
	assert.match(client, /readonly status/);
	assert.match(queryClient, /createPandataskQueryClient/);
	assert.match(queryClient, /\[ 400, 401, 403, 404, 409, 422 \]/);
	assert.match(taskHook, /queryKeys\.tasks\.list/);
	assert.match(taskHook, /\{ params, signal \}/);
	assert.match(taskHook, /params\.append\( 'limit', '500' \)/);
});

test('Large frontend surfaces keep state, models, and render sections decomposed', () => {
	const layout = fs.readFileSync(path.join(repoRoot, 'src/components/Layout.jsx'), 'utf8');
	const ganttView = fs.readFileSync(path.join(repoRoot, 'src/components/GanttView.jsx'), 'utf8');
	const taskDetail = fs.readFileSync(path.join(repoRoot, 'src/components/TaskDetail.jsx'), 'utf8');
	const taskForm = fs.readFileSync(path.join(repoRoot, 'src/components/TaskForm.jsx'), 'utf8');
	const boardShell = fs.readFileSync(path.join(repoRoot, 'src/components/board/BoardShell.jsx'), 'utf8');
	const boardController = fs.readFileSync(path.join(repoRoot, 'src/hooks/useBoardController.js'), 'utf8');
	const ganttFacade = fs.readFileSync(path.join(repoRoot, 'src/ganttModel.mjs'), 'utf8');
	const ganttRow = fs.readFileSync(path.join(repoRoot, 'src/components/gantt/GanttRow.jsx'), 'utf8');

	assert.ok(layout.split('\n').length < 80);
	assert.ok(ganttView.split('\n').length < 250);
	assert.ok(taskDetail.split('\n').length < 200);
	assert.ok(taskForm.split('\n').length < 300);
	assert.match(layout, /BoardShell/);
	assert.match(boardShell, /BoardDialogLayer/);
	assert.match(boardController, /const \[ dialog, setDialog \]/);
	assert.match(boardController, /kind: 'task'/);
	assert.match(ganttFacade, /gantt\/model\.mjs/);
	assert.match(ganttRow, /gantt\/geometry\.mjs/);
	assert.equal(fs.existsSync(path.join(repoRoot, 'src/components/BoardModals.jsx')), false);
});

test('Interactive frontend primitives expose native accessibility semantics', () => {
	const modal = fs.readFileSync(path.join(repoRoot, 'src/components/Modal.jsx'), 'utf8');
	const taskSelect = fs.readFileSync(path.join(repoRoot, 'src/components/TaskSelect.jsx'), 'utf8');
	const base = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_base.scss'), 'utf8');

	assert.match(modal, /<dialog/);
	assert.match(modal, /\.showModal\(\)/);
	assert.match(modal, /aria-labelledby/);
	assert.doesNotMatch(modal, /role="dialog"/);
	assert.match(taskSelect, /role="combobox"/);
	assert.match(taskSelect, /aria-expanded/);
	assert.match(base, /\.pandat69-visually-hidden/);
});

test('Production deployment keeps rollback active through exact release verification', () => {
	const deploy = fs.readFileSync(path.join(repoRoot, 'deploy.ps1'), 'utf8');

	assert.match(deploy, /ExpectedProductionDir = '\/home\/iarf\/htdocs\/iarf\.net\/wp-content\/plugins\/pandatask'/);
	assert.match(deploy, /set -Eeuo pipefail/);
	assert.match(deploy, /trap rollback ERR/);
	assert.match(deploy, /while IFS= read -r -d '' php_file/);
	assert.match(deploy, /sha256sum -c -/);
	assert.match(deploy, /wp plugin is-active pandatask/);
	assert.match(deploy, /actual_version=.*wp plugin get pandatask/);
	assert.ok(
		deploy.lastIndexOf('trap - ERR') <
			deploy.lastIndexOf('rm -rf $RemotePreviousDirQ')
	);
});

test('Security-sensitive task edits and public intake have explicit guards', () => {
	const policy = fs.readFileSync(path.join(repoRoot, 'src/Application/Security/TaskAccessPolicy.php'), 'utf8');
	const handler = fs.readFileSync(path.join(repoRoot, 'src/Http/Rest/V1/TaskRouteHandler.php'), 'utf8');
	const publicPolicy = fs.readFileSync(path.join(repoRoot, 'src/Application/Security/PublicBugSubmissionPolicy.php'), 'utf8');
	const comments = fs.readFileSync(path.join(repoRoot, 'src/Application/Security/CommentAccessPolicy.php'), 'utf8');

	assert.match(policy, /canManageTaskRoles/);
	assert.match(policy, /canMoveTask/);
	assert.match(handler, /validateSensitiveTaskUpdate/);
	assert.match(handler, /board-scoped relationship/);
	assert.match(handler, /pandatask_minimum_task_date/);
	assert.match(publicPolicy, /consumeAnonymousSubmissionBudget/);
	assert.match(publicPolicy, /REMOTE_ADDR/);
	assert.match(comments, /canReadTask/);
});
