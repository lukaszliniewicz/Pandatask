jQuery(document).ready(function ($) {
    // --- Configuration ---
    const ajax_url = tbp_ajax_object.ajax_url;
    const nonce = tbp_ajax_object.nonce;
    const currentUserId = tbp_ajax_object.current_user_id;
    const currentUserDisplayName = tbp_ajax_object.current_user_display_name;
    const texts = tbp_ajax_object.text;

    // --- Initialization for each board on the page ---
    $('.tbp-container').each(function () {
        const $boardContainer = $(this);
        const boardName = $boardContainer.data('board-name');

        // Cache selectors for the current board
        const $taskList = $boardContainer.find('.tbp-task-list');
        const $loading = $boardContainer.find('.tbp-loading');
        const $searchInput = $boardContainer.find('.tbp-search-input');
        const $sortSelect = $boardContainer.find('.tbp-sort-select');
        const $statusFilterSelect = $boardContainer.find('.tbp-status-filter-select');

        // Expandable sections
        const $addTaskSection = $boardContainer.find('.tbp-add-task-section');
        const $categoriesSection = $boardContainer.find('.tbp-categories-section');

        // Forms
        const $taskForm = $boardContainer.find('.tbp-task-form');
        const $categoryForm = $boardContainer.find('.tbp-add-category-form');

        // --- Initial Load ---
        fetchTasks();
        loadCategoriesForSelect($boardContainer.find('#tbp-task-category')); // Load categories for add/edit form

        // --- Event Listeners ---

        // Open Add Task Section
        $boardContainer.on('click', '.tbp-add-task-btn', function () {
            resetTaskForm();
            closeAllExpandableSections();
            showExpandableSection($addTaskSection);
            
            // Initialize TinyMCE
            let editorId = $taskForm.find('.tbp-tinymce-editor').attr('id');
            if (!editorId) {
                editorId = 'tbp-task-description-' + boardName + '-' + Date.now(); // Create dynamic unique ID
                $taskForm.find('.tbp-tinymce-editor').attr('id', editorId);
            }
            initTinyMCE(editorId);
            
            // Initialize datepicker
            initDatepicker($taskForm.find('.tbp-datepicker'));
        });

        // Open Categories Section
        $boardContainer.on('click', '.tbp-manage-categories-btn', function () {
            loadCategoriesForManagement();
            closeAllExpandableSections();
            showExpandableSection($categoriesSection);
        });

        // Close Expandable Sections
        $boardContainer.on('click', '.tbp-close-expandable, .tbp-cancel-task-btn', function () {
            const $section = $(this).closest('.tbp-expandable-section');
            
            // If it's the task form and TinyMCE is active, destroy it
            if ($section.is($addTaskSection)) {
                const editorId = $taskForm.find('.tbp-tinymce-editor').attr('id');
                if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                    tinymce.get(editorId).remove();
                }
            }
            
            hideExpandableSection($section);
        });

        // Search Input Change (with debounce)
        let searchTimeout;
        $searchInput.on('keyup', function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(fetchTasks, 300); // Debounce search
        });

        // Sort Dropdown Change
        $sortSelect.on('change', fetchTasks);

        // Status Filter Dropdown Change
        $statusFilterSelect.on('change', fetchTasks);

        // Task Form Submit (Add/Edit)
        $taskForm.on('submit', function (e) {
            e.preventDefault();
            saveTask();
        });

        // Category Form Submit
        $categoryForm.on('submit', function (e) {
            e.preventDefault();
            addCategory();
        });

        // Delete Category Button
        $categoriesSection.on('click', '.tbp-delete-category-btn', function () {
            const categoryId = $(this).data('category-id');
            const categoryName = $(this).closest('.tbp-category-item').find('.tbp-category-name').text();
            if (confirm(texts.confirm_delete_category.replace('%s', categoryName))) {
                deleteCategory(categoryId);
            }
        });

        // Task List Item Click (Toggle Details)
        $taskList.on('click', '.tbp-task-item', function (e) {
            // Prevent toggling if clicking on action buttons, expanded details content, OR STATUS LABELS
            if ($(e.target).closest('.tbp-task-item-actions').length === 0 && 
                $(e.target).closest('.tbp-task-details-expandable').length === 0 &&
                $(e.target).closest('.tbp-task-status').length === 0) { // Add this condition
                const taskId = $(this).data('task-id');
                
                // Check if THIS task is already expanded
                const isCurrentlyExpanded = $(this).hasClass('tbp-details-expanded');
                
                // Clear any active add/edit forms
                closeAllExpandableSections();
                
                // If this task is already expanded, just close it and do nothing else
                if (isCurrentlyExpanded) {
                    // Remove expanded class
                    $(this).removeClass('tbp-details-expanded');
                    // Hide and remove the details section
                    $(this).find('.tbp-task-details-expandable').slideUp(300, function() {
                        $(this).remove();
                    });
                } else {
                    // Close any existing expanded task first
                    closeAllTaskDetails();
                    
                    // Now fetch and show details for this task
                    viewTaskDetails(taskId, $(this));
                }
            }
        });

        // Stop event propagation for clicks inside the task details expandable area
        $taskList.on('click', '.tbp-task-details-expandable', function (e) {
            e.stopPropagation();
        });

        // Edit Task Button
        $taskList.on('click', '.tbp-edit-task-btn', function (e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            const taskId = $(this).closest('.tbp-task-item').data('task-id');
            editTask(taskId);
        });

        // Delete Task Button
        $taskList.on('click', '.tbp-delete-task-btn', function (e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            const taskId = $(this).closest('.tbp-task-item').data('task-id');
            if (confirm(texts.confirm_delete_task)) {
                deleteTask(taskId);
            }
        });

        // Comment Form Submit (Dynamically added)
        $taskList.on('submit', '.tbp-comment-form', function (e) {
            e.preventDefault();
            e.stopPropagation(); // Add this line to stop event propagation
            const $form = $(this);
            addComment($form);
        });

        // Add new category from task form
        $taskForm.on('click', '.tbp-add-category-inline-btn', function(e) {
            e.preventDefault();
            $taskForm.find('.tbp-inline-category-form').slideDown(200);
            $taskForm.find('.tbp-new-category-name-inline').focus();
        });
        
        // Save new category from task form
        $taskForm.on('click', '.tbp-save-category-inline-btn', function(e) {
            e.preventDefault();
            addCategoryInline();
        });
        
        // Cancel adding category from task form
        $taskForm.on('click', '.tbp-cancel-category-inline-btn', function(e) {
            e.preventDefault();
            $taskForm.find('.tbp-inline-category-form').slideUp(200);
            $taskForm.find('.tbp-new-category-name-inline').val('');
            $taskForm.find('.tbp-inline-form-message').hide();
        });

        // Status Quick Change
        $taskList.on('click', '.tbp-task-status', function(e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            
            const $statusLabel = $(this);
            const $taskItem = $statusLabel.closest('.tbp-task-item');
            const taskId = $taskItem.data('task-id');
            const currentStatus = $statusLabel.parent().data('status');
            
            // Close any other open dropdowns first
            $('.tbp-status-dropdown').remove();
            
            // Don't do anything if we're already showing the dropdown
            if ($statusLabel.parent().find('.tbp-status-dropdown').length) {
                return;
            }
            
            // Create dropdown with status options
            const dropdown = `
                <div class="tbp-status-dropdown">
                    <div class="tbp-status-option tbp-status-pending" data-status="pending">Pending</div>
                    <div class="tbp-status-option tbp-status-in-progress" data-status="in-progress">In Progress</div>
                    <div class="tbp-status-option tbp-status-done" data-status="done">Done</div>
                </div>
            `;
            
            // Add dropdown to the DOM
            $statusLabel.parent().append(dropdown);
            
            // Highlight current status
            $statusLabel.parent().find(`.tbp-status-option[data-status="${currentStatus}"]`).addClass('tbp-current-status');
            
            // Prevent immediate closing when clicking on the status label
            e.stopImmediatePropagation();
            
            // Handle click outside to close dropdown
            $(document).one('click', function() {
                $('.tbp-status-dropdown').remove();
            });
        });


        // Handle status selection - modify this handler to properly stop propagation
        $taskList.on('click', '.tbp-status-option', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            const $option = $(this);
            const newStatus = $option.data('status');
            const $statusContainer = $option.closest('.tbp-task-item-meta').find('.tbp-task-status').parent();
            const $taskItem = $option.closest('.tbp-task-item');
            const taskId = $taskItem.data('task-id');
            
            // Only proceed if this is a new status
            if ($option.hasClass('tbp-current-status')) {
                $('.tbp-status-dropdown').remove();
                return;
            }
            
            // Show loading state
            $statusContainer.find('.tbp-task-status').html('<small>Updating...</small>');
            $('.tbp-status-dropdown').remove();
            
            // Send AJAX request to update status
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_quick_update_status',
                    nonce: nonce,
                    task_id: taskId,
                    status: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        // Update the status label
                        const $status = $statusContainer.find('.tbp-task-status');
                        $status
                            .removeClass('tbp-status-pending tbp-status-in-progress tbp-status-done')
                            .addClass('tbp-status-' + newStatus)
                            .text(response.data.status_text);
                        
                        // Update the data attribute
                        $statusContainer.data('status', newStatus);
                        
                        // Briefly show success indicator
                        $status.append(' <span class="tbp-status-updated">✓</span>');
                        setTimeout(function() {
                            $status.find('.tbp-status-updated').fadeOut(300, function() { $(this).remove(); });
                        }, 1000);
                    } else {
                        // Revert to original status and show error
                        alert(response.data.message || texts.error_general);
                        fetchTasks(); // Refresh the list to ensure correct state
                    }
                },
                error: function() {
                    alert(texts.error_general);
                    fetchTasks(); // Refresh the list to ensure correct state
                }
            });
        });

        // --- Core Functions ---

        function showLoading() { $loading.show(); }
        function hideLoading() { $loading.hide(); }

        function showExpandableSection($section) {
            $section.show(); 
        }

        function hideExpandableSection($section) {
            $section.hide(); 
        }

        function closeAllExpandableSections() {
            $boardContainer.find('.tbp-expandable-section').hide(); 
            
            // Keep TinyMCE cleanup code unchanged
            const editorId = $taskForm.find('.tbp-tinymce-editor').attr('id');
            if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).remove();
            }
        }

        function closeAllTaskDetails() {
            // Find all tasks with expanded details and hide them immediately
            $taskList.find('.tbp-task-item.tbp-details-expanded').each(function() {
                $(this).removeClass('tbp-details-expanded');
                $(this).find('.tbp-task-details-expandable').hide().remove(); // Replace slideUp with hide
            });
        }

        function showFormMessage($form, message, isSuccess) {
            const $messageArea = $form.find('.tbp-form-message');
            $messageArea
                .text(message)
                .removeClass('tbp-success tbp-error')
                .addClass(isSuccess ? 'tbp-success' : 'tbp-error')
                .show();
            // Consider making timeout configurable or longer
            setTimeout(() => $messageArea.fadeOut(), 5000);
        }

        function showInlineFormMessage($container, message, isSuccess) {
            const $messageArea = $container.find('.tbp-inline-form-message');
            $messageArea
                .text(message)
                .removeClass('tbp-success tbp-error')
                .addClass(isSuccess ? 'tbp-success' : 'tbp-error')
                .show();
            setTimeout(() => $messageArea.fadeOut(), 5000);
        }

        function fetchTasks() {
            showLoading();
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_fetch_tasks',
                    nonce: nonce,
                    board_name: boardName,
                    search: $searchInput.val(),
                    sort: $sortSelect.val(),
                    status_filter: $statusFilterSelect.val()
                },
                success: function (response) {
                    hideLoading();
                    if (response.success) {
                        renderTaskList(response.data.tasks);
                    } else {
                        $taskList.html('<li class="tbp-error-item">' + escapeHtml(response.data.message || texts.error_general) + '</li>');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    hideLoading();
                    console.error("Fetch Tasks AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    $taskList.html('<li class="tbp-error-item">' + texts.error_general + '</li>');
                }
            });
        }

        function renderTaskList(tasks) {
            $taskList.empty();
            if (tasks && tasks.length > 0) {
                tasks.forEach(task => {
                    // Use the corrected escapeHtml function here
                    const deadline = task.deadline ? `Deadline: ${escapeHtml(task.deadline)}` : 'No deadline';
                    const assigned = task.assigned_user_names ? escapeHtml(task.assigned_user_names) : 'Unassigned';
                    const category = task.category_name ? escapeHtml(task.category_name) : 'Uncategorized';
                    const statusText = task.status ? escapeHtml(task.status.replace('-', ' ')) : 'unknown';
                    const listItem = `
                        <li class="tbp-task-item" data-task-id="${task.id}">
                            <div class="tbp-task-item-details">
                                <div class="tbp-task-item-name">${escapeHtml(task.name)}</div>
                                <div class="tbp-task-item-meta">
                                    <span data-status="${escapeHtml(task.status)}"><span class="tbp-task-status tbp-status-${escapeHtml(task.status)}">${statusText}</span></span>
                                    <span>Priority: ${escapeHtml(task.priority)}</span>
                                    <span>${deadline}</span>
                                    <span>Assigned: ${assigned}</span>
                                    <span>Category: ${category}</span>
                                </div>
                            </div>
                            <div class="tbp-task-item-actions">
                                <button class="tbp-icon-button tbp-edit-task-btn" title="Edit Task">✎</button> <!-- Pencil Icon -->
                                <button class="tbp-icon-button tbp-button-danger tbp-delete-task-btn" title="Delete Task">✖</button> <!-- Cross Icon -->
                            </div>
                        </li>
                    `;
                    $taskList.append(listItem);
                });
            } else {
                $taskList.html('<li class="tbp-no-tasks">No tasks found matching your criteria.</li>');
            }
        }

        function viewTaskDetails(taskId, $taskItem) {
            showLoading(); // Show loading indicator while fetching details
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_get_task_details',
                    nonce: nonce,
                    task_id: taskId
                },
                success: function (response) {
                    hideLoading();
                    if (response.success) {
                        renderTaskDetailsExpanded(response.data.task, $taskItem);
                    } else {
                        alert(escapeHtml(response.data.message || texts.error_general));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    hideLoading();
                    console.error("View Task Details AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    alert(texts.error_general);
                }
            });
        }

        function renderTaskDetailsExpanded(task, $taskItem) {
            // Create expandable details section
            const deadline = task.deadline ? escapeHtml(task.deadline) : 'N/A';
            const assigned = task.assigned_user_names ? escapeHtml(task.assigned_user_names) : 'Unassigned';
            const category = task.category_name ? escapeHtml(task.category_name) : 'Uncategorized';
            const statusText = task.status ? escapeHtml(task.status.replace('-', ' ')) : 'unknown';
            // Description HTML comes pre-sanitized (wp_kses_post) from the server. Do NOT escape it here.
            const descriptionHtml = task.description ? task.description : '<p><em>No description provided.</em></p>';

            // Create the details HTML
            const detailsHtml = `
                <div class="tbp-task-details-expandable">
                    <div class="tbp-task-details-content">
                        <h3>${escapeHtml(task.name)}</h3>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Status:</span>
                            <span class="tbp-detail-value" data-status="${escapeHtml(task.status)}"><span class="tbp-task-status tbp-status-${escapeHtml(task.status)}">${statusText}</span></span>
                        </div>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Priority:</span>
                            <span class="tbp-detail-value">${escapeHtml(task.priority)}</span>
                        </div>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Category:</span>
                            <span class="tbp-detail-value">${category}</span>
                        </div>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Deadline:</span>
                            <span class="tbp-detail-value">${deadline}</span>
                        </div>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Assigned To:</span>
                            <span class="tbp-detail-value">${assigned}</span>
                        </div>
                        <div class="tbp-detail-item">
                            <span class="tbp-detail-label">Description:</span>
                            <div class="tbp-task-details-description">${descriptionHtml}</div>
                        </div>
                        <div class="tbp-task-details-actions">
                            <button class="tbp-button tbp-edit-task-btn" data-task-id="${task.id}">Edit Task</button>
                            <button class="tbp-button tbp-button-danger tbp-delete-task-btn" data-task-id="${task.id}">Delete Task</button>
                        </div>
                    </div>
                    
                    <div class="tbp-task-comments">
                        <h4>Comments</h4>
                        <ul class="tbp-comment-list">
                            ${renderCommentsList(task.comments)}
                        </ul>
                        <form class="tbp-form tbp-comment-form">
                            <input type="hidden" name="task_id" class="tbp-comment-task-id" value="${task.id}">
                            <div class="tbp-form-field">
                                <label for="tbp-comment-text-${task.id}">Add Comment (you can @mention users):</label>
                                <textarea id="tbp-comment-text-${task.id}" name="comment_text" class="tbp-input" rows="3" required></textarea>
                            </div>
                            <div class="tbp-form-actions">
                                <button type="submit" class="tbp-button tbp-submit-comment-btn">Add Comment</button>
                                <div class="tbp-form-message tbp-comment-form-message" style="display: none;"></div>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            // Add the details section to the task item
            $taskItem.append(detailsHtml);
            
            // Add class to task item to indicate it's expanded
            $taskItem.addClass('tbp-details-expanded');
            
            // Show the details section
            $taskItem.find('.tbp-task-details-expandable').hide().slideDown(300);
        }
        
        function renderCommentsList(comments) {
            if (!comments || comments.length === 0) {
                return '<li class="tbp-no-comments">No comments yet.</li>';
            }
            
            let commentsHtml = '';
            comments.forEach(comment => {
                // Comment text HTML comes pre-sanitized (wp_kses_post) from the server. Do NOT escape it here.
                commentsHtml += `
                    <li class="tbp-comment-item">
                        <div class="tbp-comment-meta">
                            <span class="tbp-comment-author">${escapeHtml(comment.user_name)}</span>
                            <span class="tbp-comment-date">${formatDateTime(comment.created_at)}</span>
                        </div>
                        <div class="tbp-comment-text">${comment.comment_text}</div>
                    </li>
                `;
            });
            
            return commentsHtml;
        }

        function resetTaskForm() {
            $taskForm[0].reset();
            $taskForm.find('#tbp-task-id').val('');
            $taskForm.find('#tbp-task-description').val(''); // Explicitly clear textarea before TinyMCE init/reset
            $taskForm.find('.tbp-expandable-header h3').text('Add New Task');
            $taskForm.find('.tbp-submit-task-btn').text('Save Task');
            $taskForm.find('#tbp-task-category').val('');
            $taskForm.find('#tbp-task-priority').val('5'); // Reset to default
            $taskForm.find('.tbp-form-message').hide();
            
            // Clear user selection
            $taskForm.find('.tbp-selected-users-container').empty();
            $taskForm.find('#tbp-task-assigned').val('');
            $taskForm.find('#tbp-task-assigned-search').val('');
            
            // Hide inline category form
            $taskForm.find('.tbp-inline-category-form').hide();
            $taskForm.find('.tbp-new-category-name-inline').val('');
            $taskForm.find('.tbp-inline-form-message').hide();
            
            // TinyMCE will be initialized when the form is opened
        }

        function editTask(taskId) {
            resetTaskForm();
            showLoading(); // Show loading indicator
            
            // Close all expandable sections and task details
            closeAllExpandableSections();
            closeAllTaskDetails();
            
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_get_task_details', // Reuse details fetch
                    nonce: nonce,
                    task_id: taskId
                },
                success: function (response) {
                    hideLoading();
                    if (response.success) {
                        const task = response.data.task;
                        // Populate form
                        $taskForm.find('#tbp-task-id').val(task.id);
                        $taskForm.find('#tbp-task-name').val(task.name);
                        // Set textarea value *before* initializing TinyMCE for edit
                        $taskForm.find('#tbp-task-description').val(task.description || '');
                        $taskForm.find('#tbp-task-status').val(task.status);
                        $taskForm.find('#tbp-task-priority').val(task.priority);
                        $taskForm.find('#tbp-task-category').val(task.category_id || '');
                        $taskForm.find('#tbp-task-deadline').val(task.deadline || '');
                        
                        // Set up assigned users with the new autocomplete UI
                        const $selectedUsersContainer = $taskForm.find('.tbp-selected-users-container');
                        $selectedUsersContainer.empty();
                        
                        // Create selected user tags and populate hidden input
                        if (task.assigned_user_ids && task.assigned_user_ids.length) {
                            const userIds = [];
                            
                            // If we have user_ids and names, create the UI elements
                            if (task.assigned_user_names) {
                                const userNames = task.assigned_user_names.split(', ');
                                const assignedIds = task.assigned_user_ids.map(String);
                                
                                for (let i = 0; i < assignedIds.length && i < userNames.length; i++) {
                                    userIds.push(assignedIds[i]);
                                    addSelectedUserTag(assignedIds[i], userNames[i]);
                                }
                            } else {
                                // Just push IDs if no names available
                                userIds.push(...task.assigned_user_ids.map(String));
                            }
                            
                            // Update the hidden input with all user IDs
                            $taskForm.find('#tbp-task-assigned').val(userIds.join(','));
                        }

                        // Update form title and submit button
                        $taskForm.find('.tbp-expandable-header h3').text('Edit Task');
                        $taskForm.find('.tbp-submit-task-btn').text('Update Task');

                        // Show the form section
                        showExpandableSection($addTaskSection);
                        
                        // Initialize TinyMCE and datepicker
                        let editorId = $taskForm.find('.tbp-tinymce-editor').attr('id');
                        if (!editorId) {
                            editorId = 'tbp-task-description-' + boardName + '-' + Date.now();
                            $taskForm.find('.tbp-tinymce-editor').attr('id', editorId);
                        }
                        initTinyMCE(editorId);
                        initDatepicker($taskForm.find('.tbp-datepicker'));
                    } else {
                        alert(escapeHtml(response.data.message || texts.error_general));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    hideLoading();
                    console.error("Edit Task Fetch AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    alert(texts.error_general);
                }
            });
        }

        function saveTask() {
            const $button = $taskForm.find('.tbp-submit-task-btn');
            const taskId = $taskForm.find('#tbp-task-id').val();
            const action = taskId ? 'tbp_update_task' : 'tbp_add_task';
            const originalButtonText = $button.text();
        
            // Ensure TinyMCE saves its content to the textarea
            const editorId = $taskForm.find('.tbp-tinymce-editor').attr('id');
            if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).save(); // Trigger save to update underlying textarea
            }
        
            // ADDED FIX: Explicitly update the hidden input with current user selections
            const userIds = getSelectedUserIds();
            $taskForm.find('#tbp-task-assigned').val(userIds.join(','));
        
            const formData = $taskForm.serializeArray(); // Get form data as array
            // Manually add nonce and action
            formData.push({ name: 'action', value: action });
            formData.push({ name: 'nonce', value: nonce });
            // Add board name explicitly if not already part of form serialization
            let hasBoardName = formData.some(item => item.name === 'board_name');
            if (!hasBoardName && $taskForm.find('input[name="board_name"]').length > 0) {
                formData.push({ name: 'board_name', value: $taskForm.find('input[name="board_name"]').val() });
            } else if (!hasBoardName) {
                formData.push({ name: 'board_name', value: boardName }); // Fallback to board's data attribute
            }
        
            $button.prop('disabled', true).text(taskId ? 'Updating...' : 'Saving...');
        
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: $.param(formData), // Convert array to query string
                success: function (response) {
                    if (response.success) {
                        showFormMessage($taskForm, response.data.message, true);
                        fetchTasks(); // Refresh the list
                        
                        // Remove TinyMCE instance
                        if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                            tinymce.get(editorId).remove();
                        }
                        
                        // Close the form section after success
                        setTimeout(() => hideExpandableSection($addTaskSection), 1500);
                    } else {
                        // Display specific error message from server if available
                        showFormMessage($taskForm, escapeHtml(response.data.message || texts.error_general), false);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Save Task AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    showFormMessage($taskForm, texts.error_general, false);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }
        function deleteTask(taskId) {
            // Consider adding a visual cue that deletion is in progress
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_delete_task',
                    nonce: nonce,
                    task_id: taskId
                },
                success: function (response) {
                    if (response.success) {
                        // Find the item in the list and fade it out before removing
                        $taskList.find(`.tbp-task-item[data-task-id="${taskId}"]`).fadeOut(300, function() {
                            $(this).remove();
                            // Check if the list is now empty
                            if ($taskList.children().length === 0) {
                                fetchTasks(); // Re-fetch to show "No tasks found" message
                            }
                        });
                    } else {
                        alert(escapeHtml(response.data.message || texts.error_general));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Delete Task AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    alert(texts.error_general);
                }
            });
        }

        // --- Category Management Functions ---

        function loadCategoriesForManagement() {
            const $categoryListContainer = $categoriesSection.find('.tbp-category-list-container');
            const $categoryList = $categoriesSection.find('.tbp-category-list');
            $categoryList.html('<li>Loading categories...</li>'); // Provide feedback inside the list UL
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_fetch_categories',
                    nonce: nonce,
                    board_name: boardName
                },
                success: function (response) {
                    if (response.success) {
                        renderCategoryManagementList(response.data.categories);
                        // Also refresh category dropdown in add/edit form silently
                        loadCategoriesForSelect($boardContainer.find('#tbp-task-category'), true); // Pass silent=true maybe?
                    } else {
                        $categoryList.html('<li>Error loading categories: ' + escapeHtml(response.data.message || '') + '</li>');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Load Categories Mgmt AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    $categoryList.html('<li>' + texts.error_general + '</li>');
                }
            });
        }

        function renderCategoryManagementList(categories) {
            const $categoryList = $categoriesSection.find('.tbp-category-list');
            $categoryList.empty();
            if (categories && categories.length > 0) {
                categories.forEach(cat => {
                    // Use the corrected escapeHtml function
                    const item = `
                        <li class="tbp-category-item" data-category-id="${cat.id}">
                            <span class="tbp-category-name">${escapeHtml(cat.name)}</span>
                            <button class="tbp-icon-button tbp-button-danger tbp-delete-category-btn" data-category-id="${cat.id}" title="Delete Category">✖</button>
                        </li>
                    `;
                    $categoryList.append(item);
                });
            } else {
                $categoryList.html('<li>No categories defined yet.</li>');
            }
        }

        function addCategory() {
            const $button = $categoryForm.find('.tbp-add-category-btn');
            const $input = $categoryForm.find('#tbp-new-category-name');
            const categoryName = $input.val().trim();

            if (!categoryName) {
                showFormMessage($categoryForm, 'Category name cannot be empty.', false);
                $input.focus();
                return;
            }

            const originalButtonText = $button.text();
            $button.prop('disabled', true).text('Adding...');

            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_add_category',
                    nonce: nonce,
                    board_name: boardName,
                    name: categoryName
                },
                success: function (response) {
                    if (response.success) {
                        showFormMessage($categoryForm, response.data.message, true);
                        $input.val(''); // Clear input
                        loadCategoriesForManagement(); // Refresh list
                    } else {
                        showFormMessage($categoryForm, escapeHtml(response.data.message || 'Failed to add category.'), false);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Add Category AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    showFormMessage($categoryForm, texts.error_general, false);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }

        // Function to add a category from the inline form in task form
        function addCategoryInline() {
            const $inlineForm = $taskForm.find('.tbp-inline-category-form');
            const $input = $inlineForm.find('.tbp-new-category-name-inline');
            const $saveBtn = $inlineForm.find('.tbp-save-category-inline-btn');
            const categoryName = $input.val().trim();

            if (!categoryName) {
                showInlineFormMessage($inlineForm, 'Category name cannot be empty.', false);
                $input.focus();
                return;
            }

            const originalButtonText = $saveBtn.text();
            $saveBtn.prop('disabled', true).text('Adding...');

            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_add_category',
                    nonce: nonce,
                    board_name: boardName,
                    name: categoryName
                },
                success: function (response) {
                    if (response.success) {
                        showInlineFormMessage($inlineForm, 'Category added successfully!', true);
                        $input.val(''); // Clear input
                        
                        // Add the new category to the dropdown and select it
                        const newCategoryId = response.data.category.id;
                        const newCategoryName = response.data.category.name;
                        const $categorySelect = $taskForm.find('#tbp-task-category');
                        
                        // Add new option if it doesn't exist
                        if ($categorySelect.find(`option[value="${newCategoryId}"]`).length === 0) {
                            $categorySelect.append(`<option value="${newCategoryId}">${escapeHtml(newCategoryName)}</option>`);
                        }
                        
                        // Explicitly set value and trigger change event to ensure selection
                        $categorySelect.val(newCategoryId).trigger('change');
                        
                        // Hide the form after a delay
                        setTimeout(() => {
                            $inlineForm.hide();
                            $inlineForm.find('.tbp-inline-form-message').hide();
                        }, 1000);
                    } else {
                        showInlineFormMessage($inlineForm, escapeHtml(response.data.message || 'Failed to add category.'), false);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Add Category Inline AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    showInlineFormMessage($inlineForm, texts.error_general, false);
                },
                complete: function() {
                    $saveBtn.prop('disabled', false).text(originalButtonText);
                }
            });
        }

        function deleteCategory(categoryId) {
            // Visually indicate which category is being deleted maybe?
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_delete_category',
                    nonce: nonce,
                    category_id: categoryId,
                    board_name: boardName // Include board name for verification
                },
                success: function (response) {
                    if (response.success) {
                        // Fade out the item in the management list
                        $categoriesSection.find(`.tbp-category-item[data-category-id="${categoryId}"]`).fadeOut(300, function() {
                            $(this).remove();
                            if ($categoriesSection.find('.tbp-category-list').children().length === 0) {
                                $categoriesSection.find('.tbp-category-list').html('<li>No categories defined yet.</li>');
                            }
                        });
                        fetchTasks(); // Refresh task list as tasks might change category
                        // Refresh category dropdown in add/edit form
                        loadCategoriesForSelect($boardContainer.find('#tbp-task-category'));
                    } else {
                        alert(escapeHtml(response.data.message || 'Failed to delete category.'));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Delete Category AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    alert(texts.error_general);
                }
            });
        }

        // --- Comment Functions ---

        function addComment($form) {
            const $button = $form.find('.tbp-submit-comment-btn');
            const $textarea = $form.find('textarea[name="comment_text"]');
            const commentText = $textarea.val().trim();
            const taskId = $form.find('.tbp-comment-task-id').val();

            if (!commentText || !taskId) {
                showFormMessage($form, 'Comment cannot be empty.', false);
                $textarea.focus();
                return;
            }

            const originalButtonText = $button.text();
            $button.prop('disabled', true).text('Adding...');

            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_add_comment',
                    nonce: nonce,
                    task_id: taskId,
                    comment_text: commentText // Server side will sanitize using wp_kses_post
                },
                success: function (response) {
                    if (response.success) {
                        // Append the new comment to the list instantly
                        const newComment = response.data.comment;
                        // comment.comment_text is already sanitized by server wp_kses_post
                        const commentHtml = `
                            <li class="tbp-comment-item" style="display:none;">
                                <div class="tbp-comment-meta">
                                    <span class="tbp-comment-author">${escapeHtml(newComment.user_name)}</span>
                                    <span class="tbp-comment-date">${formatDateTime(newComment.created_at)}</span>
                                </div>
                                <div class="tbp-comment-text">${newComment.comment_text}</div>
                            </li>
                        `;
                        const $commentList = $form.closest('.tbp-task-comments').find('.tbp-comment-list');
                        $commentList.find('.tbp-no-comments').remove(); // Remove 'no comments' message if it was there
                        
                        const $newComment = $(commentHtml);
                        $commentList.append($newComment);
                        $newComment.fadeIn(300); // Fade in the new comment
                        
                        $textarea.val(''); // Clear textarea
                        //showFormMessage($form, response.data.message, true); // Message can be distracting here
                        $form.find('.tbp-form-message').hide(); // Clear any previous message
                    } else {
                        showFormMessage($form, escapeHtml(response.data.message || 'Failed to add comment.'), false);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("Add Comment AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    showFormMessage($form, texts.error_general, false);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }

        // --- User Autocomplete Functions ---
        
        function setupUserAutocomplete() {
            const $searchInput = $boardContainer.find('#tbp-task-assigned-search');
            const $suggestions = $boardContainer.find('.tbp-user-suggestions');
            const $selectedContainer = $boardContainer.find('.tbp-selected-users-container');
            const $hiddenInput = $boardContainer.find('#tbp-task-assigned');
            
            let searchTimeout;
            let currentSearchTerm = '';
            
            // Search as user types
            $searchInput.on('keyup', function() {
                const searchTerm = $(this).val().trim();
                currentSearchTerm = searchTerm;
                
                if (searchTerm.length < 2) {
                    $suggestions.hide();
                    return;
                }
                
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    // Show "searching" message
                    $suggestions.html(`<div class="tbp-user-suggestion-item">${texts.searching}</div>`).show();
                    
                    // Perform AJAX search
                    searchUsers(searchTerm);
                }, 300);
            });
            
            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.tbp-user-autocomplete-container').length) {
                    $suggestions.hide();
                }
            });
            
            // Click on a suggestion
            $boardContainer.on('click', '.tbp-user-suggestion-item', function() {
                const userId = $(this).data('user-id');
                const userName = $(this).text();
                
                // Only add if not already selected
                if (!isUserSelected(userId)) {
                    addSelectedUserTag(userId, userName);
                    
                    // Update hidden input with all selected users
                    updateSelectedUsersInput();
                }
                
                // Clear search and hide suggestions
                $searchInput.val('').focus();
                $suggestions.hide();
            });
            
            // Remove a selected user
            $boardContainer.on('click', '.tbp-remove-user', function() {
                $(this).parent('.tbp-selected-user').remove();
                updateSelectedUsersInput();
            });
        }
        
        function searchUsers(term) {
            const $suggestions = $boardContainer.find('.tbp-user-suggestions');
            
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_fetch_users',
                    nonce: nonce,
                    search: term
                },
                success: function(response) {
                    if (response.success && response.data.users) {
                        renderUserSuggestions(response.data.users);
                    } else {
                        $suggestions.html(`<div class="tbp-user-suggestion-item">${texts.no_results_found}</div>`);
                    }
                },
                error: function() {
                    $suggestions.html(`<div class="tbp-user-suggestion-item">${texts.error_general}</div>`);
                }
            });
        }
        
        function renderUserSuggestions(users) {
            const $suggestions = $boardContainer.find('.tbp-user-suggestions');
            $suggestions.empty();
            
            if (users.length === 0) {
                $suggestions.html(`<div class="tbp-user-suggestion-item">${texts.no_results_found}</div>`);
                return;
            }
            
            // Get currently selected user IDs to filter them out
            const selectedUserIds = getSelectedUserIds();
            
            // Create HTML for suggestions, filtering out already selected users
            let suggestionsHtml = '';
            let suggestionsCount = 0;
            
            users.forEach(user => {
                // Skip users that are already selected
                if (selectedUserIds.includes(user.id.toString())) {
                    return;
                }
                
                suggestionsHtml += `<div class="tbp-user-suggestion-item" data-user-id="${user.id}">${escapeHtml(user.name)}</div>`;
                suggestionsCount++;
            });
            
            if (suggestionsCount === 0) {
                $suggestions.html(`<div class="tbp-user-suggestion-item">${texts.no_results_found}</div>`);
            } else {
                $suggestions.html(suggestionsHtml);
            }
            
            $suggestions.show();
        }
        
        function addSelectedUserTag(userId, userName) {
            const $selectedContainer = $boardContainer.find('.tbp-selected-users-container');
            const userTag = `
                <div class="tbp-selected-user" data-user-id="${userId}">
                    ${escapeHtml(userName)}
                    <span class="tbp-remove-user" title="Remove">×</span>
                </div>
            `;
            $selectedContainer.append(userTag);
        }
        
        function isUserSelected(userId) {
            return $boardContainer.find(`.tbp-selected-user[data-user-id="${userId}"]`).length > 0;
        }
        
        function getSelectedUserIds() {
            const userIds = [];
            $boardContainer.find('.tbp-selected-user').each(function() {
                userIds.push($(this).data('user-id').toString());
            });
            return userIds;
        }
        
        function updateSelectedUsersInput() {
            const userIds = getSelectedUserIds();
            $boardContainer.find('#tbp-task-assigned').val(userIds.join(','));
        }

        // --- Helper Functions ---

        function initDatepicker($element) {
            if ($element.length && $.fn.datepicker && !$element.hasClass('hasDatepicker')) {
                $element.datepicker({
                    dateFormat: 'yy-mm-dd', // Match PHP format expected
                    changeMonth: true,
                    changeYear: true,
                    showButtonPanel: true, // Optional: Adds Today/Done buttons
                    constrainInput: true,   // Optional: Prevent manual input of invalid dates
                    beforeShow: function(input, inst) {
                        // Position the datepicker relative to the input
                        inst.dpDiv.addClass('tbp-datepicker-wrapper');
                        setTimeout(function() {
                            // Ensure it stays within viewport
                            const inputOffset = $(input).offset();
                            const windowWidth = $(window).width();
                            if (inputOffset.left + inst.dpDiv.outerWidth() > windowWidth) {
                                // Adjust position for small screens
                                inst.dpDiv.css({
                                    left: (windowWidth - inst.dpDiv.outerWidth() - 10) + 'px'
                                });
                            }
                        }, 0);
                    }
                });
            }
        }

        function initTinyMCE(editorId) {
            // Ensure editorId is valid and TinyMCE is loaded
            if (typeof tinymce === 'undefined' || !editorId || editorId.trim() === '') {
                console.error("TinyMCE not loaded or editor ID is invalid:", editorId);
                return;
            }

            // Remove existing instance if present
            if (tinymce.get(editorId)) {
                tinymce.get(editorId).remove();
            }

            // Get settings from localized object, ensuring selector targets the specific ID
            let settings = tbp_ajax_object.tinymce_settings || {};
            settings.selector = '#' + editorId;
            settings.promotion = false; // Disable the "Upgrade to TinyMCE Premium" promotion
            settings.height = settings.height || 200; // Set a default height if not provided

            // Setup function can be used for events after initialization
            settings.setup = function(editor) {
                editor.on('init', function() {
                    // console.log('TinyMCE initialized for:', editorId);
                    // Set initial content if needed (especially for edit)
                    // This assumes the textarea's value was set *before* calling initTinyMCE
                    const initialContent = $('#' + editorId).val();
                    if (initialContent) {
                       // editor.setContent(initialContent); // This might cause issues if called too early
                    }
                });
                 editor.on('change', function () {
                    // Trigger save on change to keep the underlying textarea updated
                    editor.save();
                });
            };

            // Initialize TinyMCE
            tinymce.init(settings);
        }

        function loadCategoriesForSelect($selectElement, silent = false) {
            const currentValue = $selectElement.val(); // Preserve selection if possible
            
            if (!silent) {
                // Show loading state
                $selectElement.prop('disabled', true).html('<option value="">Loading...</option>');
                
                // If Nice Select is being used
                if ($.fn.niceSelect && $selectElement.next('.nice-select').length) {
                    $selectElement.niceSelect('update');
                }
            }
            
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_fetch_categories',
                    nonce: nonce,
                    board_name: boardName
                },
                success: function (response) {
                    if (response.success && response.data.categories) {
                        $selectElement.empty().append('<option value="">-- Select Category --</option>');
                        
                        // Add categories to dropdown
                        response.data.categories.forEach(cat => {
                            $selectElement.append(`<option value="${cat.id}">${escapeHtml(cat.name)}</option>`);
                        });
                        
                        // Restore selection if it's a valid category ID from the new list
                        if (currentValue && response.data.categories.some(c => c.id == currentValue)) {
                            $selectElement.val(currentValue);
                        } else {
                            $selectElement.val(''); // Reset if previous selection is no longer valid
                        }
                        
                        // Enable the select
                        $selectElement.prop('disabled', false);
                        
                        // IMPORTANT: Update Nice Select if it's being used
                        if ($.fn.niceSelect && $selectElement.next('.nice-select').length) {
                            $selectElement.niceSelect('update');
                        }
                    } else {
                        $selectElement.html('<option value="">-- Error Loading --</option>');
                        $selectElement.prop('disabled', false);
                        
                        // Update Nice Select to show error
                        if ($.fn.niceSelect && $selectElement.next('.nice-select').length) {
                            $selectElement.niceSelect('update');
                        }
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("AJAX error loading categories:", textStatus, errorThrown, jqXHR.responseText);
                    $selectElement.html('<option value="">-- Error Loading --</option>');
                    $selectElement.prop('disabled', false);
                    
                    // Update Nice Select to show error
                    if ($.fn.niceSelect && $selectElement.next('.nice-select').length) {
                        $selectElement.niceSelect('update');
                    }
                }
            });
        }

        
        // --- @mention Autocomplete ---
        function setupMentionAutocomplete() {
            // Delegated event handler for comment textareas
            $('body').on('keyup', '.tbp-container .tbp-comment-form textarea', function(e) {
                const $textarea = $(this);
                
                // Handle up/down/enter/escape keys for selection
                if (e.which === 38 || e.which === 40 || e.which === 13 || e.which === 27) {
                    const $dropdown = $('.tbp-mention-dropdown');
                    if ($dropdown.is(':visible')) {
                        handleMentionKeyNavigation(e.which, $dropdown, $textarea);
                        e.preventDefault();
                        return;
                    }
                }
                
                const text = $textarea.val();
                const caretPos = this.selectionStart;
                
                // Find the @ symbol before the current caret position
                let startPos = caretPos - 1;
                while (startPos >= 0 && text[startPos] !== ' ' && text[startPos] !== '\n') {
                    if (text[startPos] === '@') {
                        // Found @ symbol, extract search term
                        const searchTerm = text.substring(startPos + 1, caretPos);
                        
                        console.log("Found @ at position:", startPos);
                        console.log("Search term:", searchTerm);
                        
                        // Store current position in data attributes for later use
                        $textarea.attr('data-mention-start', startPos);
                        $textarea.attr('data-mention-end', caretPos);
                        $textarea.attr('data-mention-active', 'true');
                        
                        // Search for matching users
                        if (searchTerm.length >= 1) {
                            searchMentionUsers(searchTerm, $textarea);
                        } else {
                            closeMentionDropdown();
                        }
                        return;
                    }
                    startPos--;
                }
                
                // No relevant @ found, close dropdown if open
                closeMentionDropdown();
            });
            
            // Close dropdown when clicking elsewhere
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.tbp-mention-dropdown').length && 
                    !$(e.target).closest('.tbp-comment-form textarea').length) {
                    closeMentionDropdown();
                }
            });
            
            // Handle mention selection by click
            $('body').on('click', '.tbp-mention-item', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const userId = $(this).data('user-id');
                
                // Get the plain text without any HTML formatting
                // Clone the element to safely manipulate it
                const $clone = $(this).clone();
                // Replace highlight spans with their text content
                $clone.find('.tbp-mention-highlight').replaceWith(function() {
                    return $(this).text();
                });
                const userName = $clone.text().trim();
                
                console.log("Selected user:", userName, "ID:", userId);
                
                // Find the active textarea
                const $textarea = $('.tbp-comment-form textarea[data-mention-active="true"]');
                
                if ($textarea.length) {
                    console.log("Found active textarea:", $textarea.attr('id'));
                    insertMention($textarea, userId, userName);
                } else {
                    console.error("No active textarea found for mention insertion");
                    // Try a fallback - find closest comment form
                    const $fallbackTextarea = $(this).closest('.tbp-container').find('.tbp-comment-form textarea');
                    if ($fallbackTextarea.length) {
                        console.log("Using fallback textarea");
                        insertMention($fallbackTextarea, userId, userName);
                    }
                }
                
                closeMentionDropdown();
            });
        }

        function searchMentionUsers(term, $textarea) {
            // Reuse the user search AJAX
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'tbp_fetch_users',
                    nonce: nonce,
                    search: term
                },
                success: function(response) {
                    if (response.success && response.data.users && response.data.users.length > 0) {
                        showMentionDropdown(response.data.users, term, $textarea);
                    } else {
                        closeMentionDropdown();
                    }
                },
                error: function() {
                    closeMentionDropdown();
                }
            });
        }

        function showMentionDropdown(users, searchTerm, $textarea) {
            // Remove any existing dropdown
            closeMentionDropdown();
            
            // Store a reference to which textarea is active
            $textarea.attr('data-mention-active', 'true');
            
            // Calculate position for dropdown
            const pos = getCaretCoordinates($textarea[0], parseInt($textarea.attr('data-mention-end')));
            const offset = $textarea.offset();
            
            // Create dropdown with a reference to the textarea
            const $dropdown = $('<div class="tbp-mention-dropdown"></div>');
            $dropdown.attr('data-for-textarea', $textarea.attr('id') || '');
            
            // Add user items
            users.slice(0, 10).forEach(user => { // Limit to 10 results
                const highlightedName = highlightMatch(escapeHtml(user.name), searchTerm);
                $dropdown.append(`<div class="tbp-mention-item" data-user-id="${user.id}">${highlightedName}</div>`);
            });
            
            // Position and append dropdown
            $dropdown.css({
                top: (offset.top + pos.top + 20) + 'px',
                left: (offset.left + pos.left) + 'px'
            }).appendTo('body');
            
            // Highlight first item
            $dropdown.find('.tbp-mention-item:first').addClass('active');
        }

        function closeMentionDropdown() {
            $('.tbp-mention-dropdown').remove();
            // Don't clear active flag here, do it only when we insert
        }

        function handleMentionKeyNavigation(keyCode, $dropdown, $textarea) {
            const $items = $dropdown.find('.tbp-mention-item');
            const $active = $items.filter('.active');
            
            if (keyCode === 27) { // Escape
                closeMentionDropdown();
                $textarea.removeAttr('data-mention-active');
                return;
            }
            
            if (keyCode === 13) { // Enter
                if ($active.length) {
                    // Similar extraction logic as in click handler
                    const userId = $active.data('user-id');
                    const $clone = $active.clone();
                    $clone.find('.tbp-mention-highlight').replaceWith(function() {
                        return $(this).text();
                    });
                    const userName = $clone.text().trim();
                    
                    insertMention($textarea, userId, userName);
                    closeMentionDropdown();
                }
                return;
            }
            
            if (keyCode === 38) { // Up arrow
                if ($active.length) {
                    const $prev = $active.prev('.tbp-mention-item');
                    if ($prev.length) {
                        $active.removeClass('active');
                        $prev.addClass('active');
                        scrollDropdownToItem($dropdown, $prev);
                    }
                }
                return;
            }
            
            if (keyCode === 40) { // Down arrow
                if ($active.length) {
                    const $next = $active.next('.tbp-mention-item');
                    if ($next.length) {
                        $active.removeClass('active');
                        $next.addClass('active');
                        scrollDropdownToItem($dropdown, $next);
                    }
                } else {
                    $items.first().addClass('active');
                }
                return;
            }
        }

        function insertMention($textarea, userId, userName) {
            // Get the current text and cursor position
            const text = $textarea.val();
            const startPos = parseInt($textarea.attr('data-mention-start'));
            const endPos = parseInt($textarea.attr('data-mention-end'));
            
            console.log("Mention positions:", startPos, endPos);
            console.log("Text before:", text.substring(startPos, endPos));
            
            // Create the mention text
            const mentionText = `@[${userName}](${userId})`;
            
            // Replace the text - from the @ character to the current cursor position
            const newText = text.substring(0, startPos) + mentionText + ' ' + text.substring(endPos);
            
            console.log("New text:", newText);
            
            // Update the textarea
            $textarea.val(newText);
            
            // Clear the active state after insertion
            $textarea.removeAttr('data-mention-active');
            
            // Set cursor position after the inserted mention and the space
            const newPosition = startPos + mentionText.length + 1;
            
            // Set focus and cursor position with a setTimeout to ensure the browser has time
            // to process the new value
            $textarea.focus();
            setTimeout(function() {
                $textarea[0].selectionStart = newPosition;
                $textarea[0].selectionEnd = newPosition;
            }, 50);
        }

        function highlightMatch(text, query) {
            if (!query) return text;
            const regex = new RegExp('(' + escapeRegExp(query) + ')', 'gi');
            return text.replace(regex, '<span class="tbp-mention-highlight">$1</span>');
        }

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        function scrollDropdownToItem($dropdown, $item) {
            const dropdownTop = $dropdown.scrollTop();
            const dropdownHeight = $dropdown.height();
            const itemTop = $item.position().top;
            const itemHeight = $item.outerHeight();
            
            if (itemTop < 0) {
                $dropdown.scrollTop(dropdownTop + itemTop);
            } else if (itemTop + itemHeight > dropdownHeight) {
                $dropdown.scrollTop(dropdownTop + itemTop - dropdownHeight + itemHeight);
            }
        }

        // Helper function to get caret position in textarea
        function getCaretCoordinates(element, position) {
            // Create a mirror div to copy styles
            const div = document.createElement('div');
            const computed = window.getComputedStyle(element);
            const properties = [
                'direction', 'boxSizing', 'width', 'height', 'overflowX', 'overflowY', 
                'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
                'borderStyle', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                'fontStyle', 'fontVariant', 'fontWeight', 'fontStretch', 'fontSize', 'fontSizeAdjust',
                'lineHeight', 'fontFamily', 'textAlign', 'textTransform', 'textIndent', 'textDecoration',
                'letterSpacing', 'wordSpacing'
            ];
            
            // Copy computed styles
            properties.forEach(prop => {
                div.style[prop] = computed[prop];
            });
            
            // Set div content to text until caret position
            div.textContent = element.value.substring(0, position);
            
            // Create a span to get position of caret
            const span = document.createElement('span');
            span.textContent = element.value.substring(position) || '.';
            div.appendChild(span);
            
            // Set div styles for accurate measurement
            div.style.position = 'absolute';
            div.style.visibility = 'hidden';
            div.style.whiteSpace = 'pre-wrap';
            
            // Add div to document for measurement
            document.body.appendChild(div);
            
            // Get position
            const coordinates = {
                top: span.offsetTop,
                left: span.offsetLeft
            };
            
            // Clean up
            document.body.removeChild(div);
            
            return coordinates;
        }

        // HTML escaping function
        function escapeHtml(unsafe) {
            if (unsafe === null || typeof unsafe === 'undefined') return '';
            return String(unsafe)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;"); // Use numerical entity ' for broader compatibility
        }

        function formatDateTime(dateTimeString) {
            // Handles 'YYYY-MM-DD HH:MM:SS' format from MySQL
            if (!dateTimeString) return '';
            try {
                // Ensure it's treated as UTC if coming directly from DB without timezone conversion
                // Or adjust based on how WordPress stores/retrieves dates (it might convert to site timezone)
                const date = new Date(dateTimeString.replace(' ', 'T') + 'Z'); // Assume UTC input
                if (isNaN(date.getTime())) { // Check if date is valid
                    return dateTimeString; // Return original if invalid
                }
                // Format to user's locale string
                return date.toLocaleString(undefined, { // 'undefined' uses browser default locale
                    year: 'numeric', month: 'short', day: 'numeric',
                    hour: 'numeric', minute: '2-digit'
                    // timeZoneName: 'short' // Optional: add timezone abbreviation
                });
            } catch (e) {
                console.error("Error formatting date:", dateTimeString, e);
                return dateTimeString; // Return original string if formatting fails
            }
        }
        
        // Initialize user autocomplete after document ready
        setupUserAutocomplete();
        setupMentionAutocomplete();
    }); // End .tbp-container.each
}); // End jQuery ready