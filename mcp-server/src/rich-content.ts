import { marked } from 'marked';
import type { JsonRecord } from './client.js';

const MERMAID_MAX_SOURCE_LENGTH = 50_000;

function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function validateMermaidSource(rawSource: unknown): string | null {
  const source = String(rawSource ?? '').replace(/\r\n?/g, '\n').trim();
  if (!source) return 'Mermaid diagram source cannot be empty.';
  if (source.length > MERMAID_MAX_SOURCE_LENGTH) return `Mermaid source must be ${MERMAID_MAX_SOURCE_LENGTH} characters or fewer.`;
  if (/%%\s*\{[\s\S]*?\}%%/m.test(source)) return 'Mermaid init directives are not supported.';
  const frontmatter = source.match(/^\s*---\s*\n([\s\S]*?)\n---\s*(?:\n|$)/);
  if (frontmatter && /(^|\n)\s*config\s*:/i.test(frontmatter[1] ?? '')) return 'Mermaid frontmatter configuration is not supported.';
  if (/(^|\n)\s*click\s+\S+/i.test(source)) return 'Mermaid click actions are not supported.';
  return null;
}

function serializeMermaidFigure(rawSource: unknown): string {
  const source = String(rawSource ?? '').replace(/\r\n?/g, '\n').trim();
  const validationError = validateMermaidSource(source);
  if (validationError) throw new Error(validationError);
  return [
    '<figure class="iarf-mermaid">',
    '<div class="iarf-mermaid-header"><span class="iarf-mermaid-badge">Diagram</span><strong class="iarf-mermaid-title">Imported diagram</strong></div>',
    `<div class="iarf-mermaid-stage"><pre class="iarf-mermaid-source"><code class="language-mermaid">${escapeHtml(source)}</code></pre></div>`,
    '<span class="iarf-mermaid-description">Mermaid diagram imported from Markdown.</span>',
    '</figure>',
  ].join('');
}

const MERMAID_FENCE = /(^|\n)([ \t]*)(`{3,}|~{3,})[ \t]*mermaid[^\n]*\n([\s\S]*?)\n\2\3[ \t]*(?=\n|$)/gi;

export function convertMermaidMarkdownFences(markdown: string): string {
  return String(markdown ?? '').replace(
    MERMAID_FENCE,
    (_match, prefix, _indent, _fence, source) => `${prefix}${serializeMermaidFigure(source)}`,
  );
}

export function plainTextToHtml(value: unknown): string {
  const text = String(value ?? '').replace(/\r\n?/g, '\n');
  if (!text.trim()) return '';
  return text
    .split(/\n{2,}/)
    .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

export function markdownToHtml(value: unknown): string {
  const source = convertMermaidMarkdownFences(String(value ?? ''));
  return String(marked.parse(source, { async: false, gfm: true, breaks: false }));
}

export function normalizeTaskDescriptionBody(body: JsonRecord): JsonRecord {
  const normalized: JsonRecord = { ...body };
  const format = normalized.description_format;
  delete normalized.description_format;

  if (format !== undefined && normalized.description === undefined) {
    throw new Error('description_format requires description to be supplied in the same operation.');
  }
  if (normalized.description === undefined) return normalized;

  const description = String(normalized.description ?? '');
  switch (format ?? 'html') {
    case 'html':
      normalized.description = description;
      break;
    case 'markdown':
      normalized.description = markdownToHtml(description);
      break;
    case 'plain':
      normalized.description = plainTextToHtml(description);
      break;
    default:
      throw new Error('description_format must be html, markdown, or plain.');
  }

  return normalized;
}

export function normalizeBatchTaskDescriptions(actions: unknown[]): unknown[] {
  return actions.map((action) => {
    if (!action || typeof action !== 'object' || Array.isArray(action)) return action;
    const record = action as JsonRecord;
    if (!['create_task', 'update_task'].includes(String(record.action ?? ''))) return action;
    const data = record.data;
    if (!data || typeof data !== 'object' || Array.isArray(data)) return action;
    return { ...record, data: normalizeTaskDescriptionBody(data as JsonRecord) };
  });
}
