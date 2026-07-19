#!/usr/bin/env node
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { loadConfig } from './config.js';
import { PandataskClient } from './client.js';
import { createPandataskServer, PANDATASK_MCP_VERSION } from './server.js';

async function main(): Promise<void> {
  if (process.argv.includes('--version')) {
    process.stdout.write(`${PANDATASK_MCP_VERSION}\n`);
    return;
  }

  const config = loadConfig();
  const server = createPandataskServer(new PandataskClient(config));
  const transport = new StdioServerTransport();

  const shutdown = async (): Promise<void> => {
    await server.close();
    process.exit(0);
  };
  process.once('SIGINT', () => void shutdown());
  process.once('SIGTERM', () => void shutdown());

  await server.connect(transport);
  console.error(`Pandatask MCP ${PANDATASK_MCP_VERSION} connected over stdio.`);
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`Pandatask MCP failed to start: ${message}`);
  process.exit(1);
});
