import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const webpack = require( 'webpack' );
const sass = require( 'sass' );
const { chromium } = require( 'playwright' );
const root = path.dirname( path.dirname( fileURLToPath( import.meta.url ) ) );
const output = await fs.mkdtemp( path.join( os.tmpdir(), 'pandatask-checklist-ui-' ) );
const compiler = webpack( {
	mode: 'development',
	devtool: false,
	entry: path.join( root, 'tests/fixtures/task-checklist.jsx' ),
	output: { path: output, filename: 'fixture.js' },
	resolve: { extensions: [ '.js', '.jsx', '.ts', '.tsx', '.mjs' ] },
	module: { rules: [ {
		test: /\.[jt]sx?$/,
		exclude: /node_modules/,
		use: { loader: require.resolve( 'babel-loader' ), options: {
			babelrc: false,
			configFile: false,
			presets: [ require.resolve( '@babel/preset-react' ), require.resolve( '@babel/preset-typescript' ) ],
		} },
	} ] },
} );
await new Promise( ( resolve, reject ) => compiler.run( ( error, stats ) => {
	compiler.close( () => {} );
	if ( error || stats.hasErrors() ) {
		reject( error || new Error( stats.toString( { all: false, errors: true } ) ) );
	} else {
		resolve();
	}
} ) );
const css = sass.compile( path.join( root, 'assets/scss/main.scss' ), { silenceDeprecations: [ 'legacy-js-api' ] } ).css;
await fs.writeFile( path.join( output, 'fixture.css' ), css );
await fs.writeFile( path.join( output, 'index.html' ), `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pandatask checklist acceptance</title><link rel="stylesheet" href="fixture.css"><style>body{margin:0;background:#f4f5f7;padding:24px;font-family:system-ui}.pandat69-container{max-width:1000px;margin:auto;padding:24px;display:block}.fixture-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:24px}h1{font-size:24px}.pandat69-kanban-card{margin:0}@media(max-width:640px){body{padding:8px}.pandat69-container{padding:14px}.fixture-layout{grid-template-columns:minmax(0,1fr)}}</style><div id="root"></div><script src="fixture.js"></script></html>` );
const server = http.createServer( async ( request, response ) => {
	const pathname = new URL( request.url, 'http://localhost' ).pathname;
	const filename = pathname === '/' ? 'index.html' : path.basename( pathname );
	try {
		const content = await fs.readFile( path.join( output, filename ) );
		response.setHeader( 'Content-Type', filename.endsWith( '.js' ) ? 'text/javascript' : filename.endsWith( '.css' ) ? 'text/css' : 'text/html' );
		response.end( content );
	} catch {
		response.writeHead( 404 ).end();
	}
} );
await new Promise( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
const url = `http://127.0.0.1:${ server.address().port }`;
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( { viewport: { width: 1200, height: 900 } } );
const errors = [];
page.on( 'pageerror', ( error ) => errors.push( error.message ) );
let savedRequests = 0;
const waitSaved = async () => {
	await page.waitForFunction( ( count ) => window.checklistFixture.requests.length > count, savedRequests );
	await page.waitForFunction( ( count ) => window.checklistFixture.completedRequests() > count, savedRequests );
	await page.waitForFunction( () => document.querySelector( '.pandat69-checklist' )?.getAttribute( 'aria-busy' ) === 'false' );
	savedRequests = await page.evaluate( () => window.checklistFixture.requests.length );
};
const itemLabels = () => page.locator( '.pandat69-checklist-row input[type=checkbox]' ).evaluateAll( ( inputs ) => inputs.map( ( input ) => input.getAttribute( 'aria-label' ) ) );
try {
	await page.goto( url );
	await page.getByRole( 'checkbox', { name: 'Send test email', exact: true } ).click();
	await waitSaved();
	assert.equal( await page.getByRole( 'checkbox', { name: 'Send test email', exact: true } ).isChecked(), true );
	assert.equal( await page.locator( '.pandat69-checklist-count' ).first().textContent(), '2/3 checked' );
	assert.equal( await page.locator( '[aria-label="Board preview"] .pandat69-checklist-count' ).textContent(), '2/3 checked' );
	await page.getByRole( 'checkbox', { name: 'Schedule delivery', exact: true } ).click();
	await waitSaved();
	assert.equal( await page.evaluate( () => window.checklistFixture.getTask().status ), 'pending' );
	await page.getByRole( 'checkbox', { name: 'Check links', exact: true } ).click();
	await waitSaved();
	await page.getByRole( 'button', { name: 'Edit checklist item: Send test email', exact: true } ).click();
	await page.getByRole( 'textbox', { name: 'Checklist item text', exact: true } ).fill( 'Send an accessible test email' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'Send an accessible test email', exact: true } ).waitFor();
	await page.getByRole( 'button', { name: 'Move up: Schedule delivery', exact: true } ).click();
	await waitSaved();
	assert.deepEqual( await itemLabels(), [ 'Check links', 'Schedule delivery', 'Send an accessible test email' ] );
	const addInput = page.getByRole( 'textbox', { name: 'Add a checklist item', exact: true } );
	await addInput.fill( 'Check the subject line' );
	await addInput.press( 'Enter' );
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'Check the subject line', exact: true } ).waitFor();
	await page.getByRole( 'button', { name: 'Delete checklist item: Check the subject line', exact: true } ).click();
	await waitSaved();
	assert.equal( ( await itemLabels() ).length, 3 );

	await page.evaluate( () => window.checklistFixture.externalEdit() );
	await addInput.fill( 'Review the preview text' );
	await addInput.press( 'Enter' );
	await page.getByRole( 'alert' ).waitFor();
	await waitSaved();
	assert.match( await page.getByRole( 'alert' ).textContent(), /changed elsewhere/ );
	assert.equal( await addInput.inputValue(), 'Review the preview text' );
	await page.getByRole( 'checkbox', { name: 'Check accessibility', exact: true } ).waitFor();
	await addInput.press( 'Enter' );
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'Review the preview text', exact: true } ).waitFor();
	assert.equal( ( await itemLabels() ).length, 5 );

	await page.evaluate( () => window.checklistFixture.failNext() );
	await addInput.fill( 'Preserve this draft' );
	await addInput.press( 'Enter' );
	await page.getByRole( 'alert' ).waitFor();
	await waitSaved();
	assert.equal( await addInput.inputValue(), 'Preserve this draft' );
	assert.equal( ( await itemLabels() ).length, 5 );
	await page.evaluate( () => window.checklistFixture.failNext( 'after' ) );
	await addInput.press( 'Enter' );
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'Preserve this draft', exact: true } ).waitFor();
	await addInput.press( 'Enter' );
	await waitSaved();
	assert.equal( await page.getByRole( 'checkbox', { name: 'Preserve this draft', exact: true } ).count(), 1 );
	await page.getByRole( 'button', { name: 'Edit checklist item: Check links', exact: true } ).click();
	await page.getByRole( 'textbox', { name: 'Checklist item text', exact: true } ).fill( 'Check every link' );
	await page.evaluate( () => window.checklistFixture.externalDelete( 'links' ) );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await waitSaved();
	assert.equal( await page.getByRole( 'textbox', { name: 'Checklist item text', exact: true } ).inputValue(), 'Check every link' );
	await page.getByRole( 'button', { name: 'Restore item', exact: true } ).click();
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'Check every link', exact: true } ).waitFor();
	assert.equal( await addInput.isEnabled(), true );
	await page.getByText( 'Previous occurrence checklist', { exact: true } ).click();
	assert.match( await page.getByRole( 'region', { name: 'Previous occurrence' } ).textContent(), /Check links/ );
	await page.screenshot( { path: path.join( output, 'desktop.png' ), fullPage: true } );
	await page.setViewportSize( { width: 390, height: 844 } );
	await addInput.fill( 'A very long checklist item with enough words to wrap across several lines on a phone without covering its checkbox or action buttons' );
	await addInput.press( 'Enter' );
	await waitSaved();
	assert.equal( await page.evaluate( () => document.documentElement.scrollWidth <= innerWidth ), true );
	await page.screenshot( { path: path.join( output, 'mobile.png' ), fullPage: true } );

	await page.goto( `${ url }/?mode=readonly` );
	await page.getByRole( 'checkbox', { name: 'Check links', exact: true } ).waitFor();
	assert.equal( await page.getByRole( 'checkbox', { name: 'Check links', exact: true } ).isDisabled(), true );
	assert.equal( await page.getByRole( 'textbox', { name: 'Add a checklist item', exact: true } ).count(), 0 );
	await page.goto( `${ url }/?mode=empty` );
	savedRequests = 0;
	await page.getByRole( 'button', { name: 'Add checklist', exact: true } ).click();
	await page.getByRole( 'textbox', { name: 'Add a checklist item', exact: true } ).fill( 'First small step' );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await waitSaved();
	await page.getByRole( 'checkbox', { name: 'First small step', exact: true } ).waitFor();
	const unicodeText = '😀'.repeat( 500 );
	await addInput.fill( unicodeText );
	assert.equal( await addInput.inputValue(), unicodeText );
	await addInput.press( 'Enter' );
	await waitSaved();
	await page.getByRole( 'checkbox', { name: unicodeText, exact: true } ).waitFor();
	await page.getByRole( 'button', { name: `Edit checklist item: ${ unicodeText }`, exact: true } ).click();
	assert.equal( await page.getByRole( 'textbox', { name: 'Checklist item text', exact: true } ).inputValue(), unicodeText );
	await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
	await addInput.fill( 'a'.repeat( 501 ) );
	assert.equal( await addInput.inputValue(), 'a'.repeat( 500 ) );
	assert.deepEqual( errors, [] );
	console.log( `Checklist UI passed: CRUD, reorder, keyboard entry, counts, manual completion, conflict, failed/uncertain saves, history, read-only and mobile layout. Screenshots: ${ output }` );
} catch ( error ) {
	console.error( await page.evaluate( () => ( { task: window.checklistFixture?.getTask(), requests: window.checklistFixture?.requests, error: document.querySelector( '[role=alert]' )?.textContent } ) ) );
	await page.screenshot( { path: path.join( output, 'failure.png' ), fullPage: true } );
	console.error( `Failure screenshot: ${ output }/failure.png` );
	throw error;
} finally {
	await browser.close();
	await new Promise( ( resolve ) => server.close( resolve ) );
}
