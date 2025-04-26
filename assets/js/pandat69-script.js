jQuery(document).ready(function($) {
    'use strict';
    
    // Global variables
    let currentWeekStart = new Date();
    let currentMonthDate = new Date();
    
    // Initialize the task board
    initTaskBoard();
    
    function initTaskBoard() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        if (!boardName) {
            console.error('Error: No board name specified');
            return;
        }
        
        // Load initial data
        loadTasks();
        loadCategories();
        
        // Set up event handlers
        setupEventHandlers();
        
        // Initialize tabs
        setupTabs();
        
        // Initialize datepicker
        $('.pandat69-datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            yearRange: 'c-5:c+5'
        });
        
        // Initialize current week and month for calendar views
        setupCalendarDates();
    }
    
    function setupEventHandlers() {
        // Add task button
        $('.pandat69-add-task-btn').on('click', function() {
            resetTaskForm();
            $('.pandat69-add-task-section').slideDown();
        });
        
        // Manage categories button
        $('.pandat69-manage-categories-btn').on('click', function() {
            $('.pandat69-categories-section').slideDown();
        });
        
        // Close expandable sections
        $('.pandat69-close-expandable').on('click', function() {
            $(this).closest('.pandat69-expandable-section').slideUp();
        });
        
        // Task form submit
        $('.pandat69-task-form').on('submit', function(e) {
            e.preventDefault();
            submitTaskForm();
        });
        
        // Cancel task form
        $('.pandat69-cancel-task-btn').on('click', function() {
            $('.pandat69-add-task-section').slideUp();
        });
        
        // Add category form
        $('.pandat69-add-category-form').on('submit', function(e) {
            e.preventDefault();
            addCategory();
        });
        
        // Add category inline button
        $('.pandat69-add-category-inline-btn').on('click', function() {
            $('.pandat69-inline-category-form').slideDown();
        });
        
        // Save inline category
        $('.pandat69-save-category-inline-btn').on('click', function() {
            addCategoryInline();
        });
        
        // Cancel inline category
        $('.pandat69-cancel-category-inline-btn').on('click', function() {
            $('.pandat69-inline-category-form').slideUp();
        });
        
        // Search input
        $('.pandat69-search-input').on('input', function() {
            loadTasks();
        });
        
        // Sort select
        $('.pandat69-sort-select').on('change', function() {
            loadTasks();
        });
        
        // Status filter select
        $('.pandat69-status-filter-select').on('change', function() {
            loadTasks();
        });
        
        // User search input
        setupUserAutocomplete();
        
        // Week navigation buttons
        $('.pandat69-prev-week').on('click', function() {
            navigateWeek(-1);
        });
        
        $('.pandat69-next-week').on('click', function() {
            navigateWeek(1);
        });
        
        // Month navigation buttons
        $('.pandat69-prev-month').on('click', function() {
            navigateMonth(-1);
        });
        
        $('.pandat69-next-month').on('click', function() {
            navigateMonth(1);
        });
    }
    
    function setupTabs() {
        $('.pandat69-tab-item').on('click', function() {
            const tabId = $(this).data('tab');
            
            // Update active state
            $('.pandat69-tab-item').removeClass('active');
            $(this).addClass('active');
            
            // Show selected tab content
            $('.pandat69-tab-content').removeClass('active');
            $(`.pandat69-tab-${tabId}`).addClass('active');
            
            // Load data if needed
            if (tabId === 'week') {
                loadWeekTasks();
            } else if (tabId === 'month') {
                loadMonthTasks();
            }
        });
    }
    
    function setupCalendarDates() {
        // Set current week to start on Monday
        currentWeekStart = getMonday(new Date());
        updateWeekDisplay();
        
        // Set current month
        updateMonthDisplay();
    }
    
    function getMonday(date) {
        const d = new Date(date);
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Adjust for Sunday
        return new Date(d.setDate(diff));
    }
    
    function updateWeekDisplay() {
        const weekEnd = new Date(currentWeekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);
        
        const options = { month: 'short', day: 'numeric', year: 'numeric' };
        const startStr = currentWeekStart.toLocaleDateString(undefined, options);
        const endStr = weekEnd.toLocaleDateString(undefined, options);
        
        $('.pandat69-current-week-display').text(`${startStr} - ${endStr}`);
    }
    
    function updateMonthDisplay() {
        const options = { month: 'long', year: 'numeric' };
        $('.pandat69-current-month-display').text(currentMonthDate.toLocaleDateString(undefined, options));
    }
    
    function navigateWeek(direction) {
        currentWeekStart.setDate(currentWeekStart.getDate() + (7 * direction));
        updateWeekDisplay();
        loadWeekTasks();
    }
    
    function navigateMonth(direction) {
        currentMonthDate.setMonth(currentMonthDate.getMonth() + direction);
        updateMonthDisplay();
        loadMonthTasks();
    }
    
    // Task Loading and Rendering
    
    function loadTasks() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        const searchTerm = $('.pandat69-search-input').val();
        const sortValue = $('.pandat69-sort-select').val();
        const statusFilter = $('.pandat69-status-filter-select').val();
        
        $('.pandat69-loading').show();
        $('.pandat69-task-list').first().hide();
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                search: searchTerm,
                sort: sortValue,
                status_filter: statusFilter
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    renderTasks(response.data.tasks);
                } else {
                    console.error('Error loading tasks:', response.data?.message || 'Unknown error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            },
            complete: function() {
                $('.pandat69-loading').hide();
                $('.pandat69-task-list').first().show();
            }
        });
    }
    
    function loadWeekTasks() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        // Format dates for API
        const weekStart = formatDate(currentWeekStart);
        const weekEnd = formatDate(new Date(currentWeekStart.getTime() + (6 * 24 * 60 * 60 * 1000)));
        
        $('.pandat69-week-task-container').html('<div class="pandat69-loading">Loading...</div>');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                date_filter: 'range',
                start_date: weekStart,
                end_date: weekEnd
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    renderWeekView(response.data.tasks, weekStart, weekEnd);
                } else {
                    console.error('Error loading week tasks:', response.data?.message || 'Unknown error');
                    $('.pandat69-week-task-container').html('<p>Error loading tasks.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $('.pandat69-week-task-container').html('<p>Error loading tasks.</p>');
            }
        });
    }
    
    function loadMonthTasks() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        // Get first and last day of month
        const year = currentMonthDate.getFullYear();
        const month = currentMonthDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        
        const monthStart = formatDate(firstDay);
        const monthEnd = formatDate(lastDay);
        
        $('.pandat69-month-task-container').html('<div class="pandat69-loading">Loading...</div>');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                date_filter: 'range',
                start_date: monthStart,
                end_date: monthEnd
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    renderMonthView(response.data.tasks, year, month);
                } else {
                    console.error('Error loading month tasks:', response.data?.message || 'Unknown error');
                    $('.pandat69-month-task-container').html('<p>Error loading tasks.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $('.pandat69-month-task-container').html('<p>Error loading tasks.</p>');
            }
        });
    }
    
    // Create a reusable function to render task items consistently
    function renderTaskItem(task) {
        return `
        <li class="pandat69-task-item" data-task-id="${task.id}">
            <div class="pandat69-task-item-details">
                <div class="pandat69-task-item-name">${task.name}</div>
                <div class="pandat69-task-item-meta">
                    <span><span class="pandat69-task-status pandat69-status-${task.status}">${task.status.replace('-', ' ')}</span></span>
                    <span><strong>Priority:</strong> ${task.priority}</span>
                    ${task.deadline ? `<span><strong>Deadline:</strong> ${task.deadline}</span>` : ''}
                    <span><strong>Category:</strong> ${task.category_name}</span>
                    <span><strong>Assigned to:</strong> ${task.assigned_user_names}</span>
                </div>
            </div>
            <div class="pandat69-task-item-actions">
                <button type="button" class="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task">✏️</button>
                <button type="button" class="pandat69-icon-button pandat69-delete-task-btn" title="Delete Task">🗑️</button>
            </div>
        </li>`;
    }
    
    function renderTasks(tasks) {
        const $taskList = $('.pandat69-task-list').first(); // Only target the main task list
        $taskList.empty();
        
        if (tasks.length === 0) {
            $taskList.html('<li class="pandat69-no-tasks">No tasks found.</li>');
            return;
        }
        
        tasks.forEach(task => {
            $taskList.append(renderTaskItem(task));
        });
        
        // Reattach event handlers
        attachTaskEventHandlers();
    }
    
    function renderWeekView(tasks, startDate, endDate) {
        const container = $('.pandat69-week-task-container');
        container.empty();
        
        if (tasks.length === 0) {
            container.html('<p>No tasks scheduled for this week.</p>');
            return;
        }
        
        // Group tasks by date
        const tasksByDate = {};
        tasks.forEach(task => {
            if (!task.deadline) return;
            if (!tasksByDate[task.deadline]) {
                tasksByDate[task.deadline] = [];
            }
            tasksByDate[task.deadline].push(task);
        });
        
        // For each date in the week
        for (let d = new Date(startDate); d <= new Date(endDate); d.setDate(d.getDate() + 1)) {
            const dateStr = formatDate(d);
            const formattedDate = formatDisplayDate(d);
            
            const dateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">${formattedDate}</div>
                    <ul class="pandat69-task-list">
                        ${tasksByDate[dateStr] ? tasksByDate[dateStr].map(task => renderTaskItem(task)).join('') : '<li class="pandat69-empty-day">No tasks</li>'}
                    </ul>
                </div>
            `);
            
            container.append(dateGroup);
        }
        
        // Add tasks without deadlines at the end
        const noDeadlineTasks = tasks.filter(task => !task.deadline);
        if (noDeadlineTasks.length > 0) {
            const noDateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">No Deadline</div>
                    <ul class="pandat69-task-list">
                        ${noDeadlineTasks.map(task => renderTaskItem(task)).join('')}
                    </ul>
                </div>
            `);
            container.append(noDateGroup);
        }
        
        // Reattach event handlers to all task items
        attachTaskEventHandlers();
    }
    
    function renderMonthView(tasks, year, month) {
        const container = $('.pandat69-month-task-container');
        container.empty();
        
        if (tasks.length === 0) {
            container.html('<p>No tasks scheduled for this month.</p>');
            return;
        }
        
        // Group tasks by date
        const tasksByDate = {};
        tasks.forEach(task => {
            if (!task.deadline) return;
            if (!tasksByDate[task.deadline]) {
                tasksByDate[task.deadline] = [];
            }
            tasksByDate[task.deadline].push(task);
        });
        
        // Sort dates
        const sortedDates = Object.keys(tasksByDate).sort();
        
        // Render each date group
        sortedDates.forEach(dateStr => {
            const dateTasks = tasksByDate[dateStr];
            const d = new Date(dateStr);
            const formattedDate = formatDisplayDate(d);
            
            const dateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">${formattedDate}</div>
                    <ul class="pandat69-task-list">
                        ${dateTasks.map(task => renderTaskItem(task)).join('')}
                    </ul>
                </div>
            `);
            
            container.append(dateGroup);
        });
        
        // Add tasks without deadlines at the end
        const noDeadlineTasks = tasks.filter(task => !task.deadline);
        if (noDeadlineTasks.length > 0) {
            const noDateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">No Deadline</div>
                    <ul class="pandat69-task-list">
                        ${noDeadlineTasks.map(task => renderTaskItem(task)).join('')}
                    </ul>
                </div>
            `);
            container.append(noDateGroup);
        }
        
        // Reattach event handlers to all task items
        attachTaskEventHandlers();
    }
    
    // Helper functions for date formatting
    function formatDate(date) {
        const d = new Date(date);
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${d.getFullYear()}-${month}-${day}`;
    }
    
    function formatDisplayDate(date) {
        const d = new Date(date);
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        return d.toLocaleDateString(undefined, options);
    }
    
    function attachTaskEventHandlers() {
        // Edit task button
        $('.pandat69-edit-task-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).closest('.pandat69-task-item').data('task-id');
            editTask(taskId);
        });
        
        // Delete task button
        $('.pandat69-delete-task-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).closest('.pandat69-task-item').data('task-id');
            deleteTask(taskId);
        });
        
        // Task item click (show details)
        $('.pandat69-task-item').off('click').on('click', function() {
            const taskId = $(this).data('task-id');
            showTaskDetails(taskId);
        });
    }
    
    // Task CRUD Operations
    
    function editTask(taskId) {
        // Show form section
        $('.pandat69-add-task-section').slideDown();
        
        // Show loading state
        const $form = $('.pandat69-task-form');
        $form.addClass('loading');
        $form.find('input, select, textarea, button').prop('disabled', true);
        
        // Fetch task details
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_get_task_details',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId
            },
            success: function(response) {
                if (response.success && response.data.task) {
                    fillTaskForm(response.data.task);
                } else {
                    console.error('Error fetching task details:', response.data?.message || 'Unknown error');
                    showFormMessage($form, 'error', 'Failed to load task details.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                showFormMessage($form, 'error', 'Failed to load task details. Please try again.');
            },
            complete: function() {
                $form.removeClass('loading');
                $form.find('input, select, textarea, button').prop('disabled', false);
            }
        });
    }
    
    function showTaskDetails(taskId) {
        // If details are already shown, toggle it closed
        const existingDetails = $('.pandat69-task-details-expandable[data-task-id="' + taskId + '"]');
        if (existingDetails.length > 0) {
            existingDetails.slideToggle(function() {
                if ($(this).is(':hidden')) {
                    $(this).remove();
                }
            });
            return;
        }
        
        // Close any other open details
        $('.pandat69-task-details-expandable').slideUp(function() {
            $(this).remove();
        });
        
        // Create details container
        const detailsContainer = $('<div class="pandat69-task-details-expandable" data-task-id="' + taskId + '"></div>');
        detailsContainer.html('<div class="pandat69-loading">Loading task details...</div>');
        
        // Find the task item and append details after it
        const taskItem = $('.pandat69-task-item[data-task-id="' + taskId + '"]');
        taskItem.after(detailsContainer);
        
        // Show details container with animation
        detailsContainer.slideDown();
        
        // Fetch task details
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_get_task_details',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId
            },
            success: function(response) {
                if (response.success && response.data.task) {
                    renderTaskDetails(response.data.task, detailsContainer);
                } else {
                    console.error('Error fetching task details:', response.data?.message || 'Unknown error');
                    detailsContainer.html('<div class="pandat69-error">Failed to load task details.</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                detailsContainer.html('<div class="pandat69-error">Failed to load task details. Please try again.</div>');
            }
        });
    }
    
    function renderTaskDetails(task, container) {
        // Create HTML for task details
        let html = `
            <div class="pandat69-task-details-content">
                <h3>${task.name}</h3>
                <div class="pandat69-detail-item">
                    <span class="pandat69-detail-label">Status:</span>
                    <span class="pandat69-detail-value pandat69-task-status pandat69-status-${task.status}">${task.status.replace('-', ' ')}</span>
                </div>
                <div class="pandat69-detail-item">
                    <span class="pandat69-detail-label">Priority:</span>
                    <span class="pandat69-detail-value">${task.priority}</span>
                </div>
                <div class="pandat69-detail-item">
                    <span class="pandat69-detail-label">Category:</span>
                    <span class="pandat69-detail-value">${task.category_name}</span>
                </div>
                <div class="pandat69-detail-item">
                    <span class="pandat69-detail-label">Deadline:</span>
                    <span class="pandat69-detail-value">${task.deadline || 'No deadline'}</span>
                </div>
                <div class="pandat69-detail-item">
                    <span class="pandat69-detail-label">Assigned to:</span>
                    <span class="pandat69-detail-value">${task.assigned_user_names}</span>
                </div>`;
                
        if (task.description) {
            html += `
                <div class="pandat69-task-details-description">
                    ${task.description}
                </div>`;
        }
        
        html += `
                <div class="pandat69-task-details-actions">
                    <button type="button" class="pandat69-button pandat69-edit-task-detail-btn" data-task-id="${task.id}">Edit Task</button>
                    <button type="button" class="pandat69-button pandat69-button-danger pandat69-delete-task-detail-btn" data-task-id="${task.id}">Delete Task</button>
                    <button type="button" class="pandat69-button pandat69-change-status-btn" data-task-id="${task.id}" data-current-status="${task.status}">Change Status</button>
                </div>
            </div>`;
            
        // Comments section
        html += `
            <div class="pandat69-task-comments">
                <h4>Comments</h4>
                <ul class="pandat69-comment-list">`;
                
        if (task.comments && task.comments.length > 0) {
            task.comments.forEach(function(comment) {
                html += `
                    <li class="pandat69-comment-item">
                        <div class="pandat69-comment-meta">
                            <span class="pandat69-comment-author">${comment.user_name}</span>
                            <span class="pandat69-comment-date">${comment.created_at}</span>
                        </div>
                        <div class="pandat69-comment-text">${comment.comment_text}</div>
                    </li>`;
            });
        } else {
            html += '<li class="pandat69-no-comments">No comments yet.</li>';
        }
                
        html += `
                </ul>
                <div class="pandat69-add-comment-form">
                    <textarea class="pandat69-textarea pandat69-comment-textarea" placeholder="Add a comment..."></textarea>
                    <div class="pandat69-form-actions">
                        <button type="button" class="pandat69-button pandat69-add-comment-btn" data-task-id="${task.id}">Add Comment</button>
                        <div class="pandat69-form-message pandat69-comment-message" style="display: none;"></div>
                    </div>
                </div>
            </div>`;
            
        // Update container and attach event handlers
        container.html(html);
        
        // Add event handlers
        container.find('.pandat69-edit-task-detail-btn').on('click', function() {
            editTask(task.id);
        });
        
        container.find('.pandat69-delete-task-detail-btn').on('click', function() {
            deleteTask(task.id);
        });
        
        container.find('.pandat69-add-comment-btn').on('click', function() {
            addComment(task.id, container);
        });
        
        container.find('.pandat69-change-status-btn').on('click', function() {
            showStatusDropdown($(this), task.id, task.status);
        });
    }
    
    function deleteTask(taskId) {
        if (!confirm(pandat69_ajax_object.text.confirm_delete_task)) {
            return;
        }
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_delete_task',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId
            },
            success: function(response) {
                if (response.success) {
                    // Remove task from DOM and any open details
                    $('.pandat69-task-item[data-task-id="' + taskId + '"]').fadeOut(function() {
                        $(this).remove();
                    });
                    $('.pandat69-task-details-expandable[data-task-id="' + taskId + '"]').fadeOut(function() {
                        $(this).remove();
                    });
                    
                    // Reload tasks to ensure the list is updated
                    loadTasks();
                    
                    // If we're in week or month view, refresh those too
                    if ($('.pandat69-tab-week').hasClass('active')) {
                        loadWeekTasks();
                    } else if ($('.pandat69-tab-month').hasClass('active')) {
                        loadMonthTasks();
                    }
                } else {
                    console.error('Error deleting task:', response.data?.message || 'Unknown error');
                    alert('Failed to delete task. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to delete task. Please try again.');
            }
        });
    }
    
    function addComment(taskId, container) {
        const commentText = container.find('.pandat69-comment-textarea').val().trim();
        
        if (!commentText) {
            showCommentMessage(container, 'error', 'Comment cannot be empty.');
            return;
        }
        
        // Disable form while submitting
        const $commentBtn = container.find('.pandat69-add-comment-btn');
        const $textarea = container.find('.pandat69-comment-textarea');
        $commentBtn.prop('disabled', true).text('Adding...');
        $textarea.prop('disabled', true);
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_add_comment',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId,
                comment_text: commentText
            },
            success: function(response) {
                if (response.success && response.data.comment) {
                    const comment = response.data.comment;
                    
                    // Remove "no comments" message if present
                    container.find('.pandat69-no-comments').remove();
                    
                    // Add new comment to list
                    const commentHtml = `
                        <li class="pandat69-comment-item">
                            <div class="pandat69-comment-meta">
                                <span class="pandat69-comment-author">${comment.user_name}</span>
                                <span class="pandat69-comment-date">${comment.created_at}</span>
                            </div>
                            <div class="pandat69-comment-text">${comment.comment_text}</div>
                        </li>`;
                    
                    container.find('.pandat69-comment-list').append(commentHtml);
                    
                    // Reset textarea
                    $textarea.val('');
                    
                    // Show success message
                    showCommentMessage(container, 'success', 'Comment added successfully.');
                } else {
                    console.error('Error adding comment:', response.data?.message || 'Unknown error');
                    showCommentMessage(container, 'error', 'Failed to add comment. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                showCommentMessage(container, 'error', 'Failed to add comment. Please try again.');
            },
            complete: function() {
                $commentBtn.prop('disabled', false).text('Add Comment');
                $textarea.prop('disabled', false);
            }
        });
    }
    
    function showStatusDropdown(button, taskId, currentStatus) {
        // Remove any existing dropdown
        $('.pandat69-status-dropdown').remove();
        
        // Create dropdown
        const dropdown = $(`
            <div class="pandat69-status-dropdown">
                <div class="pandat69-status-option pandat69-status-pending ${currentStatus === 'pending' ? 'pandat69-current-status' : ''}" data-status="pending">Pending</div>
                <div class="pandat69-status-option pandat69-status-in-progress ${currentStatus === 'in-progress' ? 'pandat69-current-status' : ''}" data-status="in-progress">In Progress</div>
                <div class="pandat69-status-option pandat69-status-done ${currentStatus === 'done' ? 'pandat69-current-status' : ''}" data-status="done">Done</div>
            </div>
        `);
        
        // Position dropdown below button
        button.after(dropdown);
        
        // Add click event to status options
        $('.pandat69-status-option').on('click', function() {
            const newStatus = $(this).data('status');
            updateTaskStatus(taskId, newStatus, button);
            dropdown.remove();
        });
        
        // Close dropdown when clicking outside
        $(document).one('click', function(e) {
            if (!$(e.target).closest('.pandat69-status-dropdown, .pandat69-change-status-btn').length) {
                dropdown.remove();
            }
        });
    }
    
    function updateTaskStatus(taskId, newStatus, button) {
        button.prop('disabled', true);
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_quick_update_status',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId,
                status: newStatus
            },
            success: function(response) {
                if (response.success) {
                    // Update task item status in the list
                    const taskItem = $('.pandat69-task-item[data-task-id="' + taskId + '"]');
                    taskItem.find('.pandat69-task-status')
                        .removeClass()
                        .addClass('pandat69-task-status pandat69-status-' + newStatus)
                        .text(newStatus.replace('-', ' '));
                    
                    // Update status in the details view
                    const detailsContainer = $('.pandat69-task-details-expandable[data-task-id="' + taskId + '"]');
                    detailsContainer.find('.pandat69-detail-value.pandat69-task-status')
                        .removeClass()
                        .addClass('pandat69-detail-value pandat69-task-status pandat69-status-' + newStatus)
                        .text(newStatus.replace('-', ' '));
                    
                    // Update button data attribute
                    button.data('current-status', newStatus);
                    
                    // Show a temporary status updated message
                    const statusMsg = $('<span class="pandat69-status-updated">✓ Updated</span>');
                    button.after(statusMsg);
                    setTimeout(function() {
                        statusMsg.fadeOut(function() {
                            $(this).remove();
                        });
                    }, 3000);
                } else {
                    console.error('Error updating status:', response.data?.message || 'Unknown error');
                    alert('Failed to update status. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to update status. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    }
    
    function resetTaskForm() {
        const $form = $('.pandat69-task-form');
        $form.find('input[type="text"], textarea').val('');
        $form.find('#pandat69-task-id').val(''); // Clear task ID (important for add vs edit)
        $form.find('#pandat69-task-status').val('pending');
        $form.find('#pandat69-task-priority').val('5');
        $form.find('#pandat69-task-category').val('');
        $form.find('#pandat69-task-assigned').val('');
        $form.find('.pandat69-selected-users-container').empty();
        
        // Reset TinyMCE if active
        if (typeof tinymce !== 'undefined' && tinymce.get('pandat69-task-description')) {
            tinymce.get('pandat69-task-description').setContent('');
        }
        
        // Ensure form message is hidden
        $form.find('.pandat69-form-message').hide().removeClass('pandat69-success pandat69-error');
        
        // Update form title
        $('.pandat69-add-task-section .pandat69-expandable-header h3').text('Add New Task');
    }
    
    function fillTaskForm(task) {
        const $form = $('.pandat69-task-form');
        
        // Set basic fields
        $form.find('#pandat69-task-id').val(task.id);
        $form.find('#pandat69-task-name').val(task.name);
        $form.find('#pandat69-task-status').val(task.status);
        $form.find('#pandat69-task-priority').val(task.priority);
        $form.find('#pandat69-task-category').val(task.category_id || '');
        $form.find('#pandat69-task-deadline').val(task.deadline || '');
        
        // Set description in TinyMCE or textarea
        if (typeof tinymce !== 'undefined' && tinymce.get('pandat69-task-description')) {
            tinymce.get('pandat69-task-description').setContent(task.description || '');
        } else {
            $form.find('#pandat69-task-description').val(task.description || '');
        }
        
        // Set assigned users
        const userIds = task.assigned_user_ids || [];
        $form.find('#pandat69-task-assigned').val(userIds.join(','));
        
        // Clear and rebuild selected users UI
        const $selectedUsers = $form.find('.pandat69-selected-users-container');
        $selectedUsers.empty();
        
        if (task.assigned_user_ids && task.assigned_user_ids.length > 0) {
            const userNames = task.assigned_user_names.split(', ');
            for (let i = 0; i < task.assigned_user_ids.length; i++) {
                if (i < userNames.length) {
                    addSelectedUserUI($selectedUsers, task.assigned_user_ids[i], userNames[i]);
                }
            }
        }
        
        // Update form title
        $('.pandat69-add-task-section .pandat69-expandable-header h3').text('Edit Task');
    }
    
    function submitTaskForm() {
        const $form = $('.pandat69-task-form');
        const taskId = $form.find('#pandat69-task-id').val();
        const isEdit = !!taskId;
        
        // Get form data
        const name = $form.find('#pandat69-task-name').val().trim();
        let description = '';
        
        // Get description from TinyMCE if active
        if (typeof tinymce !== 'undefined' && tinymce.get('pandat69-task-description')) {
            description = tinymce.get('pandat69-task-description').getContent();
        } else {
            description = $form.find('#pandat69-task-description').val();
        }
        
        const formData = {
            action: isEdit ? 'pandat69_update_task' : 'pandat69_add_task',
            nonce: pandat69_ajax_object.nonce,
            board_name: $form.find('#pandat69-board-name').val(),
            name: name,
            description: description,
            status: $form.find('#pandat69-task-status').val(),
            category_id: $form.find('#pandat69-task-category').val(),
            priority: $form.find('#pandat69-task-priority').val(),
            deadline: $form.find('#pandat69-task-deadline').val(),
            assigned_persons: $form.find('#pandat69-task-assigned').val()
        };
        
        // Add task ID if editing
        if (isEdit) {
            formData.task_id = taskId;
        }
        
        // Basic validation
        if (!formData.name) {
            showFormMessage($form, 'error', 'Task name is required.');
            return;
        }
        
        // Disable form while submitting
        $form.find('input, select, textarea, button').prop('disabled', true);
        $form.find('.pandat69-submit-task-btn').text(isEdit ? 'Updating...' : 'Adding...');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showFormMessage($form, 'success', isEdit ? 'Task updated successfully.' : 'Task added successfully.');
                    
                    // Reload tasks
                    loadTasks();
                    
                    // If we're in week or month view, refresh those too
                    if ($('.pandat69-tab-week').hasClass('active')) {
                        loadWeekTasks();
                    } else if ($('.pandat69-tab-month').hasClass('active')) {
                        loadMonthTasks();
                    }
                    
                    // Reset form
                    setTimeout(function() {
                        resetTaskForm();
                        $('.pandat69-add-task-section').slideUp();
                    }, 1500);
                } else {
                    console.error('Error submitting task:', response.data?.message || 'Unknown error');
                    showFormMessage($form, 'error', 'Failed to ' + (isEdit ? 'update' : 'add') + ' task. ' + (response.data?.message || 'Please try again.'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                showFormMessage($form, 'error', 'Failed to ' + (isEdit ? 'update' : 'add') + ' task. Please try again.');
            },
            complete: function() {
                $form.find('input, select, textarea, button').prop('disabled', false);
                $form.find('.pandat69-submit-task-btn').text(isEdit ? 'Update Task' : 'Save Task');
            }
        });
    }
    
    function showFormMessage($form, type, message) {
        const $message = $form.find('.pandat69-form-message');
        $message.removeClass('pandat69-success pandat69-error').addClass('pandat69-' + type);
        $message.text(message).fadeIn();
        
        // Auto-hide success messages after a delay
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 3000);
        }
    }
    
    function showCommentMessage($container, type, message) {
        const $message = $container.find('.pandat69-comment-message');
        $message.removeClass('pandat69-success pandat69-error').addClass('pandat69-' + type);
        $message.text(message).fadeIn();
        
        // Auto-hide success messages after a delay
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 3000);
        }
    }
    
    // Category Management
    
    function loadCategories() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_categories',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName
            },
            success: function(response) {
                if (response.success && response.data.categories) {
                    updateCategoryLists(response.data.categories);
                } else {
                    console.error('Error loading categories:', response.data?.message || 'Unknown error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    }
    
    function updateCategoryLists(categories) {
        // Clear current options first, preserving the default "Select Category" option
        const $categorySelect = $('#pandat69-task-category');
        $categorySelect.find('option:not(:first)').remove();
        
        // Add category options to select dropdown
        categories.forEach(function(category) {
            $categorySelect.append(`<option value="${category.id}">${category.name}</option>`);
        });
        
        // Update category list for management
        const $categoryList = $('.pandat69-category-list');
        $categoryList.empty();
        
        if (categories.length === 0) {
            $categoryList.html('<li class="pandat69-no-categories">No categories found.</li>');
            return;
        }
        
        categories.forEach(function(category) {
            $categoryList.append(`
                <li class="pandat69-category-item">
                    <span class="pandat69-category-name">${category.name}</span>
                    <button type="button" class="pandat69-icon-button pandat69-delete-category-btn" data-category-id="${category.id}" title="Delete Category">×</button>
                </li>
            `);
        });
        
        // Add event handler for delete buttons
        $('.pandat69-delete-category-btn').on('click', function() {
            const categoryId = $(this).data('category-id');
            deleteCategory(categoryId);
        });
    }
    
    function addCategory() {
        const $form = $('.pandat69-add-category-form');
        const $nameInput = $form.find('#pandat69-new-category-name');
        const categoryName = $nameInput.val().trim();
        const $message = $form.find('.pandat69-category-form-message');
        
        if (!categoryName) {
            $message.text('Category name is required.').addClass('pandat69-error').fadeIn();
            return;
        }
        
        // Disable form while submitting
        $form.find('input, button').prop('disabled', true);
        
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_add_category',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                name: categoryName
            },
            success: function(response) {
                if (response.success && response.data.category) {
                    $message.text('Category added successfully.').removeClass('pandat69-error').addClass('pandat69-success').fadeIn();
                    $nameInput.val('');
                    
                    // Reload categories
                    loadCategories();
                    
                    // Auto-hide message after delay
                    setTimeout(function() {
                        $message.fadeOut();
                    }, 3000);
                } else {
                    console.error('Error adding category:', response.data?.message || 'Unknown error');
                    $message.text(response.data?.message || 'Failed to add category. Please try again.').removeClass('pandat69-success').addClass('pandat69-error').fadeIn();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $message.text('Failed to add category. Please try again.').removeClass('pandat69-success').addClass('pandat69-error').fadeIn();
            },
            complete: function() {
                $form.find('input, button').prop('disabled', false);
            }
        });
    }
    
    function addCategoryInline() {
        const $container = $('.pandat69-inline-category-form');
        const $nameInput = $container.find('.pandat69-new-category-name-inline');
        const categoryName = $nameInput.val().trim();
        const $message = $container.find('.pandat69-inline-form-message');
        
        if (!categoryName) {
            $message.text('Category name is required.').addClass('pandat69-error').fadeIn();
            return;
        }
        
        // Disable form while submitting
        $container.find('input, button').prop('disabled', true);
        
        const boardName = $('.pandat69-container').data('board-name');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_add_category',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                name: categoryName
            },
            success: function(response) {
                if (response.success && response.data.category) {
                    $message.text('Category added successfully.').removeClass('pandat69-error').addClass('pandat69-success').fadeIn();
                    $nameInput.val('');
                    
                    // Add to select and select it
                    const newOption = $(`<option value="${response.data.category.id}">${response.data.category.name}</option>`);
                    $('#pandat69-task-category').append(newOption).val(response.data.category.id);
                    
                    // Reload all categories to ensure lists are in sync
                    loadCategories();
                    
                    // Hide the form after a delay
                    setTimeout(function() {
                        $container.slideUp();
                        $message.hide();
                    }, 1500);
                } else {
                    console.error('Error adding category:', response.data?.message || 'Unknown error');
                    $message.text(response.data?.message || 'Failed to add category. Please try again.').removeClass('pandat69-success').addClass('pandat69-error').fadeIn();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $message.text('Failed to add category. Please try again.').removeClass('pandat69-success').addClass('pandat69-error').fadeIn();
            },
            complete: function() {
                $container.find('input, button').prop('disabled', false);
            }
        });
    }
    
    function deleteCategory(categoryId) {
        if (!confirm(pandat69_ajax_object.text.confirm_delete_category)) {
            return;
        }
        
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_delete_category',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                category_id: categoryId
            },
            success: function(response) {
                if (response.success) {
                    // Reload categories
                    loadCategories();
                    
                    // Also reload tasks as they may have had this category
                    loadTasks();
                } else {
                    console.error('Error deleting category:', response.data?.message || 'Unknown error');
                    alert('Failed to delete category. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to delete category. Please try again.');
            }
        });
    }
    
    // User Autocomplete
    
    function setupUserAutocomplete() {
        const $container = $('.pandat69-user-autocomplete-container');
        const $input = $container.find('.pandat69-user-search-input');
        const $suggestions = $container.find('.pandat69-user-suggestions');
        const $selectedContainer = $container.find('.pandat69-selected-users-container');
        const $hiddenInput = $('#pandat69-task-assigned');
        
        let searchTimeout;
        
        $input.on('input', function() {
            const searchTerm = $(this).val().trim();
            
            // Clear previous timeout
            clearTimeout(searchTimeout);
            
            if (searchTerm.length < 2) {
                $suggestions.hide();
                return;
            }
            
            // Set a delay before searching
            searchTimeout = setTimeout(function() {
                $suggestions.html('<div class="pandat69-searching">' + pandat69_ajax_object.text.searching + '</div>').show();
                
                $.ajax({
                    url: pandat69_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pandat69_fetch_users',
                        nonce: pandat69_ajax_object.nonce,
                        search: searchTerm
                    },
                    success: function(response) {
                        if (response.success && response.data.users) {
                            renderUserSuggestions(response.data.users, $suggestions, $input, $selectedContainer, $hiddenInput);
                        } else {
                            $suggestions.html('<div class="pandat69-no-results">' + pandat69_ajax_object.text.no_results_found + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                        $suggestions.html('<div class="pandat69-error">' + pandat69_ajax_object.text.error_general + '</div>');
                    }
                });
            }, 300);
        });
        
        // Hide suggestions when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.pandat69-user-autocomplete-container').length) {
                $suggestions.hide();
            }
        });
        
        // Initialize remove user handlers
        $selectedContainer.on('click', '.pandat69-remove-user', function() {
            const userId = $(this).parent().data('user-id');
            removeSelectedUser(userId, $selectedContainer, $hiddenInput);
        });
    }
    
    function renderUserSuggestions(users, $suggestions, $input, $selectedContainer, $hiddenInput) {
        $suggestions.empty();
        
        if (users.length === 0) {
            $suggestions.html('<div class="pandat69-no-results">' + pandat69_ajax_object.text.no_results_found + '</div>');
            return;
        }
        
        // Get currently selected user IDs
        const selectedUserIds = $hiddenInput.val() ? $hiddenInput.val().split(',').map(Number) : [];
        
        // Filter out already selected users
        const filteredUsers = users.filter(user => !selectedUserIds.includes(parseInt(user.id)));
        
        if (filteredUsers.length === 0) {
            $suggestions.html('<div class="pandat69-no-results">All matching users already selected</div>');
            return;
        }
        
        filteredUsers.forEach(function(user) {
            const $item = $('<div class="pandat69-user-suggestion-item" data-user-id="' + user.id + '">' + user.name + '</div>');
            
            $item.on('click', function() {
                // Add to selected users
                addSelectedUser(user.id, user.name, $selectedContainer, $hiddenInput);
                
                // Clear input and hide suggestions
                $input.val('');
                $suggestions.hide();
            });
            
            $suggestions.append($item);
        });
    }
    
    function addSelectedUser(userId, userName, $container, $hiddenInput) {
        // Check if already selected
        if ($container.find('.pandat69-selected-user[data-user-id="' + userId + '"]').length > 0) {
            return;
        }
        
        // Create selected user element
        const $selectedUser = $('<div class="pandat69-selected-user" data-user-id="' + userId + '">' + userName + '<span class="pandat69-remove-user">×</span></div>');
        
        // Add to container
        $container.append($selectedUser);
        
        // Update hidden input
        let currentVal = $hiddenInput.val();
        let userIds = currentVal ? currentVal.split(',') : [];
        userIds.push(userId);
        $hiddenInput.val(userIds.join(','));
        
        // Add remove event handler
        $selectedUser.find('.pandat69-remove-user').on('click', function() {
            removeSelectedUser(userId, $container, $hiddenInput);
        });
    }
    
    function removeSelectedUser(userId, $container, $hiddenInput) {
        // Remove from DOM
        $container.find('.pandat69-selected-user[data-user-id="' + userId + '"]').remove();
        
        // Update hidden input
        let currentVal = $hiddenInput.val();
        let userIds = currentVal ? currentVal.split(',') : [];
        userIds = userIds.filter(id => parseInt(id) !== parseInt(userId));
        $hiddenInput.val(userIds.join(','));
    }
    
    function addSelectedUserUI($container, userId, userName) {
        // Create selected user element
        const $selectedUser = $('<div class="pandat69-selected-user" data-user-id="' + userId + '">' + userName + '<span class="pandat69-remove-user">×</span></div>');
        
        // Add to container
        $container.append($selectedUser);
        
        // Add remove event handler
        $selectedUser.find('.pandat69-remove-user').on('click', function() {
            const $hiddenInput = $('#pandat69-task-assigned');
            removeSelectedUser(userId, $container, $hiddenInput);
        });
    }
});