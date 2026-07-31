import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const reactDoctorEntry = require.resolve( 'react-doctor' );
const reactDoctorCli = path.resolve(
	path.dirname( reactDoctorEntry ),
	'../bin/react-doctor.js'
);
const result = spawnSync(
	process.execPath,
	[
		reactDoctorCli,
		'.',
		'--yes',
		'--no-score',
		'--no-supply-chain',
		'--no-dead-code',
		'--no-parallel',
		'--category',
		'Accessibility',
		'--json',
		'--json-compact',
		'--blocking',
		'none',
	],
	{
		encoding: 'utf8',
		env: { ...process.env, NO_COLOR: '1' },
		maxBuffer: 20 * 1024 * 1024,
	}
);

let report;
try {
	report = JSON.parse( result.stdout );
} catch {
	console.error( result.stderr || result.stdout || result.error );
	throw new Error( 'React Doctor did not return a valid JSON report.' );
}

const incompleteProjects = report.projects.filter(
	( project ) => ! project.complete
);
const issueCount =
	report.summary.errorCount + report.summary.warningCount;

if ( ! report.ok || incompleteProjects.length > 0 || issueCount > 0 ) {
	console.error(
		`React accessibility check failed: ${ report.summary.errorCount } errors, ` +
			`${ report.summary.warningCount } warnings, ` +
			`${ incompleteProjects.length } incomplete projects.`
	);
	for ( const diagnostic of report.diagnostics ) {
		console.error(
			`${ diagnostic.file || 'unknown file' }:${ diagnostic.line || '?' } ` +
				`${ diagnostic.title || diagnostic.message || 'Accessibility issue' }`
		);
	}
	process.exitCode = 1;
} else {
	console.log(
		`React accessibility check passed across ${ report.projects.reduce(
			( total, project ) => total + project.analyzedFileCount,
			0
		) } files with zero findings.`
	);
}
