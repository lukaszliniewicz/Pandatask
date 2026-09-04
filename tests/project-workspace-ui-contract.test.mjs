import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const read = ( relativePath ) =>
	fs.readFileSync( path.join( repoRoot, relativePath ), 'utf8' );

test( 'project workspaces load as a separate, lazy frontend surface', () => {
	const boardContent = read( 'src/components/board/BoardContent.jsx' );
	const projectsView = read( 'src/components/ProjectsView.jsx' );
	const mainStyles = read( 'assets/scss/main.scss' );

	assert.match(
		boardContent,
		/lazyWithRetry\(\(\) => import\('\.\.\/ProjectsView'\)\)/
	);
	assert.match( projectsView, /assets\/scss\/components\/_projects\.scss/ );
	assert.doesNotMatch( mainStyles, /components\/projects/ );
} );

test( 'project navigation keeps unassigned work useful without inventing project views', () => {
	const projectsView = read( 'src/components/ProjectsView.jsx' );
	const projectIndex = read( 'src/components/projects/ProjectIndex.jsx' );

	assert.match( projectsView, /selectedProjectId === 'none'/ );
	assert.match( projectsView, /project: 'none'/ );
	assert.match( projectsView, /<TaskList/ );
	assert.match( projectIndex, />Unassigned work</ );
	assert.match( projectIndex, /onOpenUnassigned/ );
	assert.doesNotMatch(
		projectsView,
		/UnassignedTasksView[\s\S]*ProjectFlowView/
	);
} );

test( 'project detail uses exclusive views and reserves related context for the list', () => {
	const workspace = read( 'src/components/projects/ProjectWorkspace.jsx' );
	const list = read( 'src/components/projects/ProjectWorkspaceList.jsx' );
	const flow = read( 'src/components/projects/ProjectFlowView.jsx' );

	assert.match( workspace, /role="tablist"/ );
	assert.match( workspace, /role="tabpanel"/ );
	assert.match( workspace, /currentView === "list"/ );
	assert.match( workspace, /currentView === "flow"/ );
	assert.match( workspace, /currentView === "timeline"/ );
	assert.match( list, /Related context/ );
	assert.match( list, /stay out of the action flow and timeline/ );
	assert.doesNotMatch( list, /Canonical work/ );
	assert.match( flow, /createPortal\(\s*flow,\s*document\.body\s*\)/ );
	assert.match( flow, /Open flow in full screen/ );
	assert.match( flow, /is-viewport-expanded/ );
	assert.match( flow, /is-viewport-expanded pandat69-container pandat69-root/ );
	assert.match( flow, /onPointerDown=\{\s*startPan\s*\}/ );
	assert.match( flow, /Focus relations for/ );
	assert.match( flow, /pandat69-project-flow-children/ );
	assert.match( flow, /Restricted external task/ );
} );
