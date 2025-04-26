jQuery(document).ready(function ($) {
    // --- Configuration ---
    const ajax_url = pandat69_ajax_object.ajax_url;
    const nonce = pandat69_ajax_object.nonce;
    const currentUserId = pandat69_ajax_object.current_user_id;
    const currentUserDisplayName = pandat69_ajax_object.current_user_display_name;
    const texts = pandat69_ajax_object.text;

    // --- Initialization for each board on the page ---
    $('.pandat69-container').each(function () {
        const $boardContainer = $(this);
        const boardName = $boardContainer.data('board-name');

        // Cache selectors for the current board
        const $taskList = $boardContainer.find('.pandat69-task-list');
        const $loading = $boardContainer.find('.pandat69-loading');
        const $searchInput = $boardContainer.find('.pandat69-search-input');
        const $sortSelect = $boardContainer.find('.pandat69-sort-select');
        const $statusFilterSelect = $boardContainer.find('.pandat69-status-filter-select');

        // Expandable sections
        const $addTaskSection = $boardContainer.find('.pandat69-add-task-section');
        const $categoriesSection = $boardContainer.find('.pandat69-categories-section');

        // Forms
        const $taskForm = $boardContainer.find('.pandat69-task-form');
        const $categoryForm = $boardContainer.find('.pandat69-add-category-form');

        // --- Initial Load ---
        fetchTasks();
        loadCategoriesForSelect($boardContainer.find('#pandat69-task-category')); // Load categories for add/edit form

        // --- Event Listeners ---

        // Open Add Task Section
        $boardContainer.on('click', '.pandat69-add-task-btn', function () {
            resetTaskForm();
            closeAllExpandableSections();
            showExpandableSection($addTaskSection);
            
            // Initialize TinyMCE
            let editorId = $taskForm.find('.pandat69-tinymce-editor').attr('id');
            if (!editorId) {
                editorId = 'pandat69-task-description-' + boardName + '-' + Date.now(); // Create dynamic unique ID
                $taskForm.find('.pandat69-tinymce-editor').attr('id', editorId);
            }
            initTinyMCE(editorId);
            
            // Initialize datepicker
            initDatepicker($taskForm.find('.pandat69-datepicker'));
        });

        // Open Categories Section
        $boardContainer.on('click', '.pandat69-manage-categories-btn', function () {
            loadCategoriesForManagement();
            closeAllExpandableSections();
            showExpandableSection($categoriesSection);
        });

        // Close Expandable Sections
        $boardContainer.on('click', '.pandat69-close-expandable, .pandat69-cancel-task-btn', function () {
            const $section = $(this).closest('.pandat69-expandable-section');
            
            // If it's the task form and TinyMCE is active, destroy it
            if ($section.is($addTaskSection)) {
                const editorId = $taskForm.find('.pandat69-tinymce-editor').attr('id');
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
        $categoriesSection.on('click', '.pandat69-delete-category-btn', function () {
            const categoryId = $(this).data('category-id');
            const categoryName = $(this).closest('.pandat69-category-item').find('.pandat69-category-name').text();
            if (confirm(texts.confirm_delete_category.replace('%s', categoryName))) {
                deleteCategory(categoryId);
            }
        });

        // Task List Item Click (Toggle Details)
        $taskList.on('click', '.pandat69-task-item', function (e) {
            // Prevent toggling if clicking on action buttons, expanded details content, OR STATUS LABELS
            if ($(e.target).closest('.pandat69-task-item-actions').length === 0 && 
                $(e.target).closest('.pandat69-task-details-expandable').length === 0 &&
                $(e.target).closest('.pandat69-task-status').length === 0) { // Add this condition
                const taskId = $(this).data('task-id');
                
                // Check if THIS task is already expanded
                const isCurrentlyExpanded = $(this).hasClass('pandat69-details-expanded');
                
                // Clear any active add/edit forms
                closeAllExpandableSections();
                
                // If this task is already expanded, just close it and do nothing else
                if (isCurrentlyExpanded) {
                    // Remove expanded class
                    $(this).removeClass('pandat69-details-expanded');
                    // Hide and remove the details section
                    $(this).find('.pandat69-task-details-expandable').slideUp(300, function() {
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
        $taskList.on('click', '.pandat69-task-details-expandable', function (e) {
            e.stopPropagation();
        });

        // Edit Task Button
        $taskList.on('click', '.pandat69-edit-task-btn', function (e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            const taskId = $(this).closest('.pandat69-task-item').data('task-id');
            editTask(taskId);
        });

        // Delete Task Button
        $taskList.on('click', '.pandat69-delete-task-btn', function (e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            const taskId = $(this).closest('.pandat69-task-item').data('task-id');
            if (confirm(texts.confirm_delete_task)) {
                deleteTask(taskId);
            }
        });

        // Comment Form Submit (Dynamically added)
        $taskList.on('submit', '.pandat69-comment-form', function (e) {
            e.preventDefault();
            e.stopPropagation(); // Add this line to stop event propagation
            const $form = $(this);
            addComment($form);
        });

        // Add new category from task form
        $taskForm.on('click', '.pandat69-add-category-inline-btn', function(e) {
            e.preventDefault();
            $taskForm.find('.pandat69-inline-category-form').slideDown(200);
            $taskForm.find('.pandat69-new-category-name-inline').focus();
        });
        
        // Save new category from task form
        $taskForm.on('click', '.pandat69-save-category-inline-btn', function(e) {
            e.preventDefault();
            addCategoryInline();
        });
        
        // Cancel adding category from task form
        $taskForm.on('click', '.pandat69-cancel-category-inline-btn', function(e) {
            e.preventDefault();
            $taskForm.find('.pandat69-inline-category-form').slideUp(200);
            $taskForm.find('.pandat69-new-category-name-inline').val('');
            $taskForm.find('.pandat69-inline-form-message').hide();
        });

        // Status Quick Change
        $taskList.on('click', '.pandat69-task-status', function(e) {
            e.stopPropagation(); // Prevent triggering task details toggle
            
            const $statusLabel = $(this);
            const $taskItem = $statusLabel.closest('.pandat69-task-item');
            const taskId = $taskItem.data('task-id');
            const currentStatus = $statusLabel.parent().data('status');
            
            // Close any other open dropdowns first
            $('.pandat69-status-dropdown').remove();
            
            // Don't do anything if we're already showing the dropdown
            if ($statusLabel.parent().find('.pandat69-status-dropdown').length) {
                return;
            }
            
            // Create dropdown with status options
            const dropdown = `
                <div class="pandat69-status-dropdown">
                    <div class="pandat69-status-option pandat69-status-pending" data-status="pending">Pending</div>
                    <div class="pandat69-status-option pandat69-status-in-progress" data-status="in-progress">In Progress</div>
                    <div class="pandat69-status-option pandat69-status-done" data-status="done">Done</div>
                </div>
            `;
            
            // Add dropdown to the DOM
            $statusLabel.parent().append(dropdown);
            
            // Highlight current status
            $statusLabel.parent().find(`.pandat69-status-option[data-status="${currentStatus}"]`).addClass('pandat69-current-status');
            
            // Prevent immediate closing when clicking on the status label
            e.stopImmediatePropagation();
            
            // Handle click outside to close dropdown
            $(document).one('click', function() {
                $('.pandat69-status-dropdown').remove();
            });
        });


        // Handle status selection - modify this handler to properly stop propagation
        $taskList.on('click', '.pandat69-status-option', function(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            const $option = $(this);
            const newStatus = $option.data('status');
            const $statusContainer = $option.closest('.pandat69-task-item-meta').find('.pandat69-task-status').parent();
            const $taskItem = $option.closest('.pandat69-task-item');
            const taskId = $taskItem.data('task-id');
            
            // Only proceed if this is a new status
            if ($option.hasClass('pandat69-current-status')) {
                $('.pandat69-status-dropdown').remove();
                return;
            }
            
            // Show loading state
            $statusContainer.find('.pandat69-task-status').html('<small>Updating...</small>');
            $('.pandat69-status-dropdown').remove();
            
            // Send AJAX request to update status
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_quick_update_status',
                    nonce: nonce,
                    task_id: taskId,
                    status: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        // Update the status label
                        const $status = $statusContainer.find('.pandat69-task-status');
                        $status
                            .removeClass('pandat69-status-pending pandat69-status-in-progress pandat69-status-done')
                            .addClass('pandat69-status-' + newStatus)
                            .text(response.data.status_text);
                        
                        // Update the data attribute
                        $statusContainer.data('status', newStatus);
                        
                        // Briefly show success indicator
                        $status.append(' <span class="pandat69-status-updated">✓</span>');
                        setTimeout(function() {
                            $status.find('.pandat69-status-updated').fadeOut(300, function() { $(this).remove(); });
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
            $boardContainer.find('.pandat69-expandable-section').hide(); 
            
            // Keep TinyMCE cleanup code unchanged
            const editorId = $taskForm.find('.pandat69-tinymce-editor').attr('id');
            if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).remove();
            }
        }

        function closeAllTaskDetails() {
            // Find all tasks with expanded details and hide them immediately
            $taskList.find('.pandat69-task-item.pandat69-details-expanded').each(function() {
                $(this).removeClass('pandat69-details-expanded');
                $(this).find('.pandat69-task-details-expandable').hide().remove(); // Replace slideUp with hide
            });
        }

        function showFormMessage($form, message, isSuccess) {
            const $messageArea = $form.find('.pandat69-form-message');
            $messageArea
                .text(message)
                .removeClass('pandat69-success pandat69-error')
                .addClass(isSuccess ? 'pandat69-success' : 'pandat69-error')
                .show();
            // Consider making timeout configurable or longer
            setTimeout(() => $messageArea.fadeOut(), 5000);
        }

        function showInlineFormMessage($container, message, isSuccess) {
            const $messageArea = $container.find('.pandat69-inline-form-message');
            $messageArea
                .text(message)
                .removeClass('pandat69-success pandat69-error')
                .addClass(isSuccess ? 'pandat69-success' : 'pandat69-error')
                .show();
            setTimeout(() => $messageArea.fadeOut(), 5000);
        }

        function fetchTasks() {
            showLoading();
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_fetch_tasks',
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
                        $taskList.html('<li class="pandat69-error-item">' + escapeHtml(response.data.message || texts.error_general) + '</li>');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    hideLoading();
                    console.error("Fetch Tasks AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    $taskList.html('<li class="pandat69-error-item">' + texts.error_general + '</li>');
                }
            });
        }

        function renderTaskList(tasks) {
            $taskList.empty();
            if (tasks && tasks.length > 0) {
                tasks.forEach(task => {
                    // SVG icons
                    const priorityIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pandat69-icon"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>`;
                    const deadlineIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pandat69-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>`;
                    const assignedIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pandat69-icon"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>`;
                    const categoryIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pandat69-icon"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>`;
                    
                    // Use the corrected escapeHtml function here
                    const deadline = task.deadline ? `<strong>Deadline:</strong> ${escapeHtml(task.deadline)}` : '<strong>Deadline:</strong> None';
                    const assigned = task.assigned_user_names ? `<strong>Assigned:</strong> ${escapeHtml(task.assigned_user_names)}` : '<strong>Assigned:</strong> Unassigned';
                    const category = task.category_name ? `<strong>Category:</strong> ${escapeHtml(task.category_name)}` : '<strong>Category:</strong> Uncategorized';
                    const statusText = task.status ? escapeHtml(task.status.replace('-', ' ')) : 'unknown';
                    const listItem = `
                        <li class="pandat69-task-item" data-task-id="${task.id}">
                            <div class="pandat69-task-item-details">
                                <div class="pandat69-task-item-name">${escapeHtml(task.name)}</div>
                                <div class="pandat69-task-item-meta">
                                    <span data-status="${escapeHtml(task.status)}"><span class="pandat69-task-status pandat69-status-${escapeHtml(task.status)}">${statusText}</span></span>
                                    <span>${priorityIcon} <strong>Priority:</strong> ${escapeHtml(task.priority)}</span>
                                    <span>${deadlineIcon} ${deadline}</span>
                                    <span>${assignedIcon} ${assigned}</span>
                                    <span>${categoryIcon} ${category}</span>
                                </div>
                            </div>
                            <div class="pandat69-task-item-actions">
                                <button class="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task">✎</button>
                                <button class="pandat69-icon-button pandat69-button-danger pandat69-delete-task-btn" title="Delete Task">✖</button>
                            </div>
                        </li>
                    `;
                    $taskList.append(listItem);
                });
            } else {
                $taskList.html('<li class="pandat69-no-tasks">No tasks found matching your criteria.</li>');
            }
        }

        function viewTaskDetails(taskId, $taskItem) {
            showLoading(); // Show loading indicator while fetching details
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_get_task_details',
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
                <div class="pandat69-task-details-expandable">
                    <div class="pandat69-task-details-content">
                        <h3>${escapeHtml(task.name)}</h3>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Status:</span>
                            <span class="pandat69-detail-value" data-status="${escapeHtml(task.status)}"><span class="pandat69-task-status pandat69-status-${escapeHtml(task.status)}">${statusText}</span></span>
                        </div>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Priority:</span>
                            <span class="pandat69-detail-value">${escapeHtml(task.priority)}</span>
                        </div>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Category:</span>
                            <span class="pandat69-detail-value">${category}</span>
                        </div>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Deadline:</span>
                            <span class="pandat69-detail-value">${deadline}</span>
                        </div>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Assigned To:</span>
                            <span class="pandat69-detail-value">${assigned}</span>
                        </div>
                        <div class="pandat69-detail-item">
                            <span class="pandat69-detail-label">Description:</span>
                            <div class="pandat69-task-details-description">${descriptionHtml}</div>
                        </div>
                        <div class="pandat69-task-details-actions">
                            <button class="pandat69-button pandat69-edit-task-btn" data-task-id="${task.id}">Edit Task</button>
                            <button class="pandat69-button pandat69-button-danger pandat69-delete-task-btn" data-task-id="${task.id}">Delete Task</button>
                        </div>
                    </div>
                    
                    <div class="pandat69-task-comments">
                        <h4>Comments</h4>
                        <ul class="pandat69-comment-list">
                            ${renderCommentsList(task.comments)}
                        </ul>
                        <form class="pandat69-form pandat69-comment-form">
                            <input type="hidden" name="task_id" class="pandat69-comment-task-id" value="${task.id}">
                            <div class="pandat69-form-field">
                                <label for="pandat69-comment-text-${task.id}">Add Comment (you can @mention users):</label>
                                <textarea id="pandat69-comment-text-${task.id}" name="comment_text" class="pandat69-input" rows="3" required></textarea>
                            </div>
                            <div class="pandat69-form-actions">
                                <button type="submit" class="pandat69-button pandat69-submit-comment-btn">Add Comment</button>
                                <div class="pandat69-form-message pandat69-comment-form-message" style="display: none;"></div>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            // Add the details section to the task item
            $taskItem.append(detailsHtml);
            
            // Add class to task item to indicate it's expanded
            $taskItem.addClass('pandat69-details-expanded');
            
            // Show the details section
            $taskItem.find('.pandat69-task-details-expandable').hide().slideDown(300);
        }
        
        function renderCommentsList(comments) {
            if (!comments || comments.length === 0) {
                return '<li class="pandat69-no-comments">No comments yet.</li>';
            }
            
            let commentsHtml = '';
            comments.forEach(comment => {
                // Comment text HTML comes pre-sanitized (wp_kses_post) from the server. Do NOT escape it here.
                commentsHtml += `
                    <li class="pandat69-comment-item">
                        <div class="pandat69-comment-meta">
                            <span class="pandat69-comment-author">${escapeHtml(comment.user_name)}</span>
                            <span class="pandat69-comment-date">${formatDateTime(comment.created_at)}</span>
                        </div>
                        <div class="pandat69-comment-text">${comment.comment_text}</div>
                    </li>
                `;
            });
            
            return commentsHtml;
        }

        function resetTaskForm() {
            $taskForm[0].reset();
            $taskForm.find('#pandat69-task-id').val('');
            $taskForm.find('#pandat69-task-description').val(''); // Explicitly clear textarea before TinyMCE init/reset
            $taskForm.find('.pandat69-expandable-header h3').text('Add New Task');
            $taskForm.find('.pandat69-submit-task-btn').text('Save Task');
            $taskForm.find('#pandat69-task-category').val('');
            $taskForm.find('#pandat69-task-priority').val('5'); // Reset to default
            $taskForm.find('.pandat69-form-message').hide();
            
            // Clear user selection
            $taskForm.find('.pandat69-selected-users-container').empty();
            $taskForm.find('#pandat69-task-assigned').val('');
            $taskForm.find('#pandat69-task-assigned-search').val('');
            
            // Hide inline category form
            $taskForm.find('.pandat69-inline-category-form').hide();
            $taskForm.find('.pandat69-new-category-name-inline').val('');
            $taskForm.find('.pandat69-inline-form-message').hide();
            
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
                    action: 'pandat69_get_task_details', // Reuse details fetch
                    nonce: nonce,
                    task_id: taskId
                },
                success: function (response) {
                    hideLoading();
                    if (response.success) {
                        const task = response.data.task;
                        // Populate form
                        $taskForm.find('#pandat69-task-id').val(task.id);
                        $taskForm.find('#pandat69-task-name').val(task.name);
                        // Set textarea value *before* initializing TinyMCE for edit
                        $taskForm.find('#pandat69-task-description').val(task.description || '');
                        $taskForm.find('#pandat69-task-status').val(task.status);
                        $taskForm.find('#pandat69-task-priority').val(task.priority);
                        $taskForm.find('#pandat69-task-category').val(task.category_id || '');
                        $taskForm.find('#pandat69-task-deadline').val(task.deadline || '');
                        
                        // Set up assigned users with the new autocomplete UI
                        const $selectedUsersContainer = $taskForm.find('.pandat69-selected-users-container');
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
                            $taskForm.find('#pandat69-task-assigned').val(userIds.join(','));
                        }

                        // Update form title and submit button
                        $taskForm.find('.pandat69-expandable-header h3').text('Edit Task');
                        $taskForm.find('.pandat69-submit-task-btn').text('Update Task');

                        // Show the form section
                        showExpandableSection($addTaskSection);
                        
                        // Initialize TinyMCE and datepicker
                        let editorId = $taskForm.find('.pandat69-tinymce-editor').attr('id');
                        if (!editorId) {
                            editorId = 'pandat69-task-description-' + boardName + '-' + Date.now();
                            $taskForm.find('.pandat69-tinymce-editor').attr('id', editorId);
                        }
                        initTinyMCE(editorId);
                        initDatepicker($taskForm.find('.pandat69-datepicker'));
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
            const $button = $taskForm.find('.pandat69-submit-task-btn');
            const taskId = $taskForm.find('#pandat69-task-id').val();
            const action = taskId ? 'pandat69_update_task' : 'pandat69_add_task';
            const originalButtonText = $button.text();
        
            // Ensure TinyMCE saves its content to the textarea
            const editorId = $taskForm.find('.pandat69-tinymce-editor').attr('id');
            if (editorId && typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                tinymce.get(editorId).save(); // Trigger save to update underlying textarea
            }
        
            // ADDED FIX: Explicitly update the hidden input with current user selections
            const userIds = getSelectedUserIds();
            $taskForm.find('#pandat69-task-assigned').val(userIds.join(','));
        
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
                    action: 'pandat69_delete_task',
                    nonce: nonce,
                    task_id: taskId
                },
                success: function (response) {
                    if (response.success) {
                        // Find the item in the list and fade it out before removing
                        $taskList.find(`.pandat69-task-item[data-task-id="${taskId}"]`).fadeOut(300, function() {
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
            const $categoryListContainer = $categoriesSection.find('.pandat69-category-list-container');
            const $categoryList = $categoriesSection.find('.pandat69-category-list');
            $categoryList.html('<li>Loading categories...</li>'); // Provide feedback inside the list UL
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_fetch_categories',
                    nonce: nonce,
                    board_name: boardName
                },
                success: function (response) {
                    if (response.success) {
                        renderCategoryManagementList(response.data.categories);
                        // Also refresh category dropdown in add/edit form silently
                        loadCategoriesForSelect($boardContainer.find('#pandat69-task-category'), true); // Pass silent=true maybe?
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
            const $categoryList = $categoriesSection.find('.pandat69-category-list');
            $categoryList.empty();
            if (categories && categories.length > 0) {
                categories.forEach(cat => {
                    // Use the corrected escapeHtml function
                    const item = `
                        <li class="pandat69-category-item" data-category-id="${cat.id}">
                            <span class="pandat69-category-name">${escapeHtml(cat.name)}</span>
                            <button class="pandat69-icon-button pandat69-button-danger pandat69-delete-category-btn" data-category-id="${cat.id}" title="Delete Category">✖</button>
                        </li>
                    `;
                    $categoryList.append(item);
                });
            } else {
                $categoryList.html('<li>No categories defined yet.</li>');
            }
        }

        function addCategory() {
            const $button = $categoryForm.find('.pandat69-add-category-btn');
            const $input = $categoryForm.find('#pandat69-new-category-name');
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
                    action: 'pandat69_add_category',
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
            const $inlineForm = $taskForm.find('.pandat69-inline-category-form');
            const $input = $inlineForm.find('.pandat69-new-category-name-inline');
            const $saveBtn = $inlineForm.find('.pandat69-save-category-inline-btn');
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
                    action: 'pandat69_add_category',
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
                        const $categorySelect = $taskForm.find('#pandat69-task-category');
                        
                        // Add new option if it doesn't exist
                        if ($categorySelect.find(`option[value="${newCategoryId}"]`).length === 0) {
                            $categorySelect.append(`<option value="${newCategoryId}">${escapeHtml(newCategoryName)}</option>`);
                        }
                        
                        // Explicitly set value and trigger change event to ensure selection
                        $categorySelect.val(newCategoryId).trigger('change');
                        
                        // Hide the form after a delay
                        setTimeout(() => {
                            $inlineForm.hide();
                            $inlineForm.find('.pandat69-inline-form-message').hide();
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
                    action: 'pandat69_delete_category',
                    nonce: nonce,
                    category_id: categoryId,
                    board_name: boardName // Include board name for verification
                },
                success: function (response) {
                    if (response.success) {
                        // Fade out the item in the management list
                        $categoriesSection.find(`.pandat69-category-item[data-category-id="${categoryId}"]`).fadeOut(300, function() {
                            $(this).remove();
                            if ($categoriesSection.find('.pandat69-category-list').children().length === 0) {
                                $categoriesSection.find('.pandat69-category-list').html('<li>No categories defined yet.</li>');
                            }
                        });
                        fetchTasks(); // Refresh task list as tasks might change category
                        // Refresh category dropdown in add/edit form
                        loadCategoriesForSelect($boardContainer.find('#pandat69-task-category'));
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
            const $button = $form.find('.pandat69-submit-comment-btn');
            const $textarea = $form.find('textarea[name="comment_text"]');
            const commentText = $textarea.val().trim();
            const taskId = $form.find('.pandat69-comment-task-id').val();

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
                    action: 'pandat69_add_comment',
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
                            <li class="pandat69-comment-item" style="display:none;">
                                <div class="pandat69-comment-meta">
                                    <span class="pandat69-comment-author">${escapeHtml(newComment.user_name)}</span>
                                    <span class="pandat69-comment-date">${formatDateTime(newComment.created_at)}</span>
                                </div>
                                <div class="pandat69-comment-text">${newComment.comment_text}</div>
                            </li>
                        `;
                        const $commentList = $form.closest('.pandat69-task-comments').find('.pandat69-comment-list');
                        $commentList.find('.pandat69-no-comments').remove(); // Remove 'no comments' message if it was there
                        
                        const $newComment = $(commentHtml);
                        $commentList.append($newComment);
                        $newComment.fadeIn(300); // Fade in the new comment
                        
                        $textarea.val(''); // Clear textarea
                        //showFormMessage($form, response.data.message, true); // Message can be distracting here
                        $form.find('.pandat69-form-message').hide(); // Clear any previous message
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
            const $searchInput = $boardContainer.find('#pandat69-task-assigned-search');
            const $suggestions = $boardContainer.find('.pandat69-user-suggestions');
            const $selectedContainer = $boardContainer.find('.pandat69-selected-users-container');
            const $hiddenInput = $boardContainer.find('#pandat69-task-assigned');
            
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
                    $suggestions.html(`<div class="pandat69-user-suggestion-item">${texts.searching}</div>`).show();
                    
                    // Perform AJAX search
                    searchUsers(searchTerm);
                }, 300);
            });
            
            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.pandat69-user-autocomplete-container').length) {
                    $suggestions.hide();
                }
            });
            
            // Click on a suggestion
            $boardContainer.on('click', '.pandat69-user-suggestion-item', function() {
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
            $boardContainer.on('click', '.pandat69-remove-user', function() {
                $(this).parent('.pandat69-selected-user').remove();
                updateSelectedUsersInput();
            });
        }
        
        function searchUsers(term) {
            const $suggestions = $boardContainer.find('.pandat69-user-suggestions');
            
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_fetch_users',
                    nonce: nonce,
                    search: term
                },
                success: function(response) {
                    if (response.success && response.data.users) {
                        renderUserSuggestions(response.data.users);
                    } else {
                        $suggestions.html(`<div class="pandat69-user-suggestion-item">${texts.no_results_found}</div>`);
                    }
                },
                error: function() {
                    $suggestions.html(`<div class="pandat69-user-suggestion-item">${texts.error_general}</div>`);
                }
            });
        }
        
        function renderUserSuggestions(users) {
            const $suggestions = $boardContainer.find('.pandat69-user-suggestions');
            $suggestions.empty();
            
            if (users.length === 0) {
                $suggestions.html(`<div class="pandat69-user-suggestion-item">${texts.no_results_found}</div>`);
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
                
                suggestionsHtml += `<div class="pandat69-user-suggestion-item" data-user-id="${user.id}">${escapeHtml(user.name)}</div>`;
                suggestionsCount++;
            });
            
            if (suggestionsCount === 0) {
                $suggestions.html(`<div class="pandat69-user-suggestion-item">${texts.no_results_found}</div>`);
            } else {
                $suggestions.html(suggestionsHtml);
            }
            
            $suggestions.show();
        }
        
        function addSelectedUserTag(userId, userName) {
            const $selectedContainer = $boardContainer.find('.pandat69-selected-users-container');
            const userTag = `
                <div class="pandat69-selected-user" data-user-id="${userId}">
                    ${escapeHtml(userName)}
                    <span class="pandat69-remove-user" title="Remove">×</span>
                </div>
            `;
            $selectedContainer.append(userTag);
        }
        
        function isUserSelected(userId) {
            return $boardContainer.find(`.pandat69-selected-user[data-user-id="${userId}"]`).length > 0;
        }
        
        function getSelectedUserIds() {
            const userIds = [];
            $boardContainer.find('.pandat69-selected-user').each(function() {
                userIds.push($(this).data('user-id').toString());
            });
            return userIds;
        }
        
        function updateSelectedUsersInput() {
            const userIds = getSelectedUserIds();
            $boardContainer.find('#pandat69-task-assigned').val(userIds.join(','));
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
                        inst.dpDiv.addClass('pandat69-datepicker-wrapper');
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
            let settings = pandat69_ajax_object.tinymce_settings || {};
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
                    action: 'pandat69_fetch_categories',
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
            $('body').on('keyup', '.pandat69-container .pandat69-comment-form textarea', function(e) {
                const $textarea = $(this);
                
                // Handle up/down/enter/escape keys for selection
                if (e.which === 38 || e.which === 40 || e.which === 13 || e.which === 27) {
                    const $dropdown = $('.pandat69-mention-dropdown');
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
                if (!$(e.target).closest('.pandat69-mention-dropdown').length && 
                    !$(e.target).closest('.pandat69-comment-form textarea').length) {
                    closeMentionDropdown();
                }
            });
            
            // Handle mention selection by click
            $('body').on('click', '.pandat69-mention-item', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const userId = $(this).data('user-id');
                
                // Get the plain text without any HTML formatting
                // Clone the element to safely manipulate it
                const $clone = $(this).clone();
                // Replace highlight spans with their text content
                $clone.find('.pandat69-mention-highlight').replaceWith(function() {
                    return $(this).text();
                });
                const userName = $clone.text().trim();
                
                console.log("Selected user:", userName, "ID:", userId);
                
                // Find the active textarea
                const $textarea = $('.pandat69-comment-form textarea[data-mention-active="true"]');
                
                if ($textarea.length) {
                    console.log("Found active textarea:", $textarea.attr('id'));
                    insertMention($textarea, userId, userName);
                } else {
                    console.error("No active textarea found for mention insertion");
                    // Try a fallback - find closest comment form
                    const $fallbackTextarea = $(this).closest('.pandat69-container').find('.pandat69-comment-form textarea');
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
                    action: 'pandat69_fetch_users',
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
            const $dropdown = $('<div class="pandat69-mention-dropdown"></div>');
            $dropdown.attr('data-for-textarea', $textarea.attr('id') || '');
            
            // Add user items
            users.slice(0, 10).forEach(user => { // Limit to 10 results
                const highlightedName = highlightMatch(escapeHtml(user.name), searchTerm);
                $dropdown.append(`<div class="pandat69-mention-item" data-user-id="${user.id}">${highlightedName}</div>`);
            });
            
            // Position and append dropdown
            $dropdown.css({
                top: (offset.top + pos.top + 20) + 'px',
                left: (offset.left + pos.left) + 'px'
            }).appendTo('body');
            
            // Highlight first item
            $dropdown.find('.pandat69-mention-item:first').addClass('active');
        }

        function closeMentionDropdown() {
            $('.pandat69-mention-dropdown').remove();
            // Don't clear active flag here, do it only when we insert
        }

        function handleMentionKeyNavigation(keyCode, $dropdown, $textarea) {
            const $items = $dropdown.find('.pandat69-mention-item');
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
                    $clone.find('.pandat69-mention-highlight').replaceWith(function() {
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
                    const $prev = $active.prev('.pandat69-mention-item');
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
                    const $next = $active.next('.pandat69-mention-item');
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
            return text.replace(regex, '<span class="pandat69-mention-highlight">$1</span>');
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
    }); // End .pandat69-container.each
}); // End jQuery ready