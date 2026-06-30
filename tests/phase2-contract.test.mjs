import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

test('Pandatask exposes a plugin-owned token bridge', () => {
	const variables = fs.readFileSync(path.join(repoRoot, 'assets/scss/base/_variables.scss'), 'utf8');

	assert.match(variables, /\.pandat69-root/);
	assert.match(variables, /--pandatask-primary:\s*var\(--iarf-color-primary/);
	assert.match(variables, /--pandatask-font-family:\s*var\(--iarf-font-body/);
});

test('Pandatask shortcode keeps legacy mount selector and adds root alias', () => {
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');

	assert.match(shortcode, /pandat69-container pandat69-root/);
	assert.match(shortcode, /pandat69-bug-tracker-container pandat69-root/);
});

test('Pandatask does not depend on external icon packages', () => {
	const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
	const shortcode = fs.readFileSync(path.join(repoRoot, 'src/Frontend/TaskBoardShortcode.php'), 'utf8');

	assert.doesNotMatch(JSON.stringify(packageJson), /@fortawesome|font-awesome|fontawesome/i);
	assert.doesNotMatch(shortcode, /font-awesome|fontawesome|fortawesome/i);
});
