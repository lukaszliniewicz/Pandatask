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
const output = await fs.mkdtemp( path.join( os.tmpdir(), 'pandatask-recurrence-ui-' ) );
const compiler = webpack( {
	mode: 'development',
	devtool: false,
	entry: path.join( root, 'tests/fixtures/task-recurrence.jsx' ),
	output: { path: output, filename: 'fixture.js' },
	resolve: { extensions: [ '.js', '.jsx', '.ts', '.tsx', '.mjs' ] },
	module: { rules: [ {test: /mermaid\.min\.js$/, type: 'asset/resource'}, {test: /\.scss$/, type: 'asset/source'}, {
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
const css = sass.compile( path.join(root,'assets/scss/components/_rich-content.scss') ).css + sass.compile( path.join( root, 'assets/scss/main.scss' ), { silenceDeprecations: [ 'legacy-js-api' ] } ).css;
await fs.writeFile( path.join( output, 'fixture.css' ), css );
await fs.writeFile( path.join( output, 'index.html' ), `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pandatask recurrence acceptance</title><link rel="stylesheet" href="fixture.css"><style>body{margin:0;background:#f4f5f7;padding:24px;font-family:system-ui}.pandat69-container{max-width:1000px;margin:auto;padding:24px;display:block}.fixture-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:24px}h1{font-size:24px}.pandat69-kanban-card{margin:0}@media(max-width:640px){body{padding:8px}.pandat69-container{padding:14px}.fixture-layout{grid-template-columns:minmax(0,1fr)}}</style><div id="root"></div><script src="fixture.js"></script></html>` );
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
try {
    await page.goto(url);
    await page.getByRole('button', {name:'Use these steps for future occurrences'}).click();
    await page.getByRole('status').filter({hasText:'Future occurrences will start'}).waitFor();
    assert.equal(await page.evaluate(()=>window.recurrenceFixture.requests[0].body.recurrence_scope),'future');
    await page.getByRole('checkbox',{name:'Review newsletter',exact:true}).click();
    await page.waitForFunction(()=>window.recurrenceFixture.requests.length===2);
    assert.equal(await page.evaluate(()=>window.recurrenceFixture.requests[1].body.recurrence_scope),undefined);
    assert.equal(await page.evaluate(()=>window.recurrenceFixture.series().template.checklist[0].checked),false);
    await page.getByText('Occurrence history',{exact:true}).click();
    await page.locator('.pandat69-recurrence-history button').last().click();
    await page.getByLabel('Selected task').filter({hasText:'Task #42'}).waitFor();
    assert.equal(await page.getByRole('checkbox',{name:'Review newsletter',exact:true}).isChecked(),true);
    assert.equal(await page.getByRole('button',{name:'Use these steps for future occurrences'}).count(),0);
    await page.getByRole('button',{name:'Open latest occurrence'}).click();
    await page.getByLabel('Selected task').filter({hasText:'Task #43'}).waitFor();
    await page.screenshot({path:path.join(output,'recurrence-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:path.join(output,'recurrence-mobile.png'),fullPage:true});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true);
    await page.setViewportSize({width:1200,height:900});
    await page.goto(`${url}?mode=form`);
    await page.getByRole('radio',{name:'This and future occurrences',exact:true}).waitFor();
    assert.equal(await page.getByRole('radio',{name:'This occurrence',exact:true}).isChecked(),true);
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).waitFor();
    const local = await page.evaluate(()=>window.recurrenceFixture.requests[0].body);
    assert.equal(local.recurrence_scope,'this');
    assert.equal(local.is_recurring,undefined);
    assert.equal(local.recurrence_frequency,undefined);
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).click();
    await page.getByRole('radio',{name:'This and future occurrences',exact:true}).check();
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).waitFor();
    const future = await page.evaluate(()=>window.recurrenceFixture.requests[1].body);
    assert.equal(future.recurrence_scope,'future');
    assert.equal(future.expected_series_version,3);
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).click();
    await page.getByRole('radio',{name:'This and future occurrences',exact:true}).check();
    await page.evaluate(()=>window.recurrenceFixture.externalChange());
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();
    await page.getByRole('alert').filter({hasText:'changed elsewhere'}).waitFor();
    await page.screenshot({path:path.join(output,'recurrence-editor-conflict.png'),fullPage:true});
    await page.goto(`${url}?mode=historical`);
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).click();
    assert.equal(await page.getByRole('radio',{name:'This and future occurrences',exact:true}).count(),0);
    await page.goto(`${url}?mode=stopped`);
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).click();
    await page.getByRole('radio',{name:'This and future occurrences',exact:true}).check();
    await page.getByRole('tab',{name:'Schedule & Rules',exact:true}).click();
    assert.equal(await page.getByRole('checkbox',{name:'Make this a repeating task',exact:true}).isChecked(),false);
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();
    await page.getByRole('button',{name:'Edit occurrence',exact:true}).waitFor();
    assert.equal(await page.evaluate(()=>window.recurrenceFixture.requests[0].body.is_recurring),0);
    await page.goto(`${url}?mode=calendar`);
    await page.locator('.pandat69-month-task-bar').first().waitFor();
    await page.locator('.pandat69-month-task-bar').first().click();
    await page.getByLabel('Selected task').filter({hasText:'Task #42'}).waitFor();
    await page.getByRole('button',{name:'Manage series',exact:true}).click();
    await page.getByRole('button',{name:'Stop repeating',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.recurrenceFixture.requests.at(-1).scope),'following');
    assert.deepEqual(errors,[]);
    console.log(`Recurrence UI acceptance passed. Screenshots: ${output}`);
} catch (error) {
    console.error('Browser errors:', errors);
    console.error('Rendered text:', await page.locator('body').innerText());
    await page.screenshot({path:path.join(output,'failure.png'),fullPage:true});
    console.error('Failure screenshot:',output);
    throw error;
} finally {
    await browser.close();
    await new Promise(resolve=>server.close(resolve));
}
