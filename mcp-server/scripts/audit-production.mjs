import { spawnSync } from 'node:child_process';

const npmExecPath = process.env.npm_execpath;
const npmCommand = npmExecPath ? process.execPath : process.platform === 'win32' ? 'npm.cmd' : 'npm';
const npmArguments = npmExecPath ? [npmExecPath, 'audit', '--omit=dev', '--json'] : ['audit', '--omit=dev', '--json'];
const audit = spawnSync(npmCommand, npmArguments, {
  encoding: 'utf8',
  shell: process.platform === 'win32' && !npmExecPath,
});

if (audit.error) {
  console.error(`Unable to run npm audit: ${audit.error.message}`);
  process.exit(1);
}

let report;
try {
  report = JSON.parse(audit.stdout || '{}');
} catch {
  process.stderr.write(audit.stderr || audit.stdout || 'npm audit returned invalid JSON.\n');
  process.exit(1);
}

const vulnerabilities = Object.values(report.vulnerabilities ?? {});
if (vulnerabilities.length === 0) {
  console.log('Production dependency audit passed with no findings.');
  process.exit(0);
}

const allowedAdvisory = 'https://github.com/advisories/GHSA-frvp-7c67-39w9';
const allowedPackages = new Set(['@hono/node-server', '@modelcontextprotocol/sdk']);
const narrowlyExcepted = vulnerabilities.every((vulnerability) => {
  if (!vulnerability || !allowedPackages.has(vulnerability.name) || vulnerability.severity !== 'moderate') {
    return false;
  }
  return (vulnerability.via ?? []).every((via) => {
    if (typeof via === 'string') return via === '@hono/node-server';
    return via?.url === allowedAdvisory && via?.name === '@hono/node-server';
  });
});

if (!narrowlyExcepted) {
  process.stderr.write(audit.stdout);
  process.exit(audit.status || 1);
}

console.warn(
  [
    'Production dependency audit found only GHSA-frvp-7c67-39w9 through the MCP SDK Hono adapter.',
    'Pandatask MCP is stdio-only and does not import or run Hono static-file serving, so the vulnerable path is unreachable.',
    'This exact moderate advisory is temporarily excepted; any other advisory still fails CI.',
  ].join('\n'),
);
