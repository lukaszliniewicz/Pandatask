jQuery(document).ready(function($) {
    'use strict';
    
    const modalState = {
        currentContent: null, // 'task' or 'category'
        isOpen: false
    };

    let taskColorMap = new Map(); // To store consistent colors for tasks
    let availableTaskColors = [
        '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FECA57',
        '#FF9FF3', '#54A0FF', '#5F27CD', '#00D2D3', '#FF9F43',
        '#C44569', '#40407A', '#706FD3', '#F8B500', '#EE5A6F'
    ];
    let colorIndex = 0;
    
    // Function to get a consistent color for a task
    function getTaskColor(taskId) {
        if (!taskColorMap.has(taskId)) {
            taskColorMap.set(taskId, availableTaskColors[colorIndex % availableTaskColors.length]);
            colorIndex++;
        }
        return taskColorMap.get(taskId);
    }

    function openModal(title, content) {
        // Get the modal elements
        const $modal = $('#pandat69-fullscreen-modal');
        const $modalBody = $modal.find('.pandat69-modal-body');
        
        // Ensure modal is in the body
        if ($modal.parent().prop('tagName') !== 'BODY') {
            $modal.detach().appendTo('body');
        }
        
        // IMPORTANT: First empty the modal body completely
        $modalBody.empty();
        
        // Return any existing modal content to the container
        returnModalContentToOriginal();
        
        // Get a FRESH reference to the desired content based on what was requested
        let contentToUse;
        
        if (content.hasClass('pandat69-add-task-section')) {
            // Looking for task form
            contentToUse = $('.pandat69-container > .pandat69-add-task-section').detach();
            modalState.currentContent = 'task';
        } else if (content.hasClass('pandat69-categories-section')) {
            // Looking for category management
            contentToUse = $('.pandat69-container > .pandat69-categories-section').detach();
            modalState.currentContent = 'category';
        }
        
        // Set the title
        if ($modal.find('.pandat69-modal-header').length === 0) {
            $modal.find('.pandat69-modal-content').prepend('<div class="pandat69-modal-header"><h3>' + title + '</h3></div>');
        } else {
            $modal.find('.pandat69-modal-header h3').text(title);
        }
        
        // Add the content to the modal body - ONLY if we found it
        if (contentToUse && contentToUse.length) {
            $modalBody.append(contentToUse);
            contentToUse.show(); // Make sure it's visible
        }
        
        // Add class to body to prevent scrolling
        $('body').addClass('pandat69-modal-open');
        
        // Show the modal
        $modal.fadeIn(300).addClass('active');
        modalState.isOpen = true;
        
        // Re-initialize form components
        reinitTinyMCE();
        fixJQueryUI();
        
        // Focus first visible input for accessibility
        setTimeout(function() {
            $modalBody.find('input:visible').first().focus();
        }, 300);
        
        // Set up focus trap for accessibility
        setupFocusTrap($modal);
    }
    
    function returnModalContentToOriginal() {
        const $container = $('.pandat69-container');
        const $modalBody = $('#pandat69-fullscreen-modal .pandat69-modal-body');
        
        // Process each content type separately to avoid problems
        
        // 1. Return task form to container if it's in the modal
        const $taskForm = $modalBody.find('.pandat69-add-task-section');
        if ($taskForm.length > 0) {
            $taskForm.detach().appendTo($container).hide();
        }
        
        // 2. Return category management to container if it's in the modal
        const $categoryForm = $modalBody.find('.pandat69-categories-section');
        if ($categoryForm.length > 0) {
            $categoryForm.detach().appendTo($container).hide();
        }
        
        // Clear content tracker
        modalState.currentContent = null;
    }
    /**
     * Close the modal and return content to original container
     */
    function closeModal() {
        const $modal = $('#pandat69-fullscreen-modal');
        
        // Hide the modal with animation, then handle cleanup when complete
        $modal.fadeOut(80, function() {
            // After fadeOut completes, return content to original container
            returnModalContentToOriginal();
            
            // Make sure active class is removed
            $modal.removeClass('active');
        });
        
        // Remove class from body to restore scrolling (can do immediately)
        $('body').removeClass('pandat69-modal-open');
        
        // Update modal state
        modalState.isOpen = false;
    }

    // Add this function to your code
    function reinitTinyMCE() {
        // If tinymce is defined, reinitialize editors that might be in the modal
        if (typeof tinymce !== 'undefined') {
            // First remove any existing editors
            if (tinymce.get('pandat69-task-description')) {
                tinymce.execCommand('mceRemoveEditor', false, 'pandat69-task-description');
            }
            
            // Then initialize a new editor
            setTimeout(function() {
                if ($('#pandat69-task-description').length) {
                    tinymce.execCommand('mceAddEditor', false, 'pandat69-task-description');
                }
            }, 100);
        }
    }


    /**
     * Fix jQuery UI components in the modal
     */
    function fixJQueryUI() {
        // Fix datepicker
        $('#pandat69-fullscreen-modal .pandat69-datepicker').each(function() {
            if (!$(this).hasClass('hasDatepicker')) {
                $(this).datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true,
                    yearRange: 'c-5:c+5'
                });
            }
        });
        
        // Ensure datepicker is above modal
        $('#ui-datepicker-div').css('z-index', 1000010);
        
        // Fix autocomplete
        if ($.ui && $.ui.autocomplete) {
            $.ui.autocomplete.prototype._resizeMenu = function() {
                this.menu.element.outerWidth(this.element.outerWidth());
            };
        }
    }

    /**
     * Set up focus trap for accessibility
     */
    function setupFocusTrap($modal) {
        const $focusableElements = $modal.find('a[href], button, input, textarea, select, [tabindex]:not([tabindex="-1"])');
        const $firstFocusable = $focusableElements.first();
        const $lastFocusable = $focusableElements.last();
        
        // Focus loop
        $lastFocusable.off('keydown.focustrap').on('keydown.focustrap', function(e) {
            if ((e.key === 'Tab' || e.keyCode === 9) && !e.shiftKey) {
                e.preventDefault();
                $firstFocusable.focus();
            }
        });
        
        $firstFocusable.off('keydown.focustrap').on('keydown.focustrap', function(e) {
            if ((e.key === 'Tab' || e.keyCode === 9) && e.shiftKey) {
                e.preventDefault();
                $lastFocusable.focus();
            }
        });
    }

    /**
     * Fix for iOS keyboard pushing content up
     */

    function isIOSDevice() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) || 
               (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
    function setupiOSKeyboardFix() {
        // Only apply iOS fixes on actual iOS devices
        if (!isIOSDevice()) {
            return;
        }
        
        $('#pandat69-fullscreen-modal').on('focus', 'input, textarea, select', function() {
            // Add a small delay to prevent interference with autocomplete clicks
            setTimeout(() => {
                $('.pandat69-modal-container').css('transform', 'translateY(-10%)');
            }, 100);
        }).on('blur', 'input, textarea, select', function() {
            // Check if the blur is happening because of an autocomplete interaction
            setTimeout(() => {
                // Only apply the transform if no autocomplete suggestion is being clicked
                if (!$('.pandat69-user-suggestions:visible').length || 
                    !$('.pandat69-user-suggestions:hover').length) {
                    $('.pandat69-modal-container').css('transform', 'translateY(0)');
                }
            }, 150);
        });
    }

    // Call this on page load
    function initModalSystem() {
        // Ensure modal is added directly to body
        if ($('#pandat69-fullscreen-modal').length) {
            const $modal = $('#pandat69-fullscreen-modal');
            if ($modal.parent().prop('tagName') !== 'BODY') {
                $modal.detach().appendTo('body');
            }
        }
        
        // Set up handlers for closing the modal
        $(document).off('click.modalClose').on('click.modalClose', '.pandat69-modal-close', function() {
            closeModal();
        });
        
        $(document).off('click.modalOverlay').on('click.modalOverlay', '.pandat69-modal-overlay', function(e) {
            if ($(e.target).is('.pandat69-modal-overlay')) {
                closeModal();
            }
        });
        
        $(document).off('keydown.modalEsc').on('keydown.modalEsc', function(e) {
            if (e.key === 'Escape' && modalState.isOpen) {
                closeModal();
            }
        });
        
        // Set up iOS keyboard fix
        setupiOSKeyboardFix();
    }

    function preventNiceSelectConflict() {
        // Add CSS to ensure our selects remain visible and override NiceSelect styles
        $('head').append(`
            <style>
                .pandat69-container select.pandat69-select {
                    display: block !important;
                    opacity: 1 !important;
                    visibility: visible !important;
                    position: static !important;
                    z-index: 100 !important;
                    width: 100% !important;
                    height: auto !important;
                    padding: 8px 10px !important;
                    margin: 0 !important;
                    border: 1px solid #ccc !important;
                    border-radius: 3px !important;
                    background-color: #fff !important;
                    font-size: 14px !important;
                    line-height: 1.5 !important;
                    color: #384D68 !important;
                    cursor: pointer !important;
                }
                .pandat69-container select.pandat69-select:focus {
                    border-color: #384D68 !important;
                    outline: none !important;
                    box-shadow: 0 0 0 1px #384D68 !important;
                }
                .pandat69-container .nice-select,
                .pandat69-container .nice-select.open {
                    display: none !important;
                    width: 0 !important;
                    height: 0 !important;
                    opacity: 0 !important;
                    overflow: hidden !important;
                    visibility: hidden !important;
                    pointer-events: none !important;
                }
            </style>
        `);
        
        // Destroy NiceSelect on our elements if it exists
        setTimeout(function() {
            if (typeof $.fn.niceSelect !== 'undefined') {
                try {
                    $('.pandat69-select').niceSelect('destroy');
                } catch(e) {
                    console.log('Could not destroy niceSelect', e);
                }
                
                // Remove any existing nice-select elements next to our selects
                $('.pandat69-select').next('.nice-select').remove();
            }
        }, 10);
        
        // Watch for NiceSelect being applied later
        const observer = new MutationObserver(function(mutations) {
            let needsCheck = false;
            
            // Quick check if any relevant elements were added
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes && mutation.addedNodes.length) {
                    for (let i = 0; i < mutation.addedNodes.length; i++) {
                        if (mutation.addedNodes[i].classList && 
                            (mutation.addedNodes[i].classList.contains('nice-select') || 
                             mutation.addedNodes[i].querySelector('.nice-select'))) {
                            needsCheck = true;
                            break;
                        }
                    }
                }
            });
            
            // Only do the expensive operation if needed
            if (needsCheck) {
                $('.pandat69-select').each(function() {
                    const $niceSelect = $(this).next('.nice-select');
                    if ($niceSelect.length) {
                        $niceSelect.remove();
                        if (typeof $.fn.niceSelect !== 'undefined') {
                            try {
                                $(this).niceSelect('destroy');
                            } catch(e) {}
                        }
                    }
                });
            }
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
        
        // Also handle our selects when they're created later
        $(document).on('DOMNodeInserted', '.pandat69-select', function() {
            const $select = $(this);
            setTimeout(function() {
                const $niceSelect = $select.next('.nice-select');
                if ($niceSelect.length) {
                    $niceSelect.remove();
                    if (typeof $.fn.niceSelect !== 'undefined') {
                        try {
                            $select.niceSelect('destroy');
                        } catch(e) {}
                    }
                }
            }, 50);
        });
    }    
    // Global variables
    let currentWeekStart = new Date();
    let currentMonthDate = new Date();
    let weekViewMode = 'per_day';
    let weekShowStartingTasks = true;
    let monthViewMode = 'per_day';
    let monthShowStartingTasks = true;
    let showOnlyMyTasks = false;

    // Initialize the task board
    initTaskBoard();

    function initTaskBoard() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
    
        if (!boardName) {
            console.error('Error: No board name specified');
            return;
        }
    
        // Call the function to prevent NiceSelect conflicts
        preventNiceSelectConflict();
        initModalSystem();
    
        // Initialize toggle state
        showOnlyMyTasks = false;
        $('#pandat69-my-tasks-toggle').prop('checked', false);
    
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
        setupMentionAutocomplete();
    }

    function fixSelects() {
        setTimeout(function() {
            // Find any NiceSelect elements that might have been created
            $('.pandat69-select').each(function() {
                const $niceSelect = $(this).next('.nice-select');
                if ($niceSelect.length) {
                    $niceSelect.remove();
                    if (typeof $.fn.niceSelect !== 'undefined') {
                        try {
                            $(this).niceSelect('destroy');
                        } catch(e) {}
                    }
                }
            });
        }, 50);
    }

    function setupEventHandlers() {
        // Add task button
        $('.pandat69-add-task-btn').on('click', function() {
            resetTaskForm();
            const $taskSection = $('.pandat69-add-task-section');
            openModal('Add New Task', $taskSection);
        });
    
        // Manage categories button
        $('.pandat69-manage-categories-btn').on('click', function() {
            const $categoriesSection = $('.pandat69-categories-section');
            openModal('Manage Categories', $categoriesSection);
        });
    
        // Close expandable sections (for non-modal use)
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
            closeModal();
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
        
        // "Only My Tasks" toggle - NEW
        $('#pandat69-my-tasks-toggle').on('change', function() {
            showOnlyMyTasks = $(this).prop('checked');
            loadTasks(); // Reload tasks with the filter applied
            
            // If we're in week or month view, also refresh those
            if ($('.pandat69-tab-week').hasClass('active')) {
                loadWeekTasks();
            } else if ($('.pandat69-tab-month').hasClass('active')) {
                loadMonthTasks();
            }
        });
    
        // Status change updates start date
        $('#pandat69-task-status').on('change', function() {
            updateStartDateBasedOnStatus();
        });
        
        // Toggle deadline type inputs
        $('input[name="deadline_type"]').on('change', function() {
            toggleDeadlineInputs();
        });
    
        // User search input
        setupUserAutocomplete();
        setupSupervisorAutocomplete();
    
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
    
        // Toggle deadline notification days field based on checkbox
        $(document).on('change', '#pandat69-notify-deadline', function() {
            if($(this).is(':checked')) {
                $('.pandat69-deadline-notification-days').show();
            } else {
                $('.pandat69-deadline-notification-days').hide();
            }
        });
        
        // Edit task buttons in task details view
        $(document).on('click', '.pandat69-edit-task-detail-btn', function() {
            const taskId = $(this).data('task-id');
            editTask(taskId);
        });
        
        // Task status change button in details view
        $(document).on('click', '.pandat69-change-status-btn', function() {
            const taskId = $(this).data('task-id');
            const currentStatus = $(this).data('current-status');
            showStatusDropdown($(this), taskId, currentStatus);
        });
        
        // Archive/unarchive from details view
        $(document).on('click', '.pandat69-archive-task-detail-btn', function() {
            const taskId = $(this).data('task-id');
            archiveTask(taskId);
        });
        
        $(document).on('click', '.pandat69-unarchive-task-detail-btn', function() {
            const taskId = $(this).data('task-id');
            unarchiveTask(taskId);
        });
    }

    // Handle status label clicks for quick status change
    $(document).on('click', '.pandat69-task-status', function(e) {
        e.stopPropagation(); // Prevent triggering the task item click
        
        var $statusLabel = $(this);
        var taskId = parseInt($statusLabel.closest('.pandat69-task-item').data('task-id'));
        var currentStatus = $statusLabel.attr('data-status');
        
        if (isNaN(taskId) || !taskId) {
            console.error('Invalid task ID:', taskId);
            return;
        }
        
        // Remove any existing dropdowns
        $('.pandat69-status-dropdown').remove();
        
        // Create status dropdown
        var $dropdown = $('<div class="pandat69-status-dropdown"></div>');
        
        // Add status options
        var statuses = [
            { value: 'pending', label: 'Pending', class: 'pandat69-status-pending' },
            { value: 'in-progress', label: 'In Progress', class: 'pandat69-status-in-progress' },
            { value: 'done', label: 'Done', class: 'pandat69-status-done' }
        ];
        
        statuses.forEach(function(status) {
            var $option = $('<div class="pandat69-status-option ' + status.class + '">' + status.label + '</div>');
            
            // Mark current status
            if (status.value === currentStatus) {
                $option.addClass('pandat69-current-status');
            }
            
            // Handle option click
            $option.on('click', function() {
                // Don't do anything if clicking the current status
                if (status.value === currentStatus) {
                    $dropdown.remove();
                    return;
                }
                
                // Update status via AJAX
                $.ajax({
                    url: pandat69_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pandat69_quick_update_status',
                        nonce: pandat69_ajax_object.nonce,
                        task_id: taskId,
                        status: status.value
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('List view status update response:', response.data);
                            
                            // Update the UI
                            $statusLabel.removeClass('pandat69-status-' + currentStatus)
                                    .addClass('pandat69-status-' + status.value)
                                    .attr('data-status', status.value)
                                    .text(response.data.status_text);
                                    
                            // Get the meta container for this task
                            var $metaContainer = $statusLabel.closest('.pandat69-task-item-meta');
                            
                            // Handle completion date changes
                            if (status.value === 'done' && response.data.completed_at) {
                                // Check if completion date element already exists
                                var $completedEl = $metaContainer.find('span:contains("Completed:")');
                                if ($completedEl.length) {
                                    // Update existing completion date
                                    $completedEl.html('<strong>Completed:</strong> ' + response.data.completed_at);
                                } else {
                                    // Add new completion date after deadline, start date, or priority
                                    var $deadlineEl = $metaContainer.find('span:contains("Deadline:")');
                                    var $startedEl = $metaContainer.find('span:contains("Started:")');
                                    var $priorityEl = $metaContainer.find('span:contains("Priority:")');
                                    
                                    var $targetEl = $deadlineEl.length ? $deadlineEl : 
                                                ($startedEl.length ? $startedEl : $priorityEl);
                                                
                                    $targetEl.after('<span><strong>Completed:</strong> ' + response.data.completed_at + '</span>');
                                }
                            } else if (status.value !== 'done') {
                                // Remove completion date element if status is not done
                                $metaContainer.find('span:contains("Completed:")').remove();
                            }
                            
                            // Add a "Updated" indicator that fades out
                            var $updated = $('<span class="pandat69-status-updated">✓</span>');
                            $statusLabel.append($updated);
                            
                            // Remove dropdown
                            $dropdown.remove();
                        } else {
                            alert(response.data.message || 'Failed to update status');
                            $dropdown.remove();
                        }
                    },
                    error: function() {
                        alert('Error connecting to the server');
                        $dropdown.remove();
                    }
                });
            });
            
            $dropdown.append($option);
        });
        
        // Position and append the dropdown
        $dropdown.css({
            'top': $statusLabel.position().top + $statusLabel.outerHeight(),
            'left': $statusLabel.position().left
        });
        
        $statusLabel.parent().append($dropdown);
        
        // Close dropdown when clicking outside
        $(document).one('click', function() {
            $dropdown.remove();
        });
    });

    function updateStartDateBasedOnStatus() {
        const status = $('#pandat69-task-status').val();
        const $startDate = $('#pandat69-task-start-date');
        
        if (status === 'in-progress' && !$startDate.val()) {
            // Set to current date if changing to in-progress
            const today = new Date();
            $startDate.datepicker('setDate', today);
        }
    }
    
    function toggleDeadlineInputs() {
        const deadlineType = $('input[name="deadline_type"]:checked').val();
        
        if (deadlineType === 'date') {
            $('.pandat69-deadline-date-input').show();
            $('.pandat69-deadline-days-input').hide();
        } else {
            $('.pandat69-deadline-date-input').hide();
            $('.pandat69-deadline-days-input').show();
        }
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
    
            // Sync toggle state before loading data
            $(`.pandat69-my-tasks-toggle`).prop('checked', showOnlyMyTasks);
    
            // Load data if needed
            if (tabId === 'week') {
                loadWeekTasks();
            } else if (tabId === 'month') {
                loadMonthTasks();
            } else if (tabId === 'archive') {
                loadArchivedTasks();
            } else if (tabId === 'all') {
                loadTasks();
            }
        });
    }

    // For both week and month views, add the toggle to the view options
    function setupCalendarDates() {
        // Set current week to start on Monday
        currentWeekStart = getMonday(new Date());
        updateWeekDisplay();

        // Set current month
        updateMonthDisplay();
        
        // Add view controls to week tab
        const weekViewControls = `
        <div class="pandat69-view-options">
            <div class="pandat69-view-mode">
                <label><input type="radio" name="view_mode_week" value="per_day" checked> Per Day</label>
                <label><input type="radio" name="view_mode_week" value="show_all"> Show All</label>
                <label><input type="radio" name="view_mode_week" value="calendar"> Calendar View</label>
            </div>
            <div class="pandat69-per-day-options">
                <label><input type="checkbox" id="show_starting_tasks_week"> Show Starting Tasks</label>
            </div>
            <div class="pandat69-toggle-container">
                <label class="pandat69-switch">
                    <input type="checkbox" id="pandat69-my-tasks-toggle-week" class="pandat69-my-tasks-toggle">
                    <span class="pandat69-slider pandat69-round"></span>
                </label>
                <span class="pandat69-toggle-label">Only My Tasks</span>
            </div>
        </div>`;
        
        // Add view controls to month tab
        const monthViewControls = `
        <div class="pandat69-view-options">
            <div class="pandat69-view-mode">
                <label><input type="radio" name="view_mode_month" value="per_day" checked> Per Day</label>
                <label><input type="radio" name="view_mode_month" value="show_all"> Show All</label>
                <label><input type="radio" name="view_mode_month" value="calendar"> Calendar View</label>
            </div>
            <div class="pandat69-per-day-options">
                <label><input type="checkbox" id="show_starting_tasks_month"> Show Starting Tasks</label>
            </div>
            <div class="pandat69-toggle-container">
                <label class="pandat69-switch">
                    <input type="checkbox" id="pandat69-my-tasks-toggle-month" class="pandat69-my-tasks-toggle">
                    <span class="pandat69-slider pandat69-round"></span>
                </label>
                <span class="pandat69-toggle-label">Only My Tasks</span>
            </div>
        </div>`;
        
        // Insert the view controls after date selectors
        $('.pandat69-tab-week .pandat69-date-selector').after(weekViewControls);
        $('.pandat69-tab-month .pandat69-date-selector').after(monthViewControls);
        
        // Set up event handlers for the view controls
        setupViewModeHandlers();
    }

    function setupViewModeHandlers() {
        // Week view controls
        $(document).on('change', 'input[name="view_mode_week"]', function() {
            weekViewMode = $(this).val();
            
            // Show/hide per-day options based on view mode
            if (weekViewMode === 'calendar') {
                $('.pandat69-tab-week .pandat69-per-day-options').hide();
            } else {
                $('.pandat69-tab-week .pandat69-per-day-options').show();
            }
            
            loadWeekTasks();
        });
        
        $(document).on('change', '#show_starting_tasks_week', function() {
            weekShowStartingTasks = $(this).prop('checked');
            if (weekViewMode !== 'calendar') {
                loadWeekTasks();
            }
        });
        
        // Month view controls
        $(document).on('change', 'input[name="view_mode_month"]', function() {
            monthViewMode = $(this).val();
            
            // Show/hide per-day options based on view mode
            if (monthViewMode === 'calendar') {
                $('.pandat69-tab-month .pandat69-per-day-options').hide();
            } else {
                $('.pandat69-tab-month .pandat69-per-day-options').show();
            }
            
            loadMonthTasks();
        });
        
        $(document).on('change', '#show_starting_tasks_month', function() {
            monthShowStartingTasks = $(this).prop('checked');
            if (monthViewMode !== 'calendar') {
                loadMonthTasks();
            }
        });
        
        // Handle "Only My Tasks" toggles - keep them all in sync
        $(document).on('change', '.pandat69-my-tasks-toggle', function() {
            // Update the global toggle state
            showOnlyMyTasks = $(this).prop('checked');
            
            // Sync all toggles to the same state
            $('.pandat69-my-tasks-toggle').prop('checked', showOnlyMyTasks);
            
            // Reload the currently active tab
            if ($('.pandat69-tab-all').hasClass('active')) {
                loadTasks();
            } else if ($('.pandat69-tab-week').hasClass('active')) {
                loadWeekTasks();
            } else if ($('.pandat69-tab-month').hasClass('active')) {
                loadMonthTasks();
            } else if ($('.pandat69-tab-archive').hasClass('active')) {
                loadArchivedTasks();
            }
        });
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
                status_filter: statusFilter,
                archived: 0 
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    let tasks = response.data.tasks;
                    
                    // Filter tasks if toggle is active
                    if (showOnlyMyTasks) {
                        const currentUserId = pandat69_ajax_object.current_user_id;
                        tasks = tasks.filter(function(task) {
                            // Check if current user is assigned to this task
                            return task.assigned_user_ids && 
                                   task.assigned_user_ids.includes(currentUserId.toString());
                        });
                    }
                    
                    renderTasks(tasks);
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
        
        // Always fetch all tasks for the board to allow flexible filtering
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                archived: 0 
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    // Filter tasks based on view mode
                    if (weekViewMode === 'per_day') {
                        renderWeekViewPerDay(response.data.tasks, weekStart, weekEnd);
                    } else if (weekViewMode === 'calendar') {
                        renderWeekCalendarView(response.data.tasks, weekStart, weekEnd);
                    } else { // show_all mode
                        renderWeekViewAll(response.data.tasks, weekStart, weekEnd);
                    }
                } else {
                    console.error('Error loading week tasks:', response.data?.message || 'Unknown error');
                    $('.pandat69-week-task-container').html('<p>Error loading tasks.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $('.pandat69-week-task-container').html('<p>Error loading tasks. Please try again.</p>');
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
    
        // Always fetch all tasks for the board to allow flexible filtering
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                archived: 0 
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    // Filter tasks based on view mode
                    if (monthViewMode === 'per_day') {
                        renderMonthViewPerDay(response.data.tasks, monthStart, monthEnd, year, month);
                    } else if (monthViewMode === 'calendar') {
                        renderMonthCalendarView(response.data.tasks, monthStart, monthEnd, year, month);
                    } else { // show_all mode
                        renderMonthViewAll(response.data.tasks, monthStart, monthEnd);
                    }
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

    function renderWeekCalendarView(tasks, startDate, endDate) {
        const container = $('.pandat69-week-task-container');
        container.empty();
    
        // Filter tasks to only include those with both start_date and deadline
        let calendarTasks = tasks.filter(task => {
            if (!task.start_date || !task.deadline) {
                return false;
            }
            
            const taskStart = new Date(task.start_date);
            const taskEnd = new Date(task.deadline);
            const periodStart = new Date(startDate);
            const periodEnd = new Date(endDate);
            
            return taskStart <= periodEnd && taskEnd >= periodStart;
        });
    
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            calendarTasks = calendarTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (calendarTasks.length === 0) {
            container.html('<div class="pandat69-calendar-no-tasks">No tasks with both start date and deadline found for this week.</div>');
            return;
        }
    
        // Generate the calendar structure with improved task bars
        const calendarHtml = renderImprovedCalendarGrid(calendarTasks, startDate, endDate, 'week');
        container.html(calendarHtml);
        
        // Attach event handlers
        attachCalendarTaskHandlers();
    }
    
    function renderMonthCalendarView(tasks, startDate, endDate, year, month) {
        const container = $('.pandat69-month-task-container');
        container.empty();
    
        // Filter tasks to only include those with both start_date and deadline
        let calendarTasks = tasks.filter(task => {
            if (!task.start_date || !task.deadline) {
                return false;
            }
            
            // Task should intersect with the month period (be more lenient for month view)
            const taskStart = new Date(task.start_date);
            const taskEnd = new Date(task.deadline);
            const monthStart = new Date(year, month, 1);
            const monthEnd = new Date(year, month + 1, 0);
            
            return taskStart <= monthEnd && taskEnd >= monthStart;
        });
    
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            calendarTasks = calendarTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (calendarTasks.length === 0) {
            container.html('<div class="pandat69-calendar-no-tasks">No tasks with both start date and deadline found for this month.</div>');
            return;
        }
    
        // Generate the month calendar structure
        const calendarHtml = renderMonthCalendarGrid(calendarTasks, year, month);
        container.html(calendarHtml);
        
        // Reattach event handlers
        attachTaskEventHandlers();
    }
    
    function attachCalendarTaskHandlers() {
        // Handle month task bar clicks
        $(document).off('click', '.pandat69-month-task-bar').on('click', '.pandat69-month-task-bar', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id');
            if (taskId) {
                openCalendarTaskModal(taskId);
            }
        });
        
        // Handle week task bar clicks
        $(document).off('click', '.pandat69-improved-task-bar').on('click', '.pandat69-improved-task-bar', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id');
            if (taskId) {
                openCalendarTaskModal(taskId);
            }
        });
    }    

    function displayCalendarTaskModal(task) {
        // Create or get modal
        let modal = document.getElementById('pandat69-calendar-task-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'pandat69-calendar-task-modal';
            modal.className = 'pandat69-calendar-task-modal';
            document.body.appendChild(modal);
        }
        
        const completedText = task.status === 'done' && task.completed_at ? 
            `<span><strong>Completed:</strong> ${task.completed_at}</span>` : '';
        
        // Process description
        let descriptionHtml = '';
        if (task.description) {
            const descriptionText = $('<div>').html(task.description).text();
            const truncatedText = descriptionText.length > 200 ? 
                descriptionText.substring(0, 200) + '...' : 
                descriptionText;
            descriptionHtml = `
                <div class="pandat69-modal-detail">
                    <strong>Description:</strong>
                    <div class="pandat69-description-text">${truncatedText}</div>
                    ${descriptionText.length > 200 ? 
                        `<button class="pandat69-read-more-modal" data-full-desc="${escapeHtml(task.description)}">Read more</button>` : 
                        ''}
                </div>`;
        }
        
        modal.innerHTML = `
            <div class="pandat69-calendar-modal-content">
                <div class="pandat69-calendar-modal-header">
                    <h3>${escapeHtml(task.name)}</h3>
                    <button class="pandat69-calendar-modal-close">×</button>
                </div>
                <div class="pandat69-calendar-modal-body">
                    <div class="pandat69-modal-detail">
                        <strong>Status:</strong> 
                        <span class="pandat69-task-status pandat69-status-${task.status}">${task.status.replace('-', ' ')}</span>
                    </div>
                    <div class="pandat69-modal-detail">
                        <strong>Priority:</strong> ${task.priority}
                    </div>
                    <div class="pandat69-modal-detail">
                        <strong>Category:</strong> ${task.category_name}
                    </div>
                    <div class="pandat69-modal-detail">
                        <strong>Start Date:</strong> ${task.start_date || 'Not set'}
                    </div>
                    ${completedText ? `<div class="pandat69-modal-detail">${completedText}</div>` : ''}
                    <div class="pandat69-modal-detail">
                        <strong>Deadline:</strong> ${task.deadline || 'No deadline'}${task.deadline_days_after_start ? 
                            ` (${task.deadline_days_after_start} days after start)` : ''}
                    </div>
                    <div class="pandat69-modal-detail">
                        <strong>Assigned to:</strong> ${task.assigned_user_names}
                    </div>
                    <div class="pandat69-modal-detail">
                        <strong>Supervisors:</strong> ${task.supervisor_user_names}
                    </div>
                    ${task.parent_task_name ? 
                        `<div class="pandat69-modal-detail"><strong>Parent Task:</strong> ${task.parent_task_name}</div>` : 
                        ''}
                    ${descriptionHtml}
                    
                    <div class="pandat69-calendar-modal-actions">
                        <button type="button" class="pandat69-button pandat69-edit-task-btn" data-task-id="${task.id}">
                            Edit
                        </button>
                        <button type="button" class="pandat69-button pandat69-delete-task-btn" data-task-id="${task.id}">
                            Delete
                        </button>
                        ${!task.parent_task_id ? 
                            `<button type="button" class="pandat69-button pandat69-add-subtask-btn" data-task-id="${task.id}">
                                Add Subtask
                            </button>` : ''
                        }
                        <button type="button" class="pandat69-button pandat69-archive-task-btn" data-task-id="${task.id}">
                            Archive
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Show modal
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Add event listeners
        modal.querySelector('.pandat69-calendar-modal-close').addEventListener('click', closeCalendarTaskModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCalendarTaskModal();
            }
        });
        
        // Handle description expansion
        const readMoreBtn = modal.querySelector('.pandat69-read-more-modal');
        if (readMoreBtn) {
            readMoreBtn.addEventListener('click', function() {
                const fullDesc = this.getAttribute('data-full-desc');
                const descDiv = modal.querySelector('.pandat69-description-text');
                descDiv.innerHTML = fullDesc;
                this.remove();
            });
        }
        
        // Add handlers for action buttons
        $(modal).find('.pandat69-edit-task-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeCalendarTaskModal();
            editTask(taskId);
        });
        
        $(modal).find('.pandat69-delete-task-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeCalendarTaskModal();
            deleteTask(taskId);
        });
        
        $(modal).find('.pandat69-add-subtask-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeCalendarTaskModal();
            addSubtask(taskId);
        });
        
        $(modal).find('.pandat69-archive-task-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeCalendarTaskModal();
            archiveTask(taskId);
        });
    }
    
    function closeCalendarTaskModal() {
        const modal = document.getElementById('pandat69-calendar-task-modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    // Handle escape key for calendar modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCalendarTaskModal();
        }
    });

        
    function openCalendarTaskModal(taskId) {
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
                    displayCalendarTaskModal(response.data.task);
                } else {
                    console.error('Error fetching task details:', response.data?.message || 'Unknown error');
                    alert('Failed to load task details. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to load task details. Please try again.');
            }
        });
    }

    
    function renderImprovedCalendarGrid(tasks, startDate, endDate, viewType) {
        const periodStart = new Date(startDate);
        const periodEnd = new Date(endDate);
        
        // Generate day columns
        const days = [];
        for (let d = new Date(periodStart); d <= periodEnd; d.setDate(d.getDate() + 1)) {
            days.push(new Date(d));
        }
        
        // Build header
        let headerHtml = '<div class="pandat69-calendar-view"><div class="pandat69-calendar-header">';
        
        days.forEach(day => {
            const options = { month: 'short', day: 'numeric' };
            if (viewType === 'month') {
                options.weekday = 'short';
            }
            const dayLabel = day.toLocaleDateString(undefined, options);
            headerHtml += `<div class="pandat69-calendar-day-column">${dayLabel}</div>`;
        });
        headerHtml += '</div>';
        
        // Build calendar body with improved task bars
        let bodyHtml = '<div class="pandat69-calendar-body">';
        
        // Calculate task positions and avoid overlaps
        const taskRows = distributeTasksInRows(tasks, periodStart, periodEnd);
        
        // Calculate grid height based on number of task rows
        const gridHeight = Math.max(400, 50 + (taskRows.length * 40));
        bodyHtml += `<div class="pandat69-calendar-grid" style="height: ${gridHeight}px; position: relative;">`;
        
        // Add day cells first
        days.forEach((day, dayIndex) => {
            const cellWidth = 100 / days.length;
            bodyHtml += `<div class="pandat69-calendar-day-cell" 
                              style="left: ${dayIndex * cellWidth}%; width: ${cellWidth}%; height: 100%;">
                         </div>`;
        });
        
        // Add task bars for each row
        taskRows.forEach((rowTasks, rowIndex) => {
            rowTasks.forEach(taskInfo => {
                const { task, startIndex, duration, leftOffset, width } = taskInfo;
                const taskColor = getTaskColor(task.id);
                
                bodyHtml += `<div class="pandat69-improved-task-bar pandat69-status-${task.status}" 
                                 style="left: ${leftOffset}%; 
                                        width: ${width}%; 
                                        top: ${40 + (rowIndex * 40)}px;
                                        background-color: ${taskColor};"
                                 data-task-id="${task.id}"
                                 title="${escapeHtml(task.name)} (${task.start_date} to ${task.deadline})">
                                 <div class="pandat69-status-indicator"></div>
                                 <span class="pandat69-task-bar-text">${escapeHtml(truncateTextForBar(task.name, width))}</span>
                             </div>`;
            });
        });
        
        bodyHtml += '</div></div></div>';
        
        return headerHtml + bodyHtml;
    }    
    
    
    function calculateTaskSpans(tasks, dateToPosition, firstDayOfGrid, lastDayOfGrid, currentMonth) {
        const taskSpans = [];
        
        // Apply "Only My Tasks" filter if active
        let filteredTasks = tasks;
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            filteredTasks = tasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
        
        // Sort tasks by start date and duration for better layering
        const sortedTasks = filteredTasks.slice().sort((a, b) => {
            const startA = new Date(a.start_date);
            const startB = new Date(b.start_date);
            if (startA.getTime() === startB.getTime()) {
                // If same start date, longer tasks first
                const endA = new Date(a.deadline);
                const endB = new Date(b.deadline);
                return (endB - startB) - (endA - startA);
            }
            return startA - startB;
        });
        
        // Track which rows are occupied for each day to avoid overlaps
        const rowOccupancy = new Map();
        
        sortedTasks.forEach(task => {
            if (!task.start_date || !task.deadline) return;
            
            const taskStart = new Date(task.start_date);
            const taskEnd = new Date(task.deadline);
            
            // Clamp task to visible grid
            const visibleStart = taskStart < firstDayOfGrid ? firstDayOfGrid : taskStart;
            const visibleEnd = taskEnd > lastDayOfGrid ? lastDayOfGrid : taskEnd;
            
            const startDateStr = formatDate(visibleStart);
            const endDateStr = formatDate(visibleEnd);
            
            const startPosition = dateToPosition.get(startDateStr);
            const endPosition = dateToPosition.get(endDateStr);
            
            if (startPosition === undefined || endPosition === undefined) return;
            
            // Find the first available row for this task
            let row = 0;
            let rowFound = false;
            
            while (!rowFound) {
                rowFound = true;
                // Check if this row is occupied for any day this task spans
                for (let pos = startPosition; pos <= endPosition; pos++) {
                    const rowKey = `${row}-${pos}`;
                    if (rowOccupancy.has(rowKey)) {
                        rowFound = false;
                        break;
                    }
                }
                if (!rowFound) row++;
            }
            
            // Mark this row as occupied for all days this task spans
            for (let pos = startPosition; pos <= endPosition; pos++) {
                const rowKey = `${row}-${pos}`;
                rowOccupancy.set(rowKey, task.id);
            }
            
            taskSpans.push({
                task,
                startPosition,
                endPosition,
                row
            });
        });
        
        return taskSpans;
    }    
    
    function renderMonthCalendarGrid(tasks, year, month) {
        const container = $('.pandat69-month-task-container');
        container.empty();
    
        // Filter tasks to only include those with both start_date and deadline
        let calendarTasks = tasks.filter(task => {
            if (!task.start_date || !task.deadline) {
                return false;
            }
            
            const taskStart = new Date(task.start_date);
            const taskEnd = new Date(task.deadline);
            const monthStart = new Date(year, month, 1);
            const monthEnd = new Date(year, month + 1, 0);
            
            return taskStart <= monthEnd && taskEnd >= monthStart;
        });
    
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            calendarTasks = calendarTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (calendarTasks.length === 0) {
            container.html('<div class="pandat69-calendar-no-tasks">No tasks with both start date and deadline found for this month.</div>');
            return;
        }
    
        // Get the first and last day of the month
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        
        // Get the first day of the calendar grid (might be from previous month)
        const firstDayOfGrid = new Date(firstDayOfMonth);
        firstDayOfGrid.setDate(firstDayOfGrid.getDate() - firstDayOfGrid.getDay());
        
        // Get the last day of the calendar grid (might be from next month)
        const lastDayOfGrid = new Date(firstDayOfGrid);
        lastDayOfGrid.setDate(lastDayOfGrid.getDate() + 41);
        
        // Adjust to end on Saturday
        while (lastDayOfGrid.getDay() !== 6) {
            lastDayOfGrid.setDate(lastDayOfGrid.getDate() - 1);
        }
        
        // Create a map of dates to grid positions
        const dateToPosition = new Map();
        const currentDate = new Date(firstDayOfGrid);
        let position = 0;
        
        while (currentDate <= lastDayOfGrid) {
            const dateStr = formatDate(currentDate);
            dateToPosition.set(dateStr, position);
            currentDate.setDate(currentDate.getDate() + 1);
            position++;
        }
        
        // Calculate task spans and distribute them in rows
        const taskSpans = calculateTaskSpans(calendarTasks, dateToPosition, firstDayOfGrid, lastDayOfGrid, month);
        
        // Calculate the maximum number of tasks in any single day
        const tasksPerDay = new Map();
        taskSpans.forEach(taskSpan => {
            for (let pos = taskSpan.startPosition; pos <= taskSpan.endPosition; pos++) {
                const currentCount = tasksPerDay.get(pos) || 0;
                tasksPerDay.set(pos, Math.max(currentCount, taskSpan.row + 1));
            }
        });
        
        const maxTasksPerDay = Math.max(...Array.from(tasksPerDay.values()), 0);
        const cellHeight = Math.max(120, 80 + (maxTasksPerDay * 25)); // Base height + task rows
        
        // Generate calendar structure
        let calendarHtml = '<div class="pandat69-month-calendar-view">';
        
        // Add header with day names
        calendarHtml += '<div class="pandat69-month-calendar-header">';
        const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        dayNames.forEach(dayName => {
            calendarHtml += `<div class="pandat69-month-day-header">${dayName}</div>`;
        });
        calendarHtml += '</div>';
        
        // Add calendar body
        calendarHtml += '<div class="pandat69-month-calendar-body">';
        calendarHtml += '<div class="pandat69-month-grid-container">';
        
        // Generate day cells
        const resetDate = new Date(firstDayOfGrid);
        position = 0;
        while (resetDate <= lastDayOfGrid) {
            const weekIndex = Math.floor(position / 7);
            const dayIndex = position % 7;
            const isCurrentMonth = resetDate.getMonth() === month;
            const dateStr = formatDate(resetDate);
            const dayNumber = resetDate.getDate();
            
            const today = new Date();
            const isToday = resetDate.toDateString() === today.toDateString();
            
            const dayClass = `pandat69-month-day-cell ${!isCurrentMonth ? 'pandat69-other-month' : ''} ${isToday ? 'pandat69-today' : ''}`.trim();
            
            calendarHtml += `<div class="${dayClass}" 
                                  data-date="${dateStr}" 
                                  data-position="${position}"
                                  style="grid-row: ${weekIndex + 1}; grid-column: ${dayIndex + 1}; min-height: ${cellHeight}px;">`;
            calendarHtml += `<div class="pandat69-month-day-number">${dayNumber}</div>`;
            
            // Add task bars directly in the cell
            const cellTasks = taskSpans.filter(span => 
                span.startPosition <= position && span.endPosition >= position
            );
            
            cellTasks.forEach(taskSpan => {
                const task = taskSpan.task;
                const taskColor = getTaskColor(task.id);
                const isTaskStart = taskSpan.startPosition === position;
                const isTaskEnd = taskSpan.endPosition === position;
                
                const taskBarClass = `pandat69-month-task-bar pandat69-status-${task.status}`;
                const taskStyle = `
                    background-color: ${taskColor};
                    margin-top: ${35 + (taskSpan.row * 25)}px;
                    ${isTaskStart ? '' : 'margin-left: -10px; border-radius: 0 4px 4px 0;'}
                    ${isTaskEnd ? '' : 'margin-right: -10px; border-radius: 4px 0 0 4px;'}
                    ${!isTaskStart && !isTaskEnd ? 'margin-left: -10px; margin-right: -10px; border-radius: 0;' : ''}
                `;
                
                calendarHtml += `
                    <div class="${taskBarClass}" 
                         data-task-id="${task.id}"
                         style="${taskStyle}"
                         title="${escapeHtml(task.name)} (${task.start_date} to ${task.deadline})">
                        ${isTaskStart ? '<div class="pandat69-status-indicator"></div>' : ''}
                        ${isTaskStart ? `<span class="pandat69-task-name">${escapeHtml(task.name)}</span>` : ''}
                    </div>
                `;
            });
            
            calendarHtml += '</div>';
            
            resetDate.setDate(resetDate.getDate() + 1);
            position++;
        }
        
        calendarHtml += '</div>'; // Close grid container
        calendarHtml += '</div>'; // Close calendar body
        calendarHtml += '</div>'; // Close calendar view
        
        container.html(calendarHtml);
        
        // Attach event handlers for task bars
        attachCalendarTaskHandlers();
    }

    // @Mention functionality
    function setupMentionAutocomplete() {
        $(document).on('input', '.pandat69-comment-textarea, #pandat69-task-description', function(e) {
            const $textarea = $(this);
            const text = $textarea.val();
            const caretPos = this.selectionStart;
            
            // Find the last '@' before the caret position
            const textBeforeCaret = text.substring(0, caretPos);
            const lastAtIndex = textBeforeCaret.lastIndexOf('@');
            
            if (lastAtIndex === -1) {
                hideMentionDropdown();
                return;
            }
            
            // Get the text after '@' up to the caret
            const mentionText = textBeforeCaret.substring(lastAtIndex + 1);
            
            // Check if there's a space (which would break the mention)
            if (mentionText.includes(' ') || mentionText.includes('\n')) {
                hideMentionDropdown();
                return;
            }
            
            // If we have at least 1 character after '@', search for users
            if (mentionText.length >= 1) {
                searchUsersForMention(mentionText, $textarea, lastAtIndex);
            } else if (mentionText.length === 0) {
                // Just typed '@', show recent users or all users
                searchUsersForMention('', $textarea, lastAtIndex);
            } else {
                hideMentionDropdown();
            }
        });
        
        // Hide dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.pandat69-mention-dropdown, .pandat69-comment-textarea, #pandat69-task-description').length) {
                hideMentionDropdown();
            }
        });
        
        // Handle keyboard navigation in mention dropdown
        $(document).on('keydown', '.pandat69-comment-textarea, #pandat69-task-description', function(e) {
            const $dropdown = $('.pandat69-mention-dropdown');
            if (!$dropdown.is(':visible')) return;
            
            const $items = $dropdown.find('.pandat69-mention-item');
            const $active = $items.filter('.active');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if ($active.length === 0) {
                    $items.first().addClass('active');
                } else {
                    $active.removeClass('active');
                    const next = $active.next('.pandat69-mention-item');
                    if (next.length) {
                        next.addClass('active');
                    } else {
                        $items.first().addClass('active');
                    }
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if ($active.length === 0) {
                    $items.last().addClass('active');
                } else {
                    $active.removeClass('active');
                    const prev = $active.prev('.pandat69-mention-item');
                    if (prev.length) {
                        prev.addClass('active');
                    } else {
                        $items.last().addClass('active');
                    }
                }
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                if ($active.length) {
                    $active.click();
                }
            } else if (e.key === 'Escape') {
                hideMentionDropdown();
            }
        });
    }
    
    function searchUsersForMention(searchTerm, $textarea, mentionStartPos) {
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
                    showMentionDropdown(response.data.users, $textarea, mentionStartPos, searchTerm);
                } else {
                    hideMentionDropdown();
                }
            },
            error: function() {
                hideMentionDropdown();
            }
        });
    }
    
    function showMentionDropdown(users, $textarea, mentionStartPos, searchTerm) {
        hideMentionDropdown(); // Hide any existing dropdown
        
        if (users.length === 0) return;
        
        const $dropdown = $('<div class="pandat69-mention-dropdown"></div>');
        
        users.forEach(function(user, index) {
            const userName = user.name;
            const highlightedName = searchTerm ? 
                userName.replace(new RegExp('(' + escapeRegExp(searchTerm) + ')', 'gi'), '<span class="pandat69-mention-highlight">$1</span>') :
                userName;
                
            const $item = $('<div class="pandat69-mention-item" data-user-id="' + user.id + '" data-user-name="' + escapeHtml(userName) + '">' + highlightedName + '</div>');
            
            $item.on('click', function() {
                insertMention($textarea, mentionStartPos, searchTerm, user.id, userName);
                hideMentionDropdown();
            });
            
            // Add hover effect
            $item.on('mouseenter', function() {
                $('.pandat69-mention-item').removeClass('active');
                $(this).addClass('active');
            });
            
            $dropdown.append($item);
        });
        
        // Position the dropdown
        const textareaOffset = $textarea.offset();
        const textareaHeight = $textarea.outerHeight();
        
        $dropdown.css({
            position: 'absolute',
            top: textareaOffset.top + textareaHeight,
            left: textareaOffset.left,
            zIndex: 1000
        });
        
        $('body').append($dropdown);
        
        // Highlight first item by default
        $dropdown.find('.pandat69-mention-item').first().addClass('active');
    }
    
    function insertMention($textarea, mentionStartPos, searchTerm, userId, userName) {
        const textarea = $textarea[0];
        const text = $textarea.val();
        
        // Calculate where to insert the mention
        const beforeMention = text.substring(0, mentionStartPos);
        const afterMention = text.substring(mentionStartPos + 1 + searchTerm.length);
        
        // Create the mention in the format expected by PHP: @[Username](123)
        const mention = '@[' + userName + '](' + userId + ')';
        
        // Insert the mention
        const newText = beforeMention + mention + afterMention;
        $textarea.val(newText);
        
        // Position cursor after the mention
        const newCaretPos = mentionStartPos + mention.length;
        textarea.setSelectionRange(newCaretPos, newCaretPos);
        
        // Focus back on textarea
        $textarea.focus();
    }
    
    function hideMentionDropdown() {
        $('.pandat69-mention-dropdown').remove();
    }
    
    // Helper functions
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function renderCalendarGrid(tasks, startDate, endDate, viewType) {
        const periodStart = new Date(startDate);
        const periodEnd = new Date(endDate);
        
        // Generate day columns
        const days = [];
        for (let d = new Date(periodStart); d <= periodEnd; d.setDate(d.getDate() + 1)) {
            days.push(new Date(d));
        }
        
        // Build header
        let headerHtml = '<div class="pandat69-calendar-view"><div class="pandat69-calendar-header">';
        
        days.forEach(day => {
            const options = { month: 'short', day: 'numeric' };
            if (viewType === 'month') {
                options.weekday = 'short';
            }
            const dayLabel = day.toLocaleDateString(undefined, options);
            headerHtml += `<div class="pandat69-calendar-day-column">${dayLabel}</div>`;
        });
        headerHtml += '</div>';
        
        // Build calendar body with task bars
        let bodyHtml = '<div class="pandat69-calendar-body">';
        
        // Calculate task positions and avoid overlaps
        const taskRows = distributeTasksInRows(tasks, periodStart, periodEnd);
        
        // Add task bars for each row
        taskRows.forEach((rowTasks, rowIndex) => {
            bodyHtml += '<div class="pandat69-calendar-timeline-row">';
            
            // Add day cells first
            days.forEach((day, dayIndex) => {
                bodyHtml += '<div class="pandat69-calendar-day-cell"></div>';
            });
            
            // Add task bars for this row
            rowTasks.forEach(taskInfo => {
                const { task, startIndex, duration, leftOffset, width } = taskInfo;
                
                // Create truncated task name that fits in the bar
                const truncatedName = truncateTextForBar(task.name, width);
                
                bodyHtml += `<div class="pandat69-task-bar pandat69-status-${task.status}" 
                                 style="left: ${leftOffset}%; width: ${width}%;"
                                 data-task-id="${task.id}"
                                 title="${escapeHtml(task.name)} (${task.start_date} to ${task.deadline})">
                                 <span class="pandat69-task-bar-text">${escapeHtml(truncatedName)}</span>
                             </div>`;
            });
            
            bodyHtml += '</div>';
        });
        
        bodyHtml += '</div></div>';
        
        return headerHtml + bodyHtml;
    }

    function distributeTasksInRows(tasks, periodStart, periodEnd) {
        const totalDays = Math.ceil((periodEnd - periodStart) / (24 * 60 * 60 * 1000)) + 1;
        const dayWidth = 100 / totalDays;
        const rows = [];
        
        // Sort tasks by start date, then by duration
        const sortedTasks = tasks.slice().sort((a, b) => {
            const dateA = new Date(a.start_date);
            const dateB = new Date(b.start_date);
            if (dateA.getTime() === dateB.getTime()) {
                // If same start date, longer tasks first
                const durationA = Math.ceil((new Date(a.deadline) - dateA) / (24 * 60 * 60 * 1000));
                const durationB = Math.ceil((new Date(b.deadline) - dateB) / (24 * 60 * 60 * 1000));
                return durationB - durationA;
            }
            return dateA - dateB;
        });
        
        sortedTasks.forEach(task => {
            const taskStart = new Date(task.start_date);
            const taskEnd = new Date(task.deadline);
            
            // Clamp task dates to the visible period
            const visibleStart = taskStart < periodStart ? periodStart : taskStart;
            const visibleEnd = taskEnd > periodEnd ? periodEnd : taskEnd;
            
            // Calculate position and width with more precision
            const startIndex = Math.floor((visibleStart - periodStart) / (24 * 60 * 60 * 1000));
            const duration = Math.floor((visibleEnd - visibleStart) / (24 * 60 * 60 * 1000)) + 1;
            
            // Ensure minimum width and proper positioning
            const leftOffset = startIndex * dayWidth;
            const width = Math.max(duration * dayWidth, dayWidth * 0.1); // Minimum 10% of a day width
            
            const taskInfo = {
                task,
                startIndex,
                duration,
                leftOffset,
                width
            };
            
            // Find a row where this task doesn't overlap
            let placed = false;
            for (let i = 0; i < rows.length; i++) {
                let hasOverlap = false;
                for (const existingTask of rows[i]) {
                    const existingEnd = existingTask.startIndex + existingTask.duration;
                    const taskEnd = startIndex + duration;
                    
                    // Add a small buffer to prevent touching tasks
                    if (!(startIndex >= existingEnd || taskEnd <= existingTask.startIndex)) {
                        hasOverlap = true;
                        break;
                    }
                }
                
                if (!hasOverlap) {
                    rows[i].push(taskInfo);
                    placed = true;
                    break;
                }
            }
            
            // If no existing row worked, create a new one
            if (!placed) {
                rows.push([taskInfo]);
            }
        });
        
        return rows;
    }
    function truncateTextForBar(text, widthPercentage) {
        // Estimate characters that can fit based on width percentage
        // This is a rough calculation - adjust the multiplier as needed
        const maxChars = Math.floor(widthPercentage * 0.8); // Rough estimate
        
        if (text.length <= maxChars) {
            return text;
        }
        
        // Truncate and add ellipsis
        return text.substring(0, Math.max(1, maxChars - 3)) + '...';
    }    
    
    // Task bar modal functions
    function openTaskBarModal(taskId) {
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
                    displayTaskBarModal(response.data.task);
                } else {
                    console.error('Error fetching task details:', response.data?.message || 'Unknown error');
                    alert('Failed to load task details. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to load task details. Please try again.');
            }
        });
    }
    
    function displayTaskBarModal(task) {
        let modal = document.getElementById('pandat69-task-bar-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'pandat69-task-bar-modal';
            modal.className = 'pandat69-task-bar-modal';
            document.body.appendChild(modal);
        }
        
        const isSubtask = task.parent_task_id ? true : false;
        const isArchived = task.archived == 1; // Ensure correct comparison
    
        const completedText = task.status === 'done' && task.completed_at ? 
            `<div class="pandat69-detail-item"><strong>Completed:</strong> ${escapeHtml(task.completed_at)}</div>` : '';
        
        let descriptionHtml = '';
        if (task.description) {
            descriptionHtml = `
                <div class="pandat69-detail-item">
                    <strong>Description:</strong>
                    <div style="margin-top: 5px; padding: 10px; background: #f9f9f9; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                        ${task.description} {/* Assuming description is already safe HTML from wp_kses_post */}
                    </div>
                </div>`;
        }
    
        // --- NEW: Buttons for Archive/Unarchive and Add Subtask ---
        let archiveButtonHtml = '';
        if (isArchived) {
            archiveButtonHtml = `<button type="button" class="pandat69-button pandat69-unarchive-task-modal-btn" data-task-id="${task.id}">Restore from Archive</button>`;
        } else {
            archiveButtonHtml = `<button type="button" class="pandat69-button pandat69-archive-task-modal-btn" data-task-id="${task.id}">Archive Task</button>`;
        }
    
        let addSubtaskButtonHtml = '';
        if (!isSubtask) {
            addSubtaskButtonHtml = `<button type="button" class="pandat69-button pandat69-add-subtask-modal-btn" data-task-id="${task.id}">Add Subtask</button>`;
        }
        // --- END NEW Buttons ---
        
        modal.innerHTML = `
            <div class="pandat69-task-bar-modal-content">
                <div class="pandat69-task-bar-modal-header">
                    <h3 class="pandat69-task-bar-modal-title">${escapeHtml(task.name)}</h3>
                    <button class="pandat69-task-bar-modal-close">×</button>
                </div>
                <div class="pandat69-task-bar-modal-body">
                    <div class="pandat69-detail-item">
                        <strong>Status:</strong> 
                        <span class="pandat69-task-status pandat69-status-${task.status}">${escapeHtml(task.status.replace('-', ' '))}</span>
                    </div>
                    <div class="pandat69-detail-item">
                        <strong>Priority:</strong> ${escapeHtml(String(task.priority))}
                    </div>
                    <div class="pandat69-detail-item">
                        <strong>Category:</strong> ${escapeHtml(task.category_name)}
                    </div>
                    <div class="pandat69-detail-item">
                        <strong>Start Date:</strong> ${escapeHtml(task.start_date || 'Not set')}
                    </div>
                    ${completedText}
                    <div class="pandat69-detail-item">
                        <strong>Deadline:</strong> ${escapeHtml(task.deadline || 'No deadline')}${task.deadline_days_after_start ? 
                            ` (${escapeHtml(String(task.deadline_days_after_start))} days after start)` : ''}
                    </div>
                    <div class="pandat69-detail-item">
                        <strong>Assigned to:</strong> ${escapeHtml(task.assigned_user_names)}
                    </div>
                    <div class="pandat69-detail-item">
                        <strong>Supervisors:</strong> ${escapeHtml(task.supervisor_user_names)}
                    </div>
                    ${task.parent_task_name ? 
                        `<div class="pandat69-detail-item"><strong>Parent Task:</strong> ${escapeHtml(task.parent_task_name)}</div>` : 
                        ''}
                    ${descriptionHtml}
                    
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e5eb; display: flex; flex-wrap: wrap; gap: 10px;">
                        <button type="button" class="pandat69-button pandat69-edit-task-modal-btn" data-task-id="${task.id}">
                            Edit Task
                        </button>
                        <button type="button" class="pandat69-button pandat69-button-danger pandat69-delete-task-modal-btn" data-task-id="${task.id}">
                            Delete Task
                        </button>
                        ${archiveButtonHtml}
                        ${addSubtaskButtonHtml}
                    </div>
                </div>
            </div>
        `;
        
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        modal.querySelector('.pandat69-task-bar-modal-close').addEventListener('click', closeTaskBarModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeTaskBarModal();
            }
        });
        
        // --- UPDATED: Event Handlers for Modal Buttons ---
        $(modal).find('.pandat69-edit-task-modal-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeTaskBarModal(); 
            editTask(taskId); // editTask already opens the main form modal
        });
        
        $(modal).find('.pandat69-delete-task-modal-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            deleteTask(taskId); // deleteTask will ask for confirmation
            closeTaskBarModal(); // Close modal after initiating delete
        });
    
        $(modal).find('.pandat69-archive-task-modal-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            archiveTask(taskId); // archiveTask will ask for confirmation
            closeTaskBarModal();
        });
    
        $(modal).find('.pandat69-unarchive-task-modal-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            unarchiveTask(taskId);
            closeTaskBarModal();
        });
    
        $(modal).find('.pandat69-add-subtask-modal-btn').on('click', function() {
            const taskId = $(this).data('task-id');
            closeTaskBarModal();
            addSubtask(taskId); // addSubtask opens the main form modal
        });
        // --- END UPDATED Event Handlers ---
    }
    
    function closeTaskBarModal() {
        const modal = document.getElementById('pandat69-task-bar-modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeTaskBarModal();
        }
    });    
    
    
    // Add this helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }


    function renderWeekViewPerDay(tasks, startDate, endDate) {
        const container = $('.pandat69-week-task-container');
        container.empty();
    
        // Filter tasks for this week based on deadlines and start dates
        let filteredTasks = tasks.filter(task => {
            // Include tasks with deadlines in this week
            if (task.deadline && isDateInRange(task.deadline, startDate, endDate)) {
                task.isDeadlineTask = true;
                return true;
            }
            
            // Include tasks with start dates in this week (if option is enabled)
            if (weekShowStartingTasks && task.start_date && isDateInRange(task.start_date, startDate, endDate)) {
                task.isStartingTask = true;
                return true;
            }
            
            return false;
        });
        
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            filteredTasks = filteredTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (filteredTasks.length === 0) {
            container.html('<p>No tasks scheduled for this week.</p>');
            return;
        }
    
        // Group tasks by date
        const tasksByDate = {};
        
        // Group by deadline date
        filteredTasks.forEach(task => {
            if (task.isDeadlineTask && task.deadline) {
                if (!tasksByDate[task.deadline]) {
                    tasksByDate[task.deadline] = [];
                }
                
                // Only add if not already added via start date (to avoid duplicates)
                if (!task.isStartingTask || task.start_date !== task.deadline) {
                    tasksByDate[task.deadline].push({...task, displayType: 'deadline'});
                }
            }
        });
        
        // Group by start date (if option enabled)
        if (weekShowStartingTasks) {
            filteredTasks.forEach(task => {
                if (task.isStartingTask && task.start_date) {
                    if (!tasksByDate[task.start_date]) {
                        tasksByDate[task.start_date] = [];
                    }
                    
                    // Check if task already exists for this date through deadline
                    const existingIndex = tasksByDate[task.start_date].findIndex(t => t.id === task.id);
                    
                    if (existingIndex >= 0) {
                        // Update existing task to show both indicators
                        tasksByDate[task.start_date][existingIndex].displayType = 'both';
                    } else {
                        // Add as new task
                        tasksByDate[task.start_date].push({...task, displayType: 'start'});
                    }
                }
            });
        }
    
        // For each date in the week
        for (let d = new Date(startDate); d <= new Date(endDate); d.setDate(d.getDate() + 1)) {
            const dateStr = formatDate(d);
            const formattedDate = formatDisplayDate(d);
    
            const dateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">${formattedDate}</div>
                    <ul class="pandat69-task-list">
                        ${tasksByDate[dateStr] ? tasksByDate[dateStr].map(task => renderTaskItemEnhanced(task)).join('') : 
                        '<li class="pandat69-empty-day">No tasks</li>'}
                    </ul>
                </div>
            `);
    
            container.append(dateGroup);
        }
    
        // Reattach event handlers to all task items
        attachTaskEventHandlers();
    }
    
    function renderWeekViewAll(tasks, startDate, endDate) {
        const container = $('.pandat69-week-task-container');
        container.empty();
    
        // Filter tasks based on their relationship to this week
        let filteredTasks = filterTasksForRange(tasks, startDate, endDate);
        
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            filteredTasks = filteredTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (filteredTasks.length === 0) {
            container.html('<p>No tasks related to this week.</p>');
            return;
        }
    
        // Split tasks into categories
        const spanningTasks = filteredTasks.filter(task => task.isSpanningTask);
        const startingTasks = filteredTasks.filter(task => task.isStartingTask && !task.isSpanningTask);
        const deadlineTasks = filteredTasks.filter(task => task.isDeadlineTask && !task.isSpanningTask && !task.isStartingTask);
        const intersectingTasks = filteredTasks.filter(task => task.isStartingTask && task.isDeadlineTask && !task.isSpanningTask);
    
        // Move tasks that both start and end in the period to their own section
        const pureStartingTasks = startingTasks.filter(task => !intersectingTasks.some(t => t.id === task.id));
        const pureDeadlineTasks = deadlineTasks.filter(task => !intersectingTasks.some(t => t.id === task.id));
    
        // Create section for spanning tasks
        if (spanningTasks.length > 0) {
            const spanningSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Spanning This Entire Week</h3>
                    <ul class="pandat69-task-list">
                        ${spanningTasks.map(task => renderTaskItemEnhanced(task)).join('')}
                    </ul>
                </div>
            `);
            container.append(spanningSection);
        }
    
        // Create section for tasks that both start and end in this week
        if (intersectingTasks.length > 0) {
            const intersectingSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Both Starting and Ending This Week</h3>
                    <ul class="pandat69-task-list">
                        ${intersectingTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'both'})).join('')}
                    </ul>
                </div>
            `);
            container.append(intersectingSection);
        }
    
        // Create section for starting tasks
        if (pureStartingTasks.length > 0) {
            const startingSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Starting This Week</h3>
                    <ul class="pandat69-task-list">
                        ${pureStartingTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'start'})).join('')}
                    </ul>
                </div>
            `);
            container.append(startingSection);
        }
    
        // Create section for deadline tasks
        if (pureDeadlineTasks.length > 0) {
            const deadlineSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks With Deadlines This Week</h3>
                    <ul class="pandat69-task-list">
                        ${pureDeadlineTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'deadline'})).join('')}
                    </ul>
                </div>
            `);
            container.append(deadlineSection);
        }
    
        // Reattach event handlers
        attachTaskEventHandlers();
    }
    
    function renderMonthViewPerDay(tasks, startDate, endDate, year, month) {
        const container = $('.pandat69-month-task-container');
        container.empty();
    
        // Filter tasks for this month based on deadlines and start dates
        let filteredTasks = tasks.filter(task => {
            // Include tasks with deadlines in this month
            if (task.deadline && isDateInRange(task.deadline, startDate, endDate)) {
                task.isDeadlineTask = true;
                return true;
            }
            
            // Include tasks with start dates in this month (if option is enabled)
            if (monthShowStartingTasks && task.start_date && isDateInRange(task.start_date, startDate, endDate)) {
                task.isStartingTask = true;
                return true;
            }
            
            return false;
        });
        
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            filteredTasks = filteredTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (filteredTasks.length === 0) {
            container.html('<p>No tasks scheduled for this month.</p>');
            return;
        }
    
        // Group tasks by date
        const tasksByDate = {};
        
        // Group by deadline date
        filteredTasks.forEach(task => {
            if (task.isDeadlineTask && task.deadline) {
                if (!tasksByDate[task.deadline]) {
                    tasksByDate[task.deadline] = [];
                }
                
                // Only add if not already added via start date (to avoid duplicates)
                if (!task.isStartingTask || task.start_date !== task.deadline) {
                    tasksByDate[task.deadline].push({...task, displayType: 'deadline'});
                }
            }
        });
        
        // Group by start date (if option enabled)
        if (monthShowStartingTasks) {
            filteredTasks.forEach(task => {
                if (task.isStartingTask && task.start_date) {
                    if (!tasksByDate[task.start_date]) {
                        tasksByDate[task.start_date] = [];
                    }
                    
                    // Check if task already exists for this date through deadline
                    const existingIndex = tasksByDate[task.start_date].findIndex(t => t.id === task.id);
                    
                    if (existingIndex >= 0) {
                        // Update existing task to show both indicators
                        tasksByDate[task.start_date][existingIndex].displayType = 'both';
                    } else {
                        // Add as new task
                        tasksByDate[task.start_date].push({...task, displayType: 'start'});
                    }
                }
            });
        }
    
        // Sort dates
        const sortedDates = Object.keys(tasksByDate).sort();
    
        // Render each date group
        sortedDates.forEach(dateStr => {
            const d = new Date(dateStr);
            const formattedDate = formatDisplayDate(d);
    
            const dateGroup = $(`
                <div class="pandat69-month-date-group">
                    <div class="pandat69-month-date-header">${formattedDate}</div>
                    <ul class="pandat69-task-list">
                        ${tasksByDate[dateStr].map(task => renderTaskItemEnhanced(task)).join('')}
                    </ul>
                </div>
            `);
    
            container.append(dateGroup);
        });
    
        // Reattach event handlers to all task items
        attachTaskEventHandlers();
    }
    
    function renderMonthViewAll(tasks, startDate, endDate) {
        const container = $('.pandat69-month-task-container');
        container.empty();
    
        // Filter tasks based on their relationship to this month
        let filteredTasks = filterTasksForRange(tasks, startDate, endDate);
        
        // Apply "Only My Tasks" filter if active
        if (showOnlyMyTasks) {
            const currentUserId = pandat69_ajax_object.current_user_id;
            filteredTasks = filteredTasks.filter(function(task) {
                return task.assigned_user_ids && 
                       task.assigned_user_ids.includes(currentUserId.toString());
            });
        }
    
        if (filteredTasks.length === 0) {
            container.html('<p>No tasks related to this month.</p>');
            return;
        }
    
        // Split tasks into categories
        const spanningTasks = filteredTasks.filter(task => task.isSpanningTask);
        const startingTasks = filteredTasks.filter(task => task.isStartingTask && !task.isSpanningTask);
        const deadlineTasks = filteredTasks.filter(task => task.isDeadlineTask && !task.isSpanningTask && !task.isStartingTask);
        const intersectingTasks = filteredTasks.filter(task => task.isStartingTask && task.isDeadlineTask && !task.isSpanningTask);
    
        // Move tasks that both start and end in the period to their own section
        const pureStartingTasks = startingTasks.filter(task => !intersectingTasks.some(t => t.id === task.id));
        const pureDeadlineTasks = deadlineTasks.filter(task => !intersectingTasks.some(t => t.id === task.id));
    
        // Create section for spanning tasks
        if (spanningTasks.length > 0) {
            const spanningSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Spanning This Entire Month</h3>
                    <ul class="pandat69-task-list">
                        ${spanningTasks.map(task => renderTaskItemEnhanced(task)).join('')}
                    </ul>
                </div>
            `);
            container.append(spanningSection);
        }
    
        // Create section for tasks that both start and end in this month
        if (intersectingTasks.length > 0) {
            const intersectingSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Both Starting and Ending This Month</h3>
                    <ul class="pandat69-task-list">
                        ${intersectingTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'both'})).join('')}
                    </ul>
                </div>
            `);
            container.append(intersectingSection);
        }
    
        // Create section for starting tasks
        if (pureStartingTasks.length > 0) {
            const startingSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks Starting This Month</h3>
                    <ul class="pandat69-task-list">
                        ${pureStartingTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'start'})).join('')}
                    </ul>
                </div>
            `);
            container.append(startingSection);
        }
    
        // Create section for deadline tasks
        if (pureDeadlineTasks.length > 0) {
            const deadlineSection = $(`
                <div class="pandat69-week-section">
                    <h3>Tasks With Deadlines This Month</h3>
                    <ul class="pandat69-task-list">
                        ${pureDeadlineTasks.map(task => renderTaskItemEnhanced({...task, displayType: 'deadline'})).join('')}
                    </ul>
                </div>
            `);
            container.append(deadlineSection);
        }
    
        // Reattach event handlers
        attachTaskEventHandlers();
    }

    function renderTaskItemEnhanced(task, isArchived = false) {
        const isSubtask = task.parent_task_id ? true : false;
        const displayType = task.displayType || (task.isSpanningTask ? 'spanning' : 
                              (task.isStartingTask && task.isDeadlineTask ? 'both' : 
                              (task.isStartingTask ? 'start' : 
                              (task.isDeadlineTask ? 'deadline' : ''))));
        
        // Show completed_at if status is done
        const completedText = task.status === 'done' && task.completed_at ? 
            `<span><strong>Completed:</strong> ${task.completed_at}</span>` : '';
        
        // Process description for display
        let descriptionHtml = '';
        if (task.description) {
            // Strip HTML to avoid broken tags in truncated version
            const descriptionText = $('<div>').html(task.description).text();
            const truncatedText = descriptionText.length > 150 ? 
                descriptionText.substring(0, 150) + '...' : 
                descriptionText;
                
            descriptionHtml = `
                <div class="pandat69-task-description">
                    <strong>Description:</strong>
                    <div class="pandat69-description-preview">
                        <span class="pandat69-description-text">${truncatedText}</span>
                        ${descriptionText.length > 150 ? 
                            `<a href="#" class="pandat69-read-more" data-task-id="${task.id}">Read more</a>` : 
                            ''}
                        <div class="pandat69-full-description" style="display: none;">${task.description}</div>
                    </div>
                </div>`;
        }
        
        // Build task indicator classes
        let taskClasses = `pandat69-task-item ${isArchived ? 'pandat69-archived-task' : ''} `;
        taskClasses += `${isSubtask ? 'pandat69-subtask' : ''} `;
        
        // Add task type indicators based on display type
        if (displayType === 'start' || displayType === 'both') taskClasses += 'pandat69-starting-task ';
        if (displayType === 'deadline' || displayType === 'both') taskClasses += 'pandat69-deadline-task ';
        if (displayType === 'spanning') taskClasses += 'pandat69-spanning-task ';
        
        // Create task type label based on display type
        let typeLabel = '';
        if (displayType === 'start') {
            typeLabel = '<span class="pandat69-task-type-label pandat69-type-start">Starts</span>';
        } else if (displayType === 'deadline') {
            typeLabel = '<span class="pandat69-task-type-label pandat69-type-deadline">Deadline</span>';
        } else if (displayType === 'both') {
            typeLabel = '<span class="pandat69-task-type-label pandat69-type-both">Starts & Ends</span>';
        } else if (displayType === 'spanning') {
            typeLabel = '<span class="pandat69-task-type-label pandat69-type-spanning">Ongoing</span>';
        }
        
        return `
        <li class="${taskClasses.trim()}" 
            data-task-id="${task.id}" 
            data-parent-id="${task.parent_task_id || ''}"
            data-is-subtask="${isSubtask ? '1' : '0'}">
            <div class="pandat69-task-item-details">
                <div class="pandat69-task-item-name">${task.name}</div>
                <div class="pandat69-task-item-meta">
                    <span><span class="pandat69-task-status pandat69-status-${task.status}" data-status="${task.status}">${task.status.replace('-', ' ')}</span>${typeLabel}</span>
                    <span><strong>Priority:</strong> ${task.priority}</span>
                    ${task.start_date ? `<span><strong>Start:</strong> ${task.start_date}</span>` : ''}
                    ${completedText}
                    ${task.deadline ? `<span><strong>Deadline:</strong> ${task.deadline}${task.deadline_days_after_start ? ` (${task.deadline_days_after_start} days after start)` : ''}</span>` : ''}
                    <span><strong>Category:</strong> ${task.category_name}</span>
                    <span><strong>Assigned to:</strong> ${task.assigned_user_names}</span>
                    ${task.supervisor_user_names && task.supervisor_user_names !== 'No supervisors' ? 
                        `<span><strong>Supervisors:</strong> ${task.supervisor_user_names}</span>` : ''}
                    ${task.parent_task_name ? 
                        `<span class="pandat69-parent-task-highlight"><strong>Subtask of:</strong> ${task.parent_task_name}</span>` : ''}
                    ${descriptionHtml}
                </div>
            </div>
            <div class="pandat69-task-item-footer">
                <div class="pandat69-footer-left">
                    <button type="button" class="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task">✏️</button>
                    <button type="button" class="pandat69-icon-button pandat69-delete-task-btn" title="Delete Task">🗑️</button>
                    ${isArchived ? 
                        `<button type="button" class="pandat69-icon-button pandat69-unarchive-task-btn" title="Restore from Archive">🔄</button>` : 
                        `<button type="button" class="pandat69-icon-button pandat69-archive-task-btn" title="Archive Task">📥</button>`
                    }
                    ${!isSubtask ? 
                        `<button type="button" class="pandat69-icon-button pandat69-add-subtask-btn" title="Add Subtask">➕</button>` : 
                        ''
                    }
                </div>
                <div class="pandat69-footer-right">
                    <button type="button" class="pandat69-button pandat69-show-comments-btn" data-task-id="${task.id}">
                        Show Comments
                    </button>
                </div>
            </div>
        </li>`;
    }

    // Helper function to check if a date is within a range
    function isDateInRange(dateStr, startStr, endStr) {
        if (!dateStr) return false;
        
        const date = new Date(dateStr);
        const start = new Date(startStr);
        const end = new Date(endStr);
        
        // Set times to midnight for proper comparison
        date.setHours(0, 0, 0, 0);
        start.setHours(0, 0, 0, 0);
        end.setHours(0, 0, 0, 0);
        
        return date >= start && date <= end;
    }

    // Helper function to filter tasks for "Show All" view
    function filterTasksForRange(tasks, startStr, endStr) {
        const start = new Date(startStr);
        const end = new Date(endStr);
        
        // Set times to midnight for proper comparison
        start.setHours(0, 0, 0, 0);
        end.setHours(0, 0, 0, 0);
        
        return tasks.filter(task => {
            let isRelevant = false;
            
            // Step 1: Identify tasks with deadlines in the range
            if (task.deadline && isDateInRange(task.deadline, startStr, endStr)) {
                task.isDeadlineTask = true;
                isRelevant = true;
            } else {
                task.isDeadlineTask = false;
            }
            
            // Step 2: Identify tasks that start in the range
            if (task.start_date && isDateInRange(task.start_date, startStr, endStr)) {
                task.isStartingTask = true;
                isRelevant = true;
            } else {
                task.isStartingTask = false;
            }
            
            // Step 3: Identify tasks that encompass the range (start before, end after)
            if (task.start_date && task.deadline) {
                const taskStart = new Date(task.start_date);
                const taskEnd = new Date(task.deadline);
                
                // Set times to midnight for proper comparison
                taskStart.setHours(0, 0, 0, 0);
                taskEnd.setHours(0, 0, 0, 0);
                
                if (taskStart <= start && taskEnd >= end) {
                    task.isSpanningTask = true;
                    isRelevant = true;
                } else {
                    task.isSpanningTask = false;
                }
            } else {
                task.isSpanningTask = false;
            }
            
            return isRelevant;
        });
    }

    function renderTaskItem(task, isArchived = false) {
        const isSubtask = task.parent_task_id ? true : false;
        
        // Show completed_at if status is done
        const completedText = task.status === 'done' && task.completed_at ? 
            `<span><strong>Completed:</strong> ${task.completed_at}</span>` : '';
        
        // Process description for display
        let descriptionHtml = '';
        if (task.description) {
            // Strip HTML to avoid broken tags in truncated version
            const descriptionText = $('<div>').html(task.description).text();
            const truncatedText = descriptionText.length > 150 ? 
                descriptionText.substring(0, 150) + '...' : 
                descriptionText;
                
            descriptionHtml = `
                <div class="pandat69-task-description">
                    <strong>Description:</strong>
                    <div class="pandat69-description-preview">
                        <span class="pandat69-description-text">${truncatedText}</span>
                        ${descriptionText.length > 150 ? 
                            `<a href="#" class="pandat69-read-more" data-task-id="${task.id}">Read more</a>` : 
                            ''}
                        <div class="pandat69-full-description" style="display: none;">${task.description}</div>
                    </div>
                </div>`;
        }
        
        return `
        <li class="pandat69-task-item ${isArchived ? 'pandat69-archived-task' : ''} ${isSubtask ? 'pandat69-subtask' : ''}" 
            data-task-id="${task.id}" 
            data-parent-id="${task.parent_task_id || ''}"
            data-is-subtask="${isSubtask ? '1' : '0'}">
            <div class="pandat69-task-item-details">
                ${isSubtask ? '<div class="pandat69-subtask-indicator">↳</div>' : ''}
                <div class="pandat69-task-item-name">${task.name}</div>
                <div class="pandat69-task-item-meta">
                    <span><span class="pandat69-task-status pandat69-status-${task.status}" data-status="${task.status}">${task.status.replace('-', ' ')}</span></span>
                    <span><strong>Priority:</strong> ${task.priority}</span>
                    ${task.start_date ? `<span><strong>Started:</strong> ${task.start_date}</span>` : ''}
                    ${completedText}
                    ${task.deadline ? `<span><strong>Deadline:</strong> ${task.deadline}${task.deadline_days_after_start ? ` (${task.deadline_days_after_start} days after start)` : ''}</span>` : ''}
                    <span><strong>Category:</strong> ${task.category_name}</span>
                    <span><strong>Assigned to:</strong> ${task.assigned_user_names}</span>
                    <span><strong>Supervisors:</strong> ${task.supervisor_user_names}</span>
                    ${task.parent_task_name ? `<span><strong>Parent:</strong> ${task.parent_task_name}</span>` : ''}
                    ${descriptionHtml}
                </div>
            </div>
            <div class="pandat69-task-item-footer">
                <div class="pandat69-footer-left">
                    <button type="button" class="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task">✏️</button>
                    <button type="button" class="pandat69-icon-button pandat69-delete-task-btn" title="Delete Task">🗑️</button>
                    ${isArchived ? 
                        `<button type="button" class="pandat69-icon-button pandat69-unarchive-task-btn" title="Restore from Archive">🔄</button>` : 
                        `<button type="button" class="pandat69-icon-button pandat69-archive-task-btn" title="Archive Task">📥</button>`
                    }
                    ${!isSubtask ? 
                        `<button type="button" class="pandat69-icon-button pandat69-add-subtask-btn" title="Add Subtask">➕</button>` : 
                        ''
                    }
                </div>
                <div class="pandat69-footer-right">
                    <button type="button" class="pandat69-button pandat69-show-comments-btn" data-task-id="${task.id}">
                        Show Comments
                    </button>
                </div>
            </div>
        </li>`;
    }
    function loadArchivedTasks() {
        const $container = $('.pandat69-container');
        const boardName = $container.data('board-name');
        
        $('.pandat69-archive-task-list').hide();
        $('.pandat69-tab-archive .pandat69-loading').show();
        
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_fetch_tasks',
                nonce: pandat69_ajax_object.nonce,
                board_name: boardName,
                archived: 1 // Explicitly request archived tasks
            },
            success: function(response) {
                if (response.success && response.data.tasks) {
                    renderArchivedTasks(response.data.tasks);
                } else {
                    console.error('Error loading archived tasks:', response.data?.message || 'Unknown error');
                    $('.pandat69-archive-task-list').html('<li class="pandat69-no-tasks">No archived tasks found.</li>').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                $('.pandat69-archive-task-list').html('<li class="pandat69-no-tasks">Error loading archived tasks.</li>').show();
            },
            complete: function() {
                $('.pandat69-tab-archive .pandat69-loading').hide();
            }
        });
    }
    
    function renderArchivedTasks(tasks) {
        const $taskList = $('.pandat69-archive-task-list');
        $taskList.empty();
    
        if (tasks.length === 0) {
            $taskList.html('<li class="pandat69-no-tasks">No archived tasks found.</li>');
            return;
        }
    
        tasks.forEach(task => {
            $taskList.append(renderTaskItem(task, true)); // Pass true to indicate these are archived tasks
        });
    
        // Reattach event handlers
        attachTaskEventHandlers();
        
        // Show the task list
        $taskList.show();
    }
    
    function archiveTask(taskId) {
        if (!confirm('Are you sure you want to archive this task?')) {
            return;
        }
    
        toggleArchiveTask(taskId, true);
    }
    
    function unarchiveTask(taskId) {
        toggleArchiveTask(taskId, false);
    }
    
    function toggleArchiveTask(taskId, archive = true) {
        $.ajax({
            url: pandat69_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'pandat69_toggle_archive_task',
                nonce: pandat69_ajax_object.nonce,
                task_id: taskId,
                archive: archive ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    // Reload appropriate task list based on current tab
                    if ($('.pandat69-tab-archive').hasClass('active')) {
                        loadArchivedTasks();
                    } else {
                        loadTasks();
                    }
                    
                    // Close any open task details
                    $('.pandat69-task-details-expandable').slideUp(function() {
                        $(this).remove();
                    });
                    
                    // Also reload calendar views if needed
                    if ($('.pandat69-tab-week').hasClass('active')) {
                        loadWeekTasks();
                    } else if ($('.pandat69-tab-month').hasClass('active')) {
                        loadMonthTasks();
                    }
                } else {
                    console.error('Error toggling archive status:', response.data?.message || 'Unknown error');
                    alert('Failed to update archive status. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Failed to update archive status. Please try again.');
            }
        });
    }

    function renderTasks(tasks) {
        const $taskList = $('.pandat69-task-list').first(); // Only target the main task list
        $taskList.empty();
    
        if (tasks.length === 0) {
            $taskList.html('<li class="pandat69-no-tasks">No tasks found.</li>');
            return;
        }
    
        // Create a map of tasks by ID for quick lookup
        const taskMap = {};
        tasks.forEach(task => {
            taskMap[task.id] = task;
            task.children = []; // Initialize children array
        });
    
        // Identify parent-child relationships
        const mainTasks = [];
        tasks.forEach(task => {
            if (task.parent_task_id && taskMap[task.parent_task_id]) {
                // This is a subtask with a valid parent
                taskMap[task.parent_task_id].children.push(task);
            } else {
                // This is a main task or orphaned subtask (parent not in result set)
                mainTasks.push(task);
            }
        });
    
        // Render main tasks and their children
        mainTasks.forEach(task => {
            // Render the main task
            $taskList.append(renderTaskItem(task));
            
            // Render its children
            if (task.children && task.children.length > 0) {
                task.children.forEach(childTask => {
                    $taskList.append(renderTaskItem(childTask));
                });
            }
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
        // Edit task button (for list items and detail views)
        $('.pandat69-edit-task-btn, .pandat69-edit-task-detail-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id') || $(this).closest('.pandat69-task-item').data('task-id');
            if (taskId) {
                editTask(taskId);
            }
        });
    
        // Delete task button (for list items and detail views)
        $('.pandat69-delete-task-btn, .pandat69-delete-task-detail-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id') || $(this).closest('.pandat69-task-item').data('task-id');
            if (taskId) {
                deleteTask(taskId);
            }
        });
    
        // Archive task button (for list items and detail views)
        $('.pandat69-archive-task-btn, .pandat69-archive-task-detail-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id') || $(this).closest('.pandat69-task-item').data('task-id');
            if (taskId) {
                archiveTask(taskId);
            }
        });
        
        // Unarchive task button (for list items and detail views)
        $('.pandat69-unarchive-task-btn, .pandat69-unarchive-task-detail-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id') || $(this).closest('.pandat69-task-item').data('task-id');
            if (taskId) {
                unarchiveTask(taskId);
            }
        });
        
        // Add subtask button (for list items and detail views)
        $('.pandat69-add-subtask-btn, .pandat69-add-subtask-detail-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id') || $(this).closest('.pandat69-task-item').data('task-id');
            if (taskId) {
                addSubtask(taskId);
            }
        });
    
        // Show comments button 
        $('.pandat69-show-comments-btn').off('click').on('click', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id');
            if (taskId) {
                showTaskDetails(taskId); // This shows comments in an expandable section
            }
        });
        
        // Read more link for description
        $('.pandat69-read-more').off('click').on('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            const $this = $(this);
            const $descContainer = $this.closest('.pandat69-description-preview');
            const $fullDescription = $descContainer.find('.pandat69-full-description');
            const $truncatedText = $descContainer.find('.pandat69-description-text');
            
            if ($fullDescription.is(':visible')) {
                $fullDescription.hide();
                $truncatedText.show();
                $this.text('Read more');
            } else {
                $fullDescription.show();
                $truncatedText.hide();
                $this.text('Show less');
            }
        });
    
        // Task item click (for list view items, to select/highlight)
        // Use a more specific selector if this causes issues with calendar task items
        $('.pandat69-tab-all .pandat69-task-list .pandat69-task-item, .pandat69-tab-archive .pandat69-archive-task-list .pandat69-task-item').off('click.taskHighlight').on('click.taskHighlight', function(event) {
            // Prevent triggering if a button inside the task item was clicked
            if ($(event.target).closest('button, a, .pandat69-task-status, .pandat69-read-more').length) {
                return;
            }
            $(this).toggleClass('pandat69-task-selected');
        });

        // Week Calendar View Task Bar Click
        $(document).off('click.weekCalendarTask').on('click.weekCalendarTask', '.pandat69-task-bar', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id');
            if (taskId) {
                openTaskBarModal(taskId);
            }
        });

        // Month Calendar View Task Bar Click
        $(document).off('click.monthCalendarTask').on('click.monthCalendarTask', '.pandat69-month-spanning-task', function(e) {
            e.stopPropagation();
            const taskId = $(this).data('task-id');
            if (taskId) {
                openTaskBarModal(taskId);
            }
        });
        
        // Handle "more tasks" click - (currently just logs, can be expanded)
        $(document).off('click.monthMoreTasks').on('click.monthMoreTasks', '.pandat69-month-more-tasks', function(e) {
            e.stopPropagation();
            // Could implement a day detail modal here
            console.log('Show more tasks for this day (feature can be expanded)');
        });
    }

    function addSubtask(parentTaskId) {
        // Reset form first
        resetTaskForm();
        
        // Load potential parent tasks and then set the parent task ID and open modal
        loadPotentialParentTasks(0, function() {
            // Set the parent task ID
            $('#pandat69-parent-task').val(parentTaskId);
            
            // Update modal title
            $('.pandat69-add-task-section .pandat69-expandable-header h3').text('Add Subtask');
            
            // Show the form modal
            const $taskSection = $('.pandat69-add-task-section');
            openModal('Add Subtask', $taskSection);
            
            // Use a short delay to make sure the parent task field is set
            setTimeout(function() {
                // Fix NiceSelect conflicts
                fixSelects();
                
                // Verify the parent task is selected
                if ($('#pandat69-parent-task').val() != parentTaskId) {
                    $('#pandat69-parent-task').val(parentTaskId);
                }
            }, 200);
        });
    }

    // Task CRUD Operations

    function editTask(taskId) {
        // Show form section
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
                    
                    // Show in modal
                    const $taskSection = $('.pandat69-add-task-section');
                    openModal('Edit Task', $taskSection);
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
        detailsContainer.html('<div class="pandat69-loading">Loading comments...</div>');
    
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
                    renderTaskComments(response.data.task, detailsContainer);
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

    // Handle "Read more" links for description
    $(document).on('click', '.pandat69-read-more', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $this = $(this);
        const $descContainer = $this.closest('.pandat69-task-description');
        const $fullDescription = $descContainer.find('.pandat69-full-description');
        const $truncatedText = $descContainer.find('.pandat69-description-text');
        
        if ($fullDescription.is(':visible')) {
            // Hide full description, show truncated
            $fullDescription.hide();
            $truncatedText.show();
            $this.text('Read more');
        } else {
            // Show full description, hide truncated
            $fullDescription.show();
            $truncatedText.hide();
            $this.text('Show less');
        }
    });

    // Handle Show Comments button
    $(document).on('click', '.pandat69-show-comments-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const taskId = $(this).data('task-id');
        showTaskDetails(taskId);
    });

    function renderTaskComments(task, container) {
        // Check if we're in archive tab directly via the task DOM context
        const isInArchiveTab = container.closest('.pandat69-tab-archive').length > 0;
        
        // Create HTML for comments section
        let html = `
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
        
        // Add event handler for adding comments
        container.find('.pandat69-add-comment-btn').on('click', function() {
            addComment(task.id, container);
        });
    }

    function renderTaskDetails(task, container) {
        // Check if we're in archive tab directly via the task DOM context
        const isInArchiveTab = container.closest('.pandat69-tab-archive').length > 0;
        const isSubtask = task.parent_task_id ? true : false;
        
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
                    <span class="pandat69-detail-label">Start Date:</span>
                    <span class="pandat69-detail-value">${task.start_date || 'Not started'}</span>
                </div>`;
                
            // Add completed date if status is done
            if (task.status === 'done' && task.completed_at) {
                html += `
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Completed:</span>
                        <span class="pandat69-detail-value">${task.completed_at}</span>
                    </div>`;
            }
                
            // Different display for deadlines based on how they were set
            if (task.deadline) {
                html += `
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Deadline:</span>
                        <span class="pandat69-detail-value">${task.deadline}${task.deadline_days_after_start ? 
                            ` (${task.deadline_days_after_start} days after start)` : ''}</span>
                    </div>`;
            } else {
                html += `
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Deadline:</span>
                        <span class="pandat69-detail-value">No deadline</span>
                    </div>`;
            }
            
            html += `
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Assigned to:</span>
                        <span class="pandat69-detail-value">${task.assigned_user_names}</span>
                    </div>
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Supervisors:</span>
                        <span class="pandat69-detail-value">${task.supervisor_user_names}</span>
                    </div>`;
                        
            // Add parent task information if this is a subtask
            if (task.parent_task_id && task.parent_task_name) {
                html += `
                    <div class="pandat69-detail-item">
                        <span class="pandat69-detail-label">Parent Task:</span>
                        <span class="pandat69-detail-value">${task.parent_task_name}</span>
                    </div>`;
            }
        
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
                        ${isInArchiveTab ? 
                            `<button type="button" class="pandat69-button pandat69-unarchive-task-detail-btn" data-task-id="${task.id}">Restore from Archive</button>` :
                            `<button type="button" class="pandat69-button pandat69-archive-task-detail-btn" data-task-id="${task.id}">Archive Task</button>`
                        }
                        ${!isSubtask ? 
                            `<button type="button" class="pandat69-button pandat69-add-subtask-detail-btn" data-task-id="${task.id}">Add Subtask</button>` : 
                            ''
                        }
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
        
            // Archive/Unarchive buttons
            container.find('.pandat69-archive-task-detail-btn').on('click', function() {
                archiveTask(task.id);
            });
        
            container.find('.pandat69-unarchive-task-detail-btn').on('click', function() {
                unarchiveTask(task.id);
            });
            
            // Add Subtask button handler - NEW
            container.find('.pandat69-add-subtask-detail-btn').on('click', function() {
                addSubtask(task.id);
            });
    }

    // Helper function to update UI when start date changes
    function updateTaskStartDateUI(taskId, startDate) {
        const taskItem = $('.pandat69-task-item[data-task-id="' + taskId + '"]');
        const metaContainer = taskItem.find('.pandat69-task-item-meta');
        
        // Add or update start date element in task list
        const startDateElement = metaContainer.find('span:contains("Started:")');
        if (startDateElement.length) {
            startDateElement.html('<strong>Started:</strong> ' + startDate);
        } else {
            metaContainer.find('span:contains("Priority:")').after(
                '<span><strong>Started:</strong> ' + startDate + '</span>'
            );
        }
        
        // Update in task details if open
        const detailsContainer = $('.pandat69-task-details-expandable[data-task-id="' + taskId + '"]');
        if (detailsContainer.length) {
            detailsContainer.find('.pandat69-detail-value:contains("Not started")')
                .text(startDate);
        }
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
    
        // Debug to check the taskId value
        console.log('Updating task status for ID:', taskId);
    
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
                    console.log('Status update response:', response.data);
                    
                    // Update task item status in the list
                    const taskItem = $('.pandat69-task-item[data-task-id="' + taskId + '"]');
                    taskItem.find('.pandat69-task-status')
                        .removeClass()
                        .addClass('pandat69-task-status pandat69-status-' + newStatus)
                        .text(newStatus.replace('-', ' '));
    
                    // Update status in the details view
                    const detailsContainer = $('.pandat69-task-details-expandable[data-task-id="' + taskId + '"]');
                    if (detailsContainer.length) {
                        detailsContainer.find('.pandat69-detail-value.pandat69-task-status')
                            .removeClass()
                            .addClass('pandat69-detail-value pandat69-task-status pandat69-status-' + newStatus)
                            .text(newStatus.replace('-', ' '));
                    }
    
                    // Update button data attribute
                    button.data('current-status', newStatus);
    
                    // If a start date was automatically set (when moving to in-progress)
                    if (response.data.start_date) {
                        // Update start date in task list item
                        const metaContainer = taskItem.find('.pandat69-task-item-meta');
                        const startDateElement = metaContainer.find('span:contains("Started:")');
                        
                        if (startDateElement.length) {
                            // Update existing start date element
                            startDateElement.html(`<strong>Started:</strong> ${response.data.start_date}`);
                        } else {
                            // Add start date element after priority
                            metaContainer.find('span:contains("Priority:")').after(
                                `<span><strong>Started:</strong> ${response.data.start_date}</span>`
                            );
                        }
                        
                        // Update start date in details view if open
                        if (detailsContainer.length) {
                            const startDateDetailElement = detailsContainer.find('.pandat69-detail-label:contains("Start Date:")').next();
                            if (startDateDetailElement.length) {
                                startDateDetailElement.text(response.data.start_date);
                            }
                        }
                        
                        // If deadline was also calculated from days-after-start, update deadline display
                        if (response.data.deadline) {
                            // Update deadline in task list item
                            const deadlineElement = metaContainer.find('span:contains("Deadline:")');
                            const deadlineText = response.data.deadline_days_after_start ? 
                                `${response.data.deadline} (${response.data.deadline_days_after_start} days after start)` : 
                                response.data.deadline;
                                
                            if (deadlineElement.length) {
                                // Update existing deadline element
                                deadlineElement.html(`<strong>Deadline:</strong> ${deadlineText}`);
                            } else {
                                // Add deadline element
                                metaContainer.find('span:contains("Started:")').after(
                                    `<span><strong>Deadline:</strong> ${deadlineText}</span>`
                                );
                            }
                            
                            // Update deadline in details view if open
                            if (detailsContainer.length) {
                                const deadlineDetailElement = detailsContainer.find('.pandat69-detail-label:contains("Deadline:")').next();
                                if (deadlineDetailElement.length) {
                                    deadlineDetailElement.html(deadlineText);
                                }
                            }
                        }
                    }
    
                    // Handle completed_at field when status changes to/from 'done'
                    const metaContainer = taskItem.find('.pandat69-task-item-meta');
                    if (newStatus === 'done') {
                        // Get the completion date from the response
                        const completionDate = response.data.completed_at;
                        if (completionDate) {
                            // Add or update completed date in task list
                            const completedElement = metaContainer.find('span:contains("Completed:")');
                            if (completedElement.length) {
                                // Update existing completed element
                                completedElement.html(`<strong>Completed:</strong> ${completionDate}`);
                            } else {
                                // Find suitable place to insert - after deadline, or after start date, or after priority
                                const deadlineEl = metaContainer.find('span:contains("Deadline:")');
                                const startedEl = metaContainer.find('span:contains("Started:")');
                                const priorityEl = metaContainer.find('span:contains("Priority:")');
                                
                                const targetEl = deadlineEl.length ? deadlineEl : 
                                               (startedEl.length ? startedEl : priorityEl);
                                                
                                targetEl.after(`<span><strong>Completed:</strong> ${completionDate}</span>`);
                            }
                            
                            // Add completed date to details view if open
                            if (detailsContainer.length) {
                                const completedDetailElement = detailsContainer.find('.pandat69-detail-label:contains("Completed:")');
                                if (completedDetailElement.length) {
                                    completedDetailElement.next().text(completionDate);
                                } else {
                                    // Add completed date element in the details view - after start date
                                    const startDateDetailItem = detailsContainer.find('.pandat69-detail-label:contains("Start Date:")').closest('.pandat69-detail-item');
                                    startDateDetailItem.after(`
                                        <div class="pandat69-detail-item">
                                            <span class="pandat69-detail-label">Completed:</span>
                                            <span class="pandat69-detail-value">${completionDate}</span>
                                        </div>
                                    `);
                                }
                            }
                        }
                    } 
                    // Remove completion date when status changes from done to something else
                    else if (newStatus !== 'done') {
                        // Remove completed date from task list
                        metaContainer.find('span:contains("Completed:")').remove();
                        
                        // Remove completed date from details view if open
                        if (detailsContainer.length) {
                            detailsContainer.find('.pandat69-detail-label:contains("Completed:")').closest('.pandat69-detail-item').remove();
                        }
                    }
    
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
        $form.find('#pandat69-task-deadline').val('');
        $form.find('#pandat69-task-start-date').val(''); // Reset start date
        $form.find('#pandat69-task-assigned').val('');
        $form.find('.pandat69-selected-users-container').empty();
        $form.find('#pandat69-task-supervisor').val('');
        $form.find('.pandat69-supervisor-container .pandat69-selected-users-container').empty();
    
        // Reset deadline type and days input
        $('input[name="deadline_type"][value="date"]').prop('checked', true);
        $('#pandat69-task-deadline-days').val('7'); // Default to 7 days
        toggleDeadlineInputs(); // Show/hide the appropriate inputs
        
        // Reset TinyMCE if active
        if (typeof tinymce !== 'undefined' && tinymce.get('pandat69-task-description')) {
            tinymce.get('pandat69-task-description').setContent('');
        }
    
        // Reset notification fields
        $('#pandat69-notify-deadline').prop('checked', false);
        $('#pandat69-notify-days-before').val(3); // Set to default value
        $('.pandat69-deadline-notification-days').hide();
    
        // Load potential parent tasks (for the dropdown)
        loadPotentialParentTasks();
        // Reset parent task dropdown to "None"
        $('#pandat69-parent-task').val('');
    
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
        $form.find('#pandat69-task-start-date').val(task.start_date || ''); // Fill start date
        
        // Set deadline fields based on whether we're using days after start
        if (task.deadline_days_after_start) {
            $('input[name="deadline_type"][value="days_after"]').prop('checked', true);
            $('#pandat69-task-deadline-days').val(task.deadline_days_after_start);
            $('#pandat69-task-deadline').val(task.deadline || ''); // Still show the calculated date
        } else {
            $('input[name="deadline_type"][value="date"]').prop('checked', true);
            $('#pandat69-task-deadline').val(task.deadline || '');
        }
        
        // Toggle deadline inputs based on selection
        toggleDeadlineInputs();
        
        // Set description in TinyMCE or textarea
        if (typeof tinymce !== 'undefined' && tinymce.get('pandat69-task-description')) {
            tinymce.get('pandat69-task-description').setContent(task.description || '');
        } else {
            $form.find('#pandat69-task-description').val(task.description || '');
        }
        
        // --- ASSIGNEES ---
        // Set assigned users
        const userIds = task.assigned_user_ids || [];
        $form.find('#pandat69-task-assigned').val(userIds.join(','));
        
        // Clear and rebuild selected users UI
        const $selectedUsers = $form.find('.pandat69-selected-users-container').first(); // Target the assignees container
        $selectedUsers.empty();
        
        if (task.assigned_user_ids && task.assigned_user_ids.length > 0) {
            const userNames = task.assigned_user_names.split(', ');
            for (let i = 0; i < task.assigned_user_ids.length; i++) {
                if (i < userNames.length) {
                    addSelectedUserUI($selectedUsers, task.assigned_user_ids[i], userNames[i]);
                }
            }
        }
        
        // --- SUPERVISORS ---
        // Set supervisor users
        const supervisorIds = task.supervisor_user_ids || [];
        $form.find('#pandat69-task-supervisor').val(supervisorIds.join(','));
        
        // Clear and rebuild selected supervisors UI
        const $selectedSupervisors = $form.find('.pandat69-supervisor-container .pandat69-selected-users-container');
        $selectedSupervisors.empty();
        
        if (task.supervisor_user_ids && task.supervisor_user_ids.length > 0) {
            const supervisorNames = task.supervisor_user_names.split(', ');
            for (let i = 0; i < task.supervisor_user_ids.length; i++) {
                if (i < supervisorNames.length) {
                    addSelectedUserUI($selectedSupervisors, task.supervisor_user_ids[i], supervisorNames[i]);
                }
            }
        }
        
        // Set notification fields
        $('#pandat69-notify-deadline').prop('checked', task.notify_deadline == 1);
        $('#pandat69-notify-days-before').val(task.notify_days_before || 3); // Default to 3 if null/undefined
        
        // Show/hide days field based on checkbox state from task data
        if(task.notify_deadline == 1) {
            $('.pandat69-deadline-notification-days').show();
        } else {
            $('.pandat69-deadline-notification-days').hide();
        }
        
        // --- PARENT TASK ---
        // Load potential parent tasks and set selected value
        loadPotentialParentTasks(task.id);
        
        // Wait a moment for the parent task options to load, then set the selected value
        setTimeout(function() {
            $form.find('#pandat69-parent-task').val(task.parent_task_id || '');
            
            // Fix NiceSelect conflicts
            fixSelects();
            
            // Additional protection for select values
            setTimeout(function() {
                // Ensure the value is still set (NiceSelect might have changed it)
                $form.find('#pandat69-task-status').val(task.status);
                $form.find('#pandat69-task-category').val(task.category_id || '');
                $form.find('#pandat69-parent-task').val(task.parent_task_id || '');
                
                // Make sure original selects are visible
                $('.pandat69-select').each(function() {
                    const $niceSelect = $(this).next('.nice-select');
                    if ($niceSelect.length) {
                        $niceSelect.hide();
                        $(this).show();
                    }
                });
            }, 100);
        }, 500); // Small delay to ensure the options are loaded
        
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
    
        // Handle deadline type options
        const deadlineType = $('input[name="deadline_type"]:checked').val();
        let deadline = '';
        let deadline_days_after_start = '';
        
        if (deadlineType === 'days_after') {
            deadline_days_after_start = $('#pandat69-task-deadline-days').val();
            // Leave deadline empty - will be calculated server-side
        } else {
            deadline = $('#pandat69-task-deadline').val();
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
            deadline: deadline,
            deadline_days_after_start: deadline_days_after_start,
            start_date: $form.find('#pandat69-task-start-date').val(),
            assigned_persons: $form.find('#pandat69-task-assigned').val(),
            supervisor_persons: $form.find('#pandat69-task-supervisor').val(),
            notify_deadline: $form.find('#pandat69-notify-deadline').is(':checked') ? 1 : 0,
            notify_days_before: $form.find('#pandat69-notify-days-before').val(),
            parent_task_id: $form.find('#pandat69-parent-task').val()
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
    
                    // Reset form and close modal with slight delay
                    setTimeout(function() {
                        closeModal();
                        resetTaskForm();
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

function setupSupervisorAutocomplete() {
    const $container = $('.pandat69-supervisor-container');
    const $input = $container.find('.pandat69-user-search-input');
    const $suggestions = $container.find('.pandat69-user-suggestions');
    const $selectedContainer = $container.find('.pandat69-selected-users-container');
    const $hiddenInput = $('#pandat69-task-supervisor');

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
        if (!$(e.target).closest('.pandat69-supervisor-container').length) {
            $suggestions.hide();
        }
    });

    // Initialize remove user handlers
    $selectedContainer.on('click', '.pandat69-remove-user', function() {
        const userId = $(this).parent().data('user-id');
        removeSelectedUser(userId, $selectedContainer, $hiddenInput);
    });
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
        } else {
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
    
        // Fix NiceSelect conflicts
        fixSelects();
        
        // Additional protection for the category select
        setTimeout(function() {
            // If there's a NiceSelect next to our category select, remove or hide it
            const $niceSelect = $categorySelect.next('.nice-select');
            if ($niceSelect.length) {
                // Try to destroy NiceSelect if the function exists
                if (typeof $.fn.niceSelect !== 'undefined') {
                    try {
                        $categorySelect.niceSelect('destroy');
                    } catch(e) {
                        console.log('Could not destroy NiceSelect on category select', e);
                    }
                }
                
                // Either way, remove the NiceSelect element
                $niceSelect.remove();
                
                // Make sure our select is visible
                $categorySelect.css({
                    'display': 'block',
                    'visibility': 'visible',
                    'opacity': '1'
                });
            }
        }, 100);
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

    function setupUserAutocomplete() {
        const $input = $('#pandat69-task-assigned-search');
        const $container = $input.closest('.pandat69-user-autocomplete-container');
        const $suggestions = $container.find('.pandat69-user-suggestions');
        const $selectedContainer = $container.find('.pandat69-selected-users-container');
        const $hiddenInput = $('#pandat69-task-assigned');
        
        let searchTimeout;
        
        $input.on('input', function() {
            const searchTerm = $(this).val().trim();
            clearTimeout(searchTimeout);
            
            if (searchTerm.length < 2) {
                $suggestions.hide();
                return;
            }
            
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
        
        // Use namespaced event to avoid conflicts
        $(document).off('click.assigneeAutocomplete').on('click.assigneeAutocomplete', function(e) {
            if (!$(e.target).closest($container).length) {
                $suggestions.hide();
            }
        });
        
        // Initialize remove user handlers
        $selectedContainer.on('click', '.pandat69-remove-user', function() {
            const userId = $(this).parent().data('user-id');
            removeSelectedUser(userId, $selectedContainer, $hiddenInput);
        });
    }
    
    function setupSupervisorAutocomplete() {
        const $input = $('#pandat69-task-supervisor-search');
        const $container = $input.closest('.pandat69-user-autocomplete-container');
        const $suggestions = $container.find('.pandat69-user-suggestions');
        const $selectedContainer = $container.find('.pandat69-selected-users-container');
        const $hiddenInput = $('#pandat69-task-supervisor');
        
        let searchTimeout;
        
        $input.on('input', function() {
            const searchTerm = $(this).val().trim();
            clearTimeout(searchTimeout);
            
            if (searchTerm.length < 2) {
                $suggestions.hide();
                return;
            }
            
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
        
        // Use namespaced event to avoid conflicts
        $(document).off('click.supervisorAutocomplete').on('click.supervisorAutocomplete', function(e) {
            if (!$(e.target).closest($container).length) {
                $suggestions.hide();
            }
        });
        
        // Initialize remove user handlers
        $selectedContainer.on('click', '.pandat69-remove-user', function() {
            const userId = $(this).parent().data('user-id');
            removeSelectedUser(userId, $selectedContainer, $hiddenInput);
        });
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
    
        function setupUserAutocomplete() {
            // Target specifically the first (assignee) autocomplete container
            const $input = $('#pandat69-task-assigned-search');
            const $container = $input.closest('.pandat69-user-autocomplete-container');
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
        
            // Hide suggestions when clicking outside - use namespaced handler 
            $(document).off('click.assigneeAutocomplete').on('click.assigneeAutocomplete', function(e) {
                if (!$(e.target).closest($container).length) {
                    $suggestions.hide();
                }
            });
        
            // Initialize remove user handlers for assignees only
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

        function loadPotentialParentTasks(taskId = 0, callback = null) {
            const $container = $('.pandat69-container');
            const boardName = $container.data('board-name');
            const $parentTaskSelect = $('#pandat69-parent-task');
            
            $parentTaskSelect.html('<option value="">-- None (Main Task) --</option>');
            $parentTaskSelect.prop('disabled', true);
            
            $.ajax({
                url: pandat69_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'pandat69_fetch_potential_parent_tasks',
                    nonce: pandat69_ajax_object.nonce,
                    board_name: boardName,
                    current_task_id: taskId
                },
                success: function(response) {
                    if (response.success && response.data.parent_tasks) {
                        response.data.parent_tasks.forEach(function(task) {
                            $parentTaskSelect.append(`<option value="${task.id}">${task.name}</option>`);
                        });
                        
                        // Execute callback if provided
                        if (callback && typeof callback === 'function') {
                            callback();
                        }
                    } else {
                        console.error('Error loading potential parent tasks:', response.data?.message || 'Unknown error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    // Execute callback even on error to ensure UI is not stuck
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                },
                complete: function() {
                    $parentTaskSelect.prop('disabled', false);
                }
            });
        }   

        function addSelectedUserUI($container, userId, userName) {
            // Create selected user element
            const $selectedUser = $('<div class="pandat69-selected-user" data-user-id="' + userId + '">' + userName + '<span class="pandat69-remove-user">×</span></div>');
        
            // Add to container
            $container.append($selectedUser);
            
            // Determine which hidden input to use based on the container
            const isSupervisor = $container.closest('.pandat69-supervisor-container').length > 0;
            const $hiddenInput = isSupervisor ? $('#pandat69-task-supervisor') : $('#pandat69-task-assigned');
        
            // Add remove event handler
            $selectedUser.find('.pandat69-remove-user').on('click', function() {
                removeSelectedUser(userId, $container, $hiddenInput);
            });
        }
    });