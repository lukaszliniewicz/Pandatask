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

test('Pandatask shortcode keeps legacy mount selector and adds root alias', () => {
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');
	const frontend = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/FrontendRegistrar.php'), 'utf8');
	const entry = fs.readFileSync(path.join(repoRoot, 'src/index.jsx'), 'utf8');

	assert.match(shortcode, /pandat69-container pandat69-root/);
	assert.match(shortcode, /pandat69-bug-tracker-container pandat69-root/);
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
	assert.match(coexistence, /\.pandat69-root :where\(\.dashicons\)/);
	assert.match(base, /font-family:\s*var\(--pandatask-ui-font-family/);
	assert.match(base, /color:\s*var\(--pandatask-primary/);
	assert.doesNotMatch(coexistence, /--iarf-color-primary\s*:/);
	assert.doesNotMatch(coexistence, /--iarf-font-body\s*:/);
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

test('Pandatask does not depend on external icon packages', () => {
	const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');
	const assets = fs.readFileSync(path.join(repoRoot, 'src/Bootstrap/AssetRegistrar.php'), 'utf8');
	const assetManifest = fs.readFileSync(path.join(repoRoot, 'build/main.asset.php'), 'utf8');

	assert.doesNotMatch(JSON.stringify(packageJson), /@fortawesome|font-awesome|fontawesome/i);
	assert.doesNotMatch(shortcode, /font-awesome|fontawesome|fortawesome/i);
	assert.doesNotMatch(assets, /font-awesome|fontawesome|fortawesome/i);
	assert.doesNotMatch(assetManifest, /font-awesome|fontawesome|fortawesome/i);
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
	assert.match(middleware, /rest_request_before_callbacks/);
	assert.match(middleware, /rest_request_after_callbacks/);
	assert.match(middleware, /pandatask_idempotency_conflict/);
	assert.match(middleware, /pandatask_idempotency_in_progress/);
	assert.match(middleware, /add_option\(/);
	assert.match(middleware, /DAY_IN_SECONDS/);
	assert.match(middleware, /get_current_user_id\(\)/);
});
