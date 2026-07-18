/* global jQuery, pandataskAdminSettings */

jQuery( document ).ready( function ( $ ) {
	'use strict';

	const aiContainer = $( '#pandat69-ai-assistant' );
	if ( aiContainer.length === 0 ) {
		return; // Not on the AI assistant page
	}

	const boardSelect = $( '#pandat69-board-select' );
	const userPromptTextarea = $( '#pandat69-user-prompt' );
	const generatePromptBtn = $( '#pandat69-generate-prompt-btn' );

	const generatedPromptContainer = $(
		'#pandat69-generated-prompt-container'
	);
	const generatedPromptPre = $( '#pandat69-generated-prompt' );
	const copyPromptBtn = $( '.pandat69-copy-btn' );

	const llmResponseContainer = $( '#pandat69-llm-response-container' );
	const llmResponseTextarea = $( '#pandat69-llm-response' );
	const executeActionsBtn = $( '#pandat69-execute-actions-btn' );

	const resultsContainer = $( '#pandat69-results-container' );
	const resultsDiv = $( '#pandat69-results' );

	const spinner = $( '#pandat69-spinner' );

	function showSpinner( show ) {
		spinner.css( 'visibility', show ? 'visible' : 'hidden' );
	}

	function showError( message ) {
		resultsDiv
			.empty()
			.append(
				$( '<div>' )
					.addClass( 'pandat69-result-item error' )
					.text( message )
			);
		resultsContainer.slideDown();
	}

	// 1. Fetch boards on page load
	function loadBoards() {
		$.ajax( {
			url: pandataskAdminSettings.root + 'boards',
			type: 'GET',
			beforeSend( xhr ) {
				xhr.setRequestHeader(
					'X-WP-Nonce',
					pandataskAdminSettings.nonce
				);
			},
			success( data ) {
				if ( Array.isArray( data ) ) {
					boardSelect
						.empty()
						.append(
							'<option value="">-- Select a Board --</option>'
						);
					data.forEach( function ( board ) {
						boardSelect.append(
							$( '<option>', {
								value: board.id,
								text: board.name,
							} )
						);
					} );
				} else {
					boardSelect
						.empty()
						.append(
							'<option value="">Could not load boards</option>'
						);
					showError( 'Error: Could not load boards.' );
				}
			},
			error( xhr ) {
				boardSelect
					.empty()
					.append(
						'<option value="">Could not load boards</option>'
					);
				showError(
					'Error loading boards: ' +
						( xhr.responseJSON?.message || xhr.statusText )
				);
			},
		} );
	}

	// 2. Generate AI Prompt
	generatePromptBtn.on( 'click', function () {
		const boardName = boardSelect.val();

		if ( ! boardName ) {
			showError( 'Please select a board.' );
			return;
		}

		const userPrompt = userPromptTextarea.val().trim();
		if ( ! userPrompt ) {
			showError( 'Please enter your request.' );
			return;
		}

		showSpinner( true );
		$( this ).prop( 'disabled', true );

		$.ajax( {
			url: pandataskAdminSettings.root + 'ai/generate-prompt',
			type: 'POST',
			beforeSend( xhr ) {
				xhr.setRequestHeader(
					'X-WP-Nonce',
					pandataskAdminSettings.nonce
				);
			},
			contentType: 'application/json',
			data: JSON.stringify( {
				board_name: boardName,
				user_prompt: userPrompt,
			} ),
			success( response ) {
				generatedPromptPre.text( response.prompt );
				generatedPromptContainer.slideDown();
				llmResponseContainer.slideDown();
				resultsContainer.slideUp();
				resultsDiv.empty();
			},
			error( xhr ) {
				showError(
					'Error generating prompt: ' +
						( xhr.responseJSON?.message || xhr.statusText )
				);
			},
			complete() {
				showSpinner( false );
				generatePromptBtn.prop( 'disabled', false );
			},
		} );
	} );

	// 3. Copy prompt to clipboard
	copyPromptBtn.on( 'click', function () {
		const targetId = $( this ).data( 'target' );
		const textToCopy = $( '#' + targetId ).text();

		navigator.clipboard
			.writeText( textToCopy )
			.then( () => {
				const originalText = $( this ).text();
				$( this ).text( 'Copied!' );
				setTimeout( () => {
					$( this ).text( originalText );
				}, 2000 );
			} )
			.catch( () => {
				showError( 'Failed to copy text.' );
			} );
	} );

	// 4. Execute AI Actions
	executeActionsBtn.on( 'click', function () {
		const boardName = boardSelect.val();

		if ( ! boardName ) {
			showError( 'Please ensure a board is still selected.' );
			return;
		}

		const llmResponse = llmResponseTextarea.val().trim();
		if ( ! llmResponse ) {
			showError( 'Please paste the AI response.' );
			return;
		}

		let actions = [];
		try {
			actions = JSON.parse( llmResponse );
		} catch {
			showError(
				'The provided response is not valid JSON. Please check the format.'
			);
			return;
		}

		if ( ! Array.isArray( actions ) ) {
			showError( 'JSON must be an array of action objects.' );
			return;
		}

		// Augment actions with board_name where required
		actions.forEach( ( action ) => {
			if (
				action.action &&
				( action.action.startsWith( 'create_' ) ||
					action.action === 'delete_category' )
			) {
				if ( ! action.board_name ) {
					action.board_name = boardName;
				}
			}
		} );

		showSpinner( true );
		$( this ).prop( 'disabled', true );
		resultsContainer.slideUp();
		resultsDiv.empty();

		$.ajax( {
			url: pandataskAdminSettings.root + 'batch',
			type: 'POST',
			beforeSend( xhr ) {
				xhr.setRequestHeader(
					'X-WP-Nonce',
					pandataskAdminSettings.nonce
				);
			},
			contentType: 'application/json',
			data: JSON.stringify( { actions } ),
			success( response ) {
				if ( response.results ) {
					renderResults( response.results );
				} else {
					renderResults( [
						{
							success: false,
							message: 'Unknown response format from server.',
						},
					] );
				}
			},
			error( xhr ) {
				const msg =
					xhr.responseJSON?.message ||
					xhr.statusText ||
					'A server error occurred.';
				renderResults( [ { success: false, message: msg } ] );
			},
			complete() {
				showSpinner( false );
				executeActionsBtn.prop( 'disabled', false );
			},
		} );
	} );

	function renderResults( results ) {
		resultsDiv.empty();
		results.forEach( function ( result ) {
			const resultClass = result.success ? 'success' : 'error';
			const resultItem = $( '<div>' ).addClass(
				`pandat69-result-item ${ resultClass }`
			);
			const actionLine = $( '<p>' );
			const actionLabel = $( '<strong>' );
			$( '<span>' )
				.addClass(
					`dashicons ${
						result.success ? 'dashicons-yes-alt' : 'dashicons-no'
					}`
				)
				.appendTo( actionLabel );
			actionLabel.append( document.createTextNode( ' Action:' ) );
			actionLine.append(
				actionLabel,
				document.createTextNode(
					` ${ result.action_description || 'Unknown' }`
				)
			);

			const statusLine = $( '<p>' ).append(
				$( '<strong>' ).text( 'Status:' ),
				document.createTextNode(
					` ${ result.success ? 'Success' : 'Failed' }`
				)
			);
			const messageLine = $( '<p>' ).append(
				$( '<strong>' ).text( 'Message:' ),
				document.createTextNode( ` ${ result.message || '' }` )
			);

			resultItem.append( actionLine, statusLine, messageLine );
			resultsDiv.append( resultItem );
		} );
		resultsContainer.slideDown();
	}

	// Initialize
	loadBoards();
} );
