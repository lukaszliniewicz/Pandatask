import { marked } from 'marked';
import { convertMermaidMarkdownFences } from './mermaidContent.mjs';

export const TASK_DESCRIPTION_MAX_LENGTH = 10_000;

const BLOCK_MARKDOWN_PATTERNS = [
	/^ {0,3}#{1,6}(?:[ \t]+|$)/m,
	/^ {0,3}>[ \t]+/m,
	/^ {0,3}(?:[-+*]|\d+[.)])[ \t]+\S/m,
	/^ {0,3}(?:`{3,}|~{3,})/m,
	/^ {0,3}(?:-{3,}|_{3,}|\*{3,})[ \t]*$/m,
	/^ {0,3}\[[^\]]+\]:[ \t]+\S+/m,
];

const INLINE_MARKDOWN_PATTERNS = [
	/(?:^|[^\\])!\[[^\]]*\](?:\([^\n)]*\)|\[[^\]]*\])/,
	/(?:^|[^\\])\[[^\]]+\]\([^\n)]+\)/,
	/(?:^|[^\\])\*\*(?=\S)[\s\S]*?\S\*\*/,
	/(?:^|[^\\])__(?=\S)[\s\S]*?\S__/,
	/(?:^|[^\\])~~(?=\S)[\s\S]*?\S~~/,
	/(?:^|[^\\])`[^`\n]+`/,
];

const hasGfmTable = ( value ) => {
	const lines = value.split( /\r?\n/ );
	return lines.some(
		( line, index ) =>
			index > 0 &&
			/\|/.test( lines[ index - 1 ] ) &&
			/^ {0,3}\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*$/.test(
				line
			)
	);
};

export const looksLikeTaskMarkdown = ( value ) => {
	const text = String( value ?? '' );
	if ( ! text.trim() ) {
		return false;
	}
	return (
		BLOCK_MARKDOWN_PATTERNS.some( ( pattern ) => pattern.test( text ) ) ||
		INLINE_MARKDOWN_PATTERNS.some( ( pattern ) => pattern.test( text ) ) ||
		hasGfmTable( text )
	);
};

export const validateTaskMarkdown = ( value ) => {
	const text = String( value ?? '' );
	if ( /^ {0,3}[-+*][ \t]+\[[ xX]\][ \t]+/m.test( text ) ) {
		return 'Task-list checkboxes are not supported in descriptions. Use the task checklist instead.';
	}
	if ( /(?:^|[^\\])!\[[^\]]*\](?:\([^\n)]*\)|\[[^\]]*\])/.test( text ) ) {
		return 'Markdown images are not supported in descriptions. Add an attachment or paste a link instead.';
	}
	return null;
};

export const markdownToTaskHtml = ( markdown ) => {
	const validationError = validateTaskMarkdown( markdown );
	if ( validationError ) {
		throw new Error( validationError );
	}

	return String(
		marked.parse(
			convertMermaidMarkdownFences( String( markdown ?? '' ) ),
			{
				async: false,
				gfm: true,
				breaks: false,
			}
		)
	);
};

export const validateTaskDescriptionLength = ( value ) =>
	String( value ?? '' ).length <= TASK_DESCRIPTION_MAX_LENGTH
		? null
		: `Task descriptions must be ${ TASK_DESCRIPTION_MAX_LENGTH.toLocaleString(
				'en-US'
		  ) } characters or fewer.`;
