jQuery(document).ready(function($) {
    'use strict';

    const aiContainer = $('#pandat69-ai-assistant');
    if (aiContainer.length === 0) {
        return; // Not on the AI assistant page
    }

    const boardSelect = $('#pandat69-board-select');
    const userPromptTextarea = $('#pandat69-user-prompt');
    const generatePromptBtn = $('#pandat69-generate-prompt-btn');

    const generatedPromptContainer = $('#pandat69-generated-prompt-container');
    const generatedPromptPre = $('#pandat69-generated-prompt');
    const copyPromptBtn = $('.pandat69-copy-btn');
    
    const llmResponseContainer = $('#pandat69-llm-response-container');
    const llmResponseTextarea = $('#pandat69-llm-response');
    const executeActionsBtn = $('#pandat69-execute-actions-btn');

    const resultsContainer = $('#pandat69-results-container');
    const resultsDiv = $('#pandat69-results');
    
    const spinner = $('#pandat69-spinner');

    function showSpinner(show) {
        spinner.css('visibility', show ? 'visible' : 'hidden');
    }

    // 1. Fetch boards on page load
    function loadBoards() {
        $.ajax({
            url: pandat69_admin_object.root + 'boards',
            type: 'GET',
            beforeSend: function ( xhr ) {
                xhr.setRequestHeader( 'X-WP-Nonce', pandat69_admin_object.nonce );
            },
            success: function(data) {
                if (Array.isArray(data)) {
                    boardSelect.empty().append('<option value="">-- Select a Board --</option>');
                    data.forEach(function(board) {
                        boardSelect.append($('<option>', {
                            value: board.id,
                            text: board.name
                        }));
                    });
                } else {
                    boardSelect.empty().append('<option value="">Could not load boards</option>');
                    alert('Error: Could not load boards.');
                }
            },
            error: function(xhr) {
                boardSelect.empty().append('<option value="">Could not load boards</option>');
                alert('Error loading boards: ' + (xhr.responseJSON?.message || xhr.statusText));
            }
        });
    }

    // 2. Generate AI Prompt
    generatePromptBtn.on('click', function() {
        const boardName = boardSelect.val();
        const userPrompt = userPromptTextarea.val().trim();

        if (!boardName) {
            alert('Please select a board.');
            return;
        }
        if (!userPrompt) {
            alert('Please enter your request.');
            return;
        }

        showSpinner(true);
        $(this).prop('disabled', true);

        $.ajax({
            url: pandat69_admin_object.root + 'ai/generate-prompt',
            type: 'POST',
            beforeSend: function ( xhr ) {
                xhr.setRequestHeader( 'X-WP-Nonce', pandat69_admin_object.nonce );
            },
            contentType: 'application/json',
            data: JSON.stringify({
                board_name: boardName,
                user_prompt: userPrompt
            }),
            success: function(response) {
                generatedPromptPre.text(response.prompt);
                generatedPromptContainer.slideDown();
                llmResponseContainer.slideDown();
                resultsContainer.slideUp();
                resultsDiv.empty();
            },
            error: function(xhr) {
                alert('Error generating prompt: ' + (xhr.responseJSON?.message || xhr.statusText));
            },
            complete: function() {
                showSpinner(false);
                generatePromptBtn.prop('disabled', false);
            }
        });
    });

    // 3. Copy prompt to clipboard
    copyPromptBtn.on('click', function() {
        const targetId = $(this).data('target');
        const textToCopy = $('#' + targetId).text();
        
        navigator.clipboard.writeText(textToCopy).then(() => {
            const originalText = $(this).text();
            $(this).text('Copied!');
            setTimeout(() => {
                $(this).text(originalText);
            }, 2000);
        }).catch(err => {
            alert('Failed to copy text.');
            console.error('Copy error:', err);
        });
    });

    // 4. Execute AI Actions
    executeActionsBtn.on('click', function() {
        const boardName = boardSelect.val();
        let llmResponse = llmResponseTextarea.val().trim();

        if (!boardName) {
            alert('Please ensure a board is still selected.');
            return;
        }
        if (!llmResponse) {
            alert('Please paste the AI response.');
            return;
        }

        let actions = [];
        try {
            actions = JSON.parse(llmResponse);
        } catch (e) {
            alert('The provided response is not valid JSON. Please check the format.');
            return;
        }

        if (!Array.isArray(actions)) {
            alert('JSON must be an array of action objects.');
            return;
        }

        // Augment actions with board_name where required
        actions.forEach(action => {
            if (action.action && (action.action.startsWith('create_') || action.action === 'delete_category')) {
                if (!action.board_name) {
                    action.board_name = boardName;
                }
            }
        });

        showSpinner(true);
        $(this).prop('disabled', true);
        resultsContainer.slideUp();
        resultsDiv.empty();

        $.ajax({
            url: pandat69_admin_object.root + 'batch',
            type: 'POST',
            beforeSend: function ( xhr ) {
                xhr.setRequestHeader( 'X-WP-Nonce', pandat69_admin_object.nonce );
            },
            contentType: 'application/json',
            data: JSON.stringify({ actions: actions }),
            success: function(response) {
                if (response.results) {
                    renderResults(response.results);
                } else {
                    renderResults([{ success: false, message: 'Unknown response format from server.' }]);
                }
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.message || xhr.statusText || 'A server error occurred.';
                renderResults([{ success: false, message: msg }]);
            },
            complete: function() {
                showSpinner(false);
                executeActionsBtn.prop('disabled', false);
            }
        });
    });
    
    function renderResults(results) {
        resultsDiv.empty();
        results.forEach(function(result) {
            const resultClass = result.success ? 'success' : 'error';
            const resultIcon = result.success ? '<span class="dashicons dashicons-yes-alt"></span>' : '<span class="dashicons dashicons-no"></span>';
            const resultHtml = `
                <div class="pandat69-result-item ${resultClass}">
                    <p><strong>${resultIcon} Action:</strong> ${result.action_description || 'Unknown'}</p>
                    <p><strong>Status:</strong> ${result.success ? 'Success' : 'Failed'}</p>
                    <p><strong>Message:</strong> ${result.message}</p>
                </div>
            `;
            resultsDiv.append(resultHtml);
        });
        resultsContainer.slideDown();
    }

    // Initialize
    loadBoards();
});
