import { mergeAttributes, Node } from '@tiptap/core';

const text = ( element, selector ) =>
	element.querySelector( selector )?.textContent?.trim() || '';

const MermaidNode = Node.create( {
	name: 'mermaid',
	group: 'block',
	atom: true,
	draggable: true,
	selectable: true,

	addAttributes() {
		return {
			source: { default: '' },
			title: { default: '' },
			caption: { default: '' },
			description: { default: '' },
		};
	},

	parseHTML() {
		return [
			{
				tag: 'figure.iarf-mermaid',
				getAttrs: ( node ) => ( {
					source: text( node, '.iarf-mermaid-source code' ),
					title: text( node, '.iarf-mermaid-title' ),
					caption: text( node, '.iarf-mermaid-caption' ),
					description: text( node, '.iarf-mermaid-description' ),
				} ),
			},
		];
	},

	renderHTML( { HTMLAttributes } ) {
		const source = String( HTMLAttributes.source || '' );
		const title = String( HTMLAttributes.title || '' );
		const caption = String( HTMLAttributes.caption || '' );
		const description = String( HTMLAttributes.description || '' );
		const figureAttributes = mergeAttributes(
			{ class: 'iarf-mermaid' },
			Object.fromEntries(
				Object.entries( HTMLAttributes ).filter(
					( [ key ] ) =>
						! [
							'source',
							'title',
							'caption',
							'description',
						].includes( key )
				)
			)
		);

		return [
			'figure',
			figureAttributes,
			[
				'div',
				{ class: 'iarf-mermaid-header' },
				[ 'span', { class: 'iarf-mermaid-badge' }, 'Diagram' ],
				...( title
					? [ [ 'strong', { class: 'iarf-mermaid-title' }, title ] ]
					: [] ),
			],
			[
				'div',
				{ class: 'iarf-mermaid-stage' },
				[
					'pre',
					{ class: 'iarf-mermaid-source' },
					[ 'code', { class: 'language-mermaid' }, source ],
				],
			],
			...( caption
				? [
						[
							'figcaption',
							{ class: 'iarf-mermaid-caption' },
							caption,
						],
				  ]
				: [] ),
			...( description
				? [
						[
							'span',
							{ class: 'iarf-mermaid-description' },
							description,
						],
				  ]
				: [] ),
		];
	},
} );

export default MermaidNode;
