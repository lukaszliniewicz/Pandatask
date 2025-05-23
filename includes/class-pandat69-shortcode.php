<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pandat69_Shortcode {

    public function register() {
        add_shortcode( 'task_board', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'board_name' => 'default_board',
            'group_id' => 0,
            'page_name' => '',
        ), $atts, 'task_board' );
    
        $board_name = sanitize_key( $atts['board_name'] );
        $group_id = absint( $atts['group_id'] );
        $page_name = sanitize_title( $atts['page_name'] );
        
        // Get a display name for the board
        $display_name = $this->get_board_display_name($board_name, $group_id);
        
        ob_start();
        ?>
        <div class="pandat69-container" 
             id="pandat69-container-<?php echo esc_attr($board_name); ?>" 
             data-board-name="<?php echo esc_attr($board_name); ?>"
             <?php 
             // Output group_id attribute only if it exists
             if ($group_id > 0) { 
                 echo ' data-group-id="' . esc_attr($group_id) . '"'; 
             } 
             // Output page_name attribute only if it exists
             if (!empty($page_name)) { 
                 echo ' data-page-name="' . esc_attr($page_name) . '"'; 
             } 
             ?>>
            <div class="pandat69-header">
                <h2>Task Board: <?php echo esc_html($display_name); ?></h2>
            </div>
            
            <div class="pandat69-tabs">
                <ul class="pandat69-tab-navigation">
                    <li class="pandat69-tab-item active" data-tab="all">Task List</li>
                    <li class="pandat69-tab-item" data-tab="week">Week Overview</li>
                    <li class="pandat69-tab-item" data-tab="month">Month Overview</li>
                    <li class="pandat69-tab-item" data-tab="archive">Archive</li>
                </ul>
                
                <!-- "All" tab - existing content -->
                <div class="pandat69-tab-content pandat69-tab-all active">
                <div class="pandat69-controls">
                    <button class="pandat69-button pandat69-add-task-btn">Add New Task</button>
                    <button class="pandat69-button pandat69-manage-categories-btn">Manage Categories</button>
                    <div class="pandat69-toggle-container">
                        <label class="pandat69-switch">
                            <input type="checkbox" id="pandat69-my-tasks-toggle">
                            <span class="pandat69-slider pandat69-round"></span>
                        </label>
                        <span class="pandat69-toggle-label">Only My Tasks</span>
                    </div>
                </div>
                    <div class="pandat69-filters">
                        <input type="text" class="pandat69-input pandat69-search-input" placeholder="Search tasks...">
                        <select class="pandat69-select pandat69-sort-select">
                            <option value="name_asc">Sort by Name (A-Z)</option>
                            <option value="name_desc">Sort by Name (Z-A)</option>
                            <option value="priority_asc">Sort by Priority (Low-High)</option>
                            <option value="priority_desc">Sort by Priority (High-Low)</option>
                            <option value="deadline_asc">Sort by Deadline (Soonest)</option>
                            <option value="deadline_desc">Sort by Deadline (Latest)</option>
                            <option value="status_asc">Sort by Status (A-Z)</option>
                            <option value="status_desc">Sort by Status (Z-A)</option>
                        </select>
                        <select class="pandat69-select pandat69-status-filter-select">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="in-progress">In Progress</option>
                            <option value="done">Done</option>
                        </select>
                    </div>
                    <div class="pandat69-task-list-container">
                        <div class="pandat69-loading" style="display: none;">Loading...</div>
                        <ul class="pandat69-task-list">
                            <!-- Tasks will be loaded here by JavaScript -->
                        </ul>
                    </div>
                </div>
                
                <!-- Week overview tab -->
                <div class="pandat69-tab-content pandat69-tab-week">
                    <div class="pandat69-date-selector">
                        <button class="pandat69-button pandat69-prev-week">◀ Previous Week</button>
                        <span class="pandat69-current-week-display"></span>
                        <button class="pandat69-button pandat69-next-week">Next Week ▶</button>
                    </div>
                    <div class="pandat69-week-task-container">
                        <!-- Week tasks will be loaded here -->
                    </div>
                </div>
                
                <!-- Month overview tab -->
                <div class="pandat69-tab-content pandat69-tab-month">
                    <div class="pandat69-date-selector">
                        <button class="pandat69-button pandat69-prev-month">◀ Previous Month</button>
                        <span class="pandat69-current-month-display"></span>
                        <button class="pandat69-button pandat69-next-month">Next Month ▶</button>
                    </div>
                    <div class="pandat69-month-task-container">
                        <!-- Month tasks will be loaded here -->
                    </div>
                </div>
                <!-- Archive tab -->
                <div class="pandat69-tab-content pandat69-tab-archive">
                    <div class="pandat69-controls">
                        <p class="pandat69-archive-info">Archived tasks are stored here. They can be restored or permanently deleted.</p>
                    </div>
                    <div class="pandat69-archive-container">
                        <div class="pandat69-loading" style="display: none;">Loading archived tasks...</div>
                        <ul class="pandat69-archive-task-list">
                            <!-- Archived tasks will be loaded here by JavaScript -->
                        </ul>
                    </div>
                </div>
            </div>
    
            <!-- Add Task Expandable Section -->
            <div class="pandat69-expandable-section pandat69-add-task-section" style="display: none;">
                <div class="pandat69-expandable-header">
                    <h3>Add New Task</h3>
                    <button type="button" class="pandat69-icon-button pandat69-close-expandable">×</button>
                </div>
                <div class="pandat69-expandable-content">
                    <form class="pandat69-form pandat69-task-form">
                        <input type="hidden" id="pandat69-task-id" name="task_id" value="">
                        <input type="hidden" id="pandat69-board-name" name="board_name" value="<?php echo esc_attr($board_name); ?>">
    
                        <div class="pandat69-form-field">
                            <label for="pandat69-task-name">Task Name:</label>
                            <input type="text" id="pandat69-task-name" name="name" class="pandat69-input" required>
                        </div>
    
                         <div class="pandat69-form-field">
                            <label for="pandat69-task-description">Description:</label>
                            <textarea id="pandat69-task-description" name="description" class="pandat69-input pandat69-tinymce-editor" rows="6"></textarea>
                        </div>
    
                        <div class="pandat69-form-row">
                            <div class="pandat69-form-field pandat69-form-field-half">
                                <label for="pandat69-task-status">Status:</label>
                                <select id="pandat69-task-status" name="status" class="pandat69-select">
                                    <option value="pending">Pending</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="done">Done</option>
                                </select>
                            </div>
                            <div class="pandat69-form-field pandat69-form-field-half">
                                <label for="pandat69-task-start-date">Start Date:</label>
                                <input type="text" id="pandat69-task-start-date" name="start_date" class="pandat69-input pandat69-datepicker" placeholder="YYYY-MM-DD">
                                <p class="pandat69-description">Will be set automatically for a pending task when it is set to in-progress later, and if set will chage pending tasks to in-progress on that date.</p>
                            </div>
                             <div class="pandat69-form-field pandat69-form-field-half">
                                <label for="pandat69-task-priority">Priority (1-10):</label>
                                <input type="number" id="pandat69-task-priority" name="priority" class="pandat69-input" min="1" max="10" value="5" required>
                             </div>
                        </div>
    
                        <div class="pandat69-form-row">
                            <div class="pandat69-form-field pandat69-form-field-half">
                                <label for="pandat69-task-category">Category:</label>
                                <div class="pandat69-category-input-group">
                                    <select id="pandat69-task-category" name="category_id" class="pandat69-select">
                                        <option value="">-- Select Category --</option>
                                        <!-- Categories loaded by JS -->
                                    </select>
                                    <button type="button" class="pandat69-icon-button pandat69-add-category-inline-btn" title="Add New Category">+</button>
                                </div>
                                <!-- Inline category form - hidden by default -->
                                <div class="pandat69-inline-category-form" style="display: none;">
                                    <div class="pandat69-inline-form-row">
                                        <input type="text" class="pandat69-input pandat69-new-category-name-inline" placeholder="New category name">
                                        <button type="button" class="pandat69-button pandat69-save-category-inline-btn">Add</button>
                                        <button type="button" class="pandat69-button pandat69-cancel-category-inline-btn">Cancel</button>
                                    </div>
                                    <div class="pandat69-inline-form-message" style="display: none;"></div>
                                </div>
                            </div>
                            <div class="pandat69-form-field pandat69-form-field-half">
                                <label for="pandat69-parent-task">Parent Task:</label>
                                <select id="pandat69-parent-task" name="parent_task_id" class="pandat69-select">
                                    <option value="">-- None (Main Task) --</option>
                                    <!-- Parent tasks loaded by JS -->
                                </select>
                            </div>
                            <div class="pandat69-form-field pandat69-form-field-half">
                                <label>Deadline Setting:</label>
                                <div class="pandat69-deadline-type-selector">
                                    <label>
                                        <input type="radio" name="deadline_type" value="date" checked> 
                                        Specific date
                                    </label>
                                    <label>
                                        <input type="radio" name="deadline_type" value="days_after"> 
                                        Days after start
                                    </label>
                                </div>
                                
                                <div class="pandat69-deadline-date-input">
                                    <label for="pandat69-task-deadline">Deadline Date:</label>
                                    <input type="text" id="pandat69-task-deadline" name="deadline" class="pandat69-input pandat69-datepicker" placeholder="YYYY-MM-DD">
                                </div>
                                
                                <div class="pandat69-deadline-days-input" style="display: none;">
                                    <label for="pandat69-task-deadline-days">Days after start:</label>
                                    <input type="number" id="pandat69-task-deadline-days" name="deadline_days_after_start" class="pandat69-input" min="1" value="7">
                                </div>
                            </div>
                        </div>
                        <div class="pandat69-form-field">
                            <label for="pandat69-notify-deadline">
                                <input type="checkbox" id="pandat69-notify-deadline" name="notify_deadline" value="1">
                                Enable deadline notifications
                            </label>
                            <div class="pandat69-deadline-notification-days" style="margin-top: 8px; display: none;">
                                <label for="pandat69-notify-days-before">Notify days before deadline:</label>
                                <input type="number" id="pandat69-notify-days-before" name="notify_days_before" class="pandat69-input" min="1" max="30" value="3">
                                <p class="description">Users assigned to this task will be notified this many days before the deadline.</p>
                            </div>
                        </div>    
                        <div class="pandat69-form-field">
                            <label for="pandat69-task-assigned-search">Assign Persons:</label>
                            <div class="pandat69-user-autocomplete-container">
                                <input type="text" id="pandat69-task-assigned-search" class="pandat69-input pandat69-user-search-input" placeholder="Type to search users...">
                                <div class="pandat69-user-suggestions" style="display: none;"></div>
                                <div class="pandat69-selected-users-container"></div>
                                <!-- Hidden field to store the actual user IDs -->
                                <input type="hidden" id="pandat69-task-assigned" name="assigned_persons" value="">
                            </div>
                        </div>
                        <div class="pandat69-form-field pandat69-supervisor-container">
                            <label for="pandat69-task-supervisor-search">Task Supervisors:</label>
                            <div class="pandat69-user-autocomplete-container">
                                <input type="text" id="pandat69-task-supervisor-search" class="pandat69-input pandat69-user-search-input" placeholder="Type to search users...">
                                <div class="pandat69-user-suggestions" style="display: none;"></div>
                                <div class="pandat69-selected-users-container"></div>
                                <!-- Hidden field to store the supervisor IDs -->
                                <input type="hidden" id="pandat69-task-supervisor" name="supervisor_persons" value="">
                            </div>
                        </div>
    
                        <div class="pandat69-form-actions">
                            <button type="submit" class="pandat69-button pandat69-submit-task-btn">Save Task</button>
                            <button type="button" class="pandat69-button pandat69-cancel-task-btn">Cancel</button>
                            <div class="pandat69-form-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </div>
    
            <!-- Categories Management Expandable Section -->
            <div class="pandat69-expandable-section pandat69-categories-section" style="display: none;">
                <div class="pandat69-expandable-header">
                    <h3>Manage Categories</h3>
                    <button type="button" class="pandat69-icon-button pandat69-close-expandable">×</button>
                </div>
                <div class="pandat69-expandable-content">
                    <div class="pandat69-category-list-container">
                        <ul class="pandat69-category-list">
                            <!-- Categories loaded by JS -->
                        </ul>
                    </div>
                    <form class="pandat69-form pandat69-add-category-form">
                        <div class="pandat69-form-field">
                            <label for="pandat69-new-category-name">New Category Name:</label>
                            <input type="text" id="pandat69-new-category-name" name="name" class="pandat69-input" required>
                        </div>
                        <div class="pandat69-form-actions">
                            <button type="submit" class="pandat69-button pandat69-add-category-btn">Add Category</button>
                            <div class="pandat69-form-message pandat69-category-form-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Full-Screen Modal -->
            <div id="pandat69-fullscreen-modal" style="display: none;">
                <div class="pandat69-modal-overlay"></div>
                <div class="pandat69-modal-container">
                    <div class="pandat69-modal-content">
                        <div class="pandat69-modal-close">×</div>
                        <div class="pandat69-modal-body"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get a human-readable display name for the board
     *
     * @param string $board_name The internal board name
     * @param int $group_id The group ID if available
     * @return string The display name for the board
     */
    private function get_board_display_name($board_name, $group_id = 0) {
        // Check if this is a BuddyPress group board
        if (preg_match('/^group_(\d+)$/', $board_name, $matches)) {
            $detected_group_id = intval($matches[1]);
            
            // Use the provided group_id if available, otherwise use the one from the board name
            $group_id = ($group_id > 0) ? $group_id : $detected_group_id;
            
            // Get the group name if BuddyPress is active
            if (function_exists('groups_get_group') && $group_id > 0) {
                $group = groups_get_group($group_id);
                if ($group && !empty($group->name)) {
                    return esc_html($group->name);
                }
            }
        }
        
        // Fallback: Format the board name nicely
        $display_name = str_replace('_', ' ', $board_name);
        return ucwords($display_name);
    }
}