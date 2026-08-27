import mermaidScriptUrl from 'mermaid/dist/mermaid.min.js';
import {
	normalizeMermaidContent,
	readMermaidFigure,
	validateMermaidSource,
} from './mermaidContent.mjs';

let mermaidPromise = null;
let renderSequence = 0;

const PANDATASK_MERMAID_CONFIG = {
	startOnLoad: false,
	securityLevel: 'strict',
	suppressErrorRendering: true,
	theme: 'base',
	fontFamily:
		'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
	htmlLabels: false,
	maxTextSize: 50_000,
	maxEdges: 500,
	secure: [
		'secure',
		'securityLevel',
		'startOnLoad',
		'maxTextSize',
		'maxEdges',
		'suppressErrorRendering',
		'theme',
		'themeVariables',
		'themeCSS',
		'fontFamily',
		'htmlLabels',
	],
	flowchart: {
		htmlLabels: false,
		useMaxWidth: true,
	},
};

const loadMermaid = async () => {
	const initialize = () => {
		const mermaid = globalThis.mermaid;
		if ( ! mermaid || typeof mermaid.initialize !== 'function' ) {
			throw new Error(
				'Mermaid runtime loaded without its expected global API.'
			);
		}
		mermaid.initialize( PANDATASK_MERMAID_CONFIG );
		return mermaid;
	};

	if ( globalThis.mermaid ) {
		return initialize();
	}

	if ( ! mermaidPromise ) {
		mermaidPromise = new Promise( ( resolve, reject ) => {
			const script = document.createElement( 'script' );
			script.src = mermaidScriptUrl;
			script.async = true;
			script.dataset.pandataskMermaidRuntime = '1';
			script.onload = () => {
				try {
					resolve( initialize() );
				} catch ( error ) {
					reject( error );
				}
			};
			script.onerror = () =>
				reject( new Error( 'Failed to load the Mermaid runtime.' ) );
			document.head.appendChild( script );
		} ).catch( ( error ) => {
			mermaidPromise = null;
			throw error;
		} );
	}
	return mermaidPromise;
};

const replaceAccessibility = ( svg, content, id ) => {
	if ( ! content.title && ! content.description ) {
		return;
	}

	svg.querySelectorAll( ':scope > title, :scope > desc' ).forEach( ( node ) =>
		node.remove()
	);

	if ( content.description ) {
		const description = svg.ownerDocument.createElementNS(
			'http://www.w3.org/2000/svg',
			'desc'
		);
		description.id = `${ id }-description`;
		description.textContent = content.description;
		svg.insertBefore( description, svg.firstChild );
		svg.setAttribute( 'aria-describedby', description.id );
	} else {
		svg.removeAttribute( 'aria-describedby' );
	}

	if ( content.title ) {
		const title = svg.ownerDocument.createElementNS(
			'http://www.w3.org/2000/svg',
			'title'
		);
		title.id = `${ id }-title`;
		title.textContent = content.title;
		svg.insertBefore( title, svg.firstChild );
		svg.setAttribute( 'aria-labelledby', title.id );
	} else {
		svg.removeAttribute( 'aria-labelledby' );
	}

	svg.setAttribute( 'role', 'img' );
};

const isSafeSvgReference = ( value ) => {
	const normalized = value.trim();
	return ! normalized || normalized.startsWith( '#' );
};

export const sanitizeRenderedSvg = ( rawSvg, content, id ) => {
	const documentNode = new globalThis.DOMParser().parseFromString(
		rawSvg,
		'image/svg+xml'
	);
	if ( documentNode.querySelector( 'parsererror' ) ) {
		throw new Error( 'Mermaid returned invalid SVG.' );
	}

	const svg = documentNode.documentElement;
	if ( svg.localName.toLowerCase() !== 'svg' ) {
		throw new Error( 'Mermaid did not return an SVG document.' );
	}

	svg.querySelectorAll(
		'script, foreignObject, iframe, object, embed'
	).forEach( ( node ) => node.remove() );
	svg.querySelectorAll( '*' ).forEach( ( element ) => {
		Array.from( element.attributes ).forEach( ( attribute ) => {
			const name = attribute.name.toLowerCase();
			if ( name.startsWith( 'on' ) ) {
				element.removeAttribute( attribute.name );
				return;
			}
			if (
				( name === 'href' || name === 'xlink:href' ) &&
				! isSafeSvgReference( attribute.value )
			) {
				element.removeAttribute( attribute.name );
				return;
			}
			if (
				name === 'style' &&
				/url\((?!\s*['"]?#)/i.test( attribute.value )
			) {
				element.removeAttribute( attribute.name );
			}
		} );
	} );
	svg.querySelectorAll( 'style' ).forEach( ( style ) => {
		if ( /@import|url\((?!\s*['"]?#)/i.test( style.textContent || '' ) ) {
			style.remove();
		}
	} );

	svg.removeAttribute( 'height' );
	svg.removeAttribute( 'width' );
	svg.setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
	svg.setAttribute( 'focusable', 'false' );
	replaceAccessibility( svg, content, id );

	return new globalThis.XMLSerializer().serializeToString( svg );
};

export const renderMermaidSvg = async ( input ) => {
	const content = normalizeMermaidContent( input );
	const validationError = validateMermaidSource( content.source );
	if ( validationError ) {
		throw new Error( validationError );
	}

	const mermaid = await loadMermaid();
	const id = `pandatask-mermaid-${ Date.now().toString(
		36
	) }-${ ( ++renderSequence ).toString( 36 ) }`;
	const result = await mermaid.render( id, content.source );
	return sanitizeRenderedSvg( result.svg, content, id );
};

export const renderMermaidFigures = async ( root ) => {
	if ( ! root ) {
		return;
	}

	const figures = Array.from(
		root.querySelectorAll( 'figure.iarf-mermaid' )
	);
	await Promise.all(
		figures.map( async ( figure ) => {
			const stage = figure.querySelector( '.iarf-mermaid-stage' );
			if ( ! stage || stage.dataset.pandataskMermaidRendered === '1' ) {
				return;
			}

			const content = readMermaidFigure( figure );
			const sourceMarkup = stage.innerHTML;
			stage.setAttribute( 'aria-busy', 'true' );

			try {
				const svg = await renderMermaidSvg( content );
				stage.innerHTML = svg;
				stage.dataset.pandataskMermaidRendered = '1';
				stage.classList.remove( 'pandat69-mermaid-stage-error' );
			} catch ( error ) {
				stage.innerHTML = sourceMarkup;
				stage.classList.add( 'pandat69-mermaid-stage-error' );
				const message = document.createElement( 'p' );
				message.className = 'pandat69-mermaid-error';
				message.setAttribute( 'role', 'status' );
				message.textContent =
					error instanceof Error
						? error.message
						: 'Diagram preview unavailable.';
				stage.appendChild( message );
			} finally {
				stage.removeAttribute( 'aria-busy' );
			}
		} )
	);
};

export const mermaidRuntimeIsLoaded = () => mermaidPromise !== null;
