import { stat } from 'node:fs/promises';
import { gzip } from 'node:zlib';
import { promisify } from 'node:util';
import { readFile } from 'node:fs/promises';

const gzipAsync = promisify( gzip );
const budgets = [
	// The group Work Log is route-split; the small increase here is the runtime
	// needed to load its JS/CSS chunks rather than the view implementation.
	{ path: 'build/main.js', maximum: 260 * 1024, maximumGzip: 73 * 1024 },
	{ path: 'build/main.css', maximum: 127 * 1024, maximumGzip: 21 * 1024 },
];

let failed = false;

for ( const budget of budgets ) {
	const [ metadata, contents ] = await Promise.all( [
		stat( budget.path ),
		readFile( budget.path ),
	] );
	const compressed = await gzipAsync( contents );
	const rawKiB = ( metadata.size / 1024 ).toFixed( 1 );
	const gzipKiB = ( compressed.length / 1024 ).toFixed( 1 );
	const maximumKiB = ( budget.maximum / 1024 ).toFixed( 0 );
	const maximumGzipKiB = ( budget.maximumGzip / 1024 ).toFixed( 0 );
	const withinBudget =
		metadata.size <= budget.maximum &&
		compressed.length <= budget.maximumGzip;

	process.stdout.write(
		`${ withinBudget ? 'PASS' : 'FAIL' } ${ budget.path }: ${ rawKiB } KiB raw, ${ gzipKiB } KiB gzip (budgets ${ maximumKiB } KiB raw / ${ maximumGzipKiB } KiB gzip)\n`
	);
	failed ||= ! withinBudget;
}

if ( failed ) {
	process.exitCode = 1;
}
