# Pandatask Quick Capture

A small Manifest V3 browser extension for capturing the current page or selected text into Pandatask.

## What it does

- Defaults to **My Inbox** for near-zero-friction capture.
- Can bypass Inbox and create the task directly on any board the authenticated user may write to.
- Preserves the page title and URL and can include selected text and a short note.
- Uses Pandatask's REST API only; task, permission, triage, and move rules remain server-side.

## Install for development

### Firefox

1. Open `about:debugging#/runtime/this-firefox`.
2. Choose **Load Temporary Add-on**.
3. Select `manifest.json` from this directory.

### Chromium-based browsers

1. Open the browser's extensions page and enable **Developer mode**.
2. Choose **Load unpacked**.
3. Select this directory.

## Connect

Open the extension's Settings page and enter:

- the WordPress site URL,
- your WordPress username,
- a dedicated, revocable WordPress Application Password.

The extension requests host access only for the configured site and stores its settings in extension-local storage. OAuth is intentionally left as a future authentication upgrade; replacing the credential mechanism does not require changing the capture API.

The browser extension is source-distributed separately and is not included in the installable WordPress plugin ZIP.
