import { stat } from 'node:fs/promises';
import { gzip } from 'node:zlib';
import { promisify } from 'node:util';
import { readFile } from 'node:fs/promises';

const gzipAsync = promisify( gzip );
const budgets = [
	{ path: 'build/main.js', maximum: 260 * 1024 },
	{ path: 'build/main.css', maximum: 100 * 1024 },
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
	const withinBudget = metadata.size <= budget.maximum;

	process.stdout.write(
		`${ withinBudget ? 'PASS' : 'FAIL' } ${ budget.path }: ${ rawKiB } KiB raw, ${ gzipKiB } KiB gzip (budget ${ maximumKiB } KiB raw)\n`
	);
	failed ||= ! withinBudget;
}

if ( failed ) {
	process.exitCode = 1;
}
