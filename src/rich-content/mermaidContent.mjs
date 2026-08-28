export const MERMAID_MAX_SOURCE_LENGTH = 50_000;
export const MERMAID_MAX_TITLE_LENGTH = 180;
export const MERMAID_MAX_CAPTION_LENGTH = 500;
export const MERMAID_MAX_DESCRIPTION_LENGTH = 1_200;

const clampText = ( value, maxLength ) =>
	String( value ?? '' )
		.replace( /\r\n?/g, '\n' )
		.trim()
		.slice( 0, maxLength );

const normalizeSource = ( value ) =>
	String( value ?? '' )
		.replace( /\r\n?/g, '\n' )
		.trim();

export const normalizeMermaidContent = ( value = {} ) => ( {
	// Preserve oversized source so validation can reject it visibly instead of
	// silently changing what the user typed or uploaded.
	source: normalizeSource( value.source ),
	title: clampText( value.title, MERMAID_MAX_TITLE_LENGTH ),
	caption: clampText( value.caption, MERMAID_MAX_CAPTION_LENGTH ),
	description: clampText( value.description, MERMAID_MAX_DESCRIPTION_LENGTH ),
} );

export const validateMermaidSource = ( rawSource ) => {
	const source = String( rawSource ?? '' )
		.replace( /\r\n?/g, '\n' )
		.trim();

	if ( ! source ) {
		return 'Enter a Mermaid diagram definition.';
	}
	if ( source.length > MERMAID_MAX_SOURCE_LENGTH ) {
		return `Diagram source must be ${ MERMAID_MAX_SOURCE_LENGTH.toLocaleString() } characters or fewer.`;
	}
	if ( /%%\s*\{[\s\S]*?\}%%/m.test( source ) ) {
		return 'Mermaid init directives are not supported.';
	}

	const frontmatter = source.match(
		/^\s*---\s*\n([\s\S]*?)\n---\s*(?:\n|$)/
	);
	if ( frontmatter && /(^|\n)\s*config\s*:/i.test( frontmatter[ 1 ] ) ) {
		return 'Mermaid frontmatter configuration is not supported.';
	}
	if ( /(^|\n)\s*click\s+\S+/i.test( source ) ) {
		return 'Mermaid click actions and external navigation are not supported.';
	}

	return null;
};

export const escapeRichTextHtml = ( value ) =>
	String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );

export const serializeMermaidFigure = ( value = {} ) => {
	const content = normalizeMermaidContent( value );
	const validationError = validateMermaidSource( content.source );
	if ( validationError ) {
		throw new Error( validationError );
	}
	const title = content.title
		? `<strong class="iarf-mermaid-title">${ escapeRichTextHtml(
				content.title
		  ) }</strong>`
		: '';
	const caption = content.caption
		? `<figcaption class="iarf-mermaid-caption">${ escapeRichTextHtml(
				content.caption
		  ) }</figcaption>`
		: '';
	const description = content.description
		? `<span class="iarf-mermaid-description">${ escapeRichTextHtml(
				content.description
		  ) }</span>`
		: '';

	return [
		'<figure class="iarf-mermaid">',
		`<div class="iarf-mermaid-header"><span class="iarf-mermaid-badge">Diagram</span>${ title }</div>`,
		`<div class="iarf-mermaid-stage"><pre class="iarf-mermaid-source"><code class="language-mermaid">${ escapeRichTextHtml(
			content.source
		) }</code></pre></div>`,
		caption,
		description,
		'</figure>',
	].join( '' );
};

export const readMermaidFigure = ( figure ) =>
	normalizeMermaidContent( {
		source:
			figure.querySelector( '.iarf-mermaid-source code' )?.textContent ||
			'',
		title: figure.querySelector( '.iarf-mermaid-title' )?.textContent || '',
		caption:
			figure.querySelector( '.iarf-mermaid-caption' )?.textContent || '',
		description:
			figure.querySelector( '.iarf-mermaid-description' )?.textContent ||
			'',
	} );

const MERMAID_FENCE =
	/(^|\n)([ \t]*)(`{3,}|~{3,})[ \t]*mermaid[^\n]*\n([\s\S]*?)\n\2\3[ \t]*(?=\n|$)/gi;

export const convertMermaidMarkdownFences = ( markdown ) =>
	String( markdown ?? '' ).replace(
		MERMAID_FENCE,
		( _match, prefix, _indent, _fence, source ) =>
			`${ prefix }${ serializeMermaidFigure( {
				source,
				title: 'Imported diagram',
				description: 'Mermaid diagram imported from Markdown.',
			} ) }`
	);

export const plainTextToHtml = ( value ) => {
	const text = String( value ?? '' ).replace( /\r\n?/g, '\n' );
	if ( ! text.trim() ) {
		return '';
	}

	return text
		.split( /\n{2,}/ )
		.map(
			( paragraph ) =>
				`<p>${ escapeRichTextHtml( paragraph ).replace(
					/\n/g,
					'<br>'
				) }</p>`
		)
		.join( '' );
};

export const looksLikeHtml = ( value ) =>
	/<\/?[a-z][^>]*>/i.test( String( value ?? '' ) );

export const defaultMermaidSource = `flowchart LR
    A[Starting point] --> B{Decision}
    B -->|Yes| C[Next step]
    B -->|No| D[Alternative]`;
