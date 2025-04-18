<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Task_Board_Shortcode {

    public function register() {
        add_shortcode( 'task_board', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'board_name' => 'default_board',
            'group_id' => 0, // Add this parameter
            'page_name' => '', // Add this parameter 
        ), $atts, 'task_board' );
    
        $board_name = sanitize_key( $atts['board_name'] );
        $group_id = absint( $atts['group_id'] );
        $page_name = sanitize_title( $atts['page_name'] );
        
        // Removed the construction of $data_attributes variable here
        
        // Get a display name for the board
        $display_name = $this->get_board_display_name($board_name, $group_id);
        
        ob_start();
        ?>
        <div class="tbp-container" 
             id="tbp-container-<?php echo esc_attr($board_name); ?>" 
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
            <div class="tbp-header">
                <h2>Task Board: <?php echo esc_html($display_name); ?></h2>
                <div class="tbp-controls">
                    <button class="tbp-button tbp-add-task-btn">Add New Task</button>
                    <button class="tbp-button tbp-manage-categories-btn">Manage Categories</button>
                </div>
                <div class="tbp-filters">
                    <input type="text" class="tbp-input tbp-search-input" placeholder="Search tasks...">
                    <select class="tbp-select tbp-sort-select">
                        <option value="name_asc">Sort by Name (A-Z)</option>
                        <option value="name_desc">Sort by Name (Z-A)</option>
                        <option value="priority_asc">Sort by Priority (Low-High)</option>
                        <option value="priority_desc">Sort by Priority (High-Low)</option>
                        <option value="deadline_asc">Sort by Deadline (Soonest)</option>
                        <option value="deadline_desc">Sort by Deadline (Latest)</option>
                        <option value="status_asc">Sort by Status (A-Z)</option>
                        <option value="status_desc">Sort by Status (Z-A)</option>
                    </select>
                     <select class="tbp-select tbp-status-filter-select">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="in-progress">In Progress</option>
                        <option value="done">Done</option>
                    </select>
                </div>
            </div>

            <!-- Add Task Expandable Section -->
            <div class="tbp-expandable-section tbp-add-task-section" style="display: none;">
                <div class="tbp-expandable-header">
                    <h3>Add New Task</h3>
                    <button type="button" class="tbp-icon-button tbp-close-expandable">×</button>
                </div>
                <div class="tbp-expandable-content">
                    <form class="tbp-form tbp-task-form">
                        <input type="hidden" id="tbp-task-id" name="task_id" value="">
                        <input type="hidden" id="tbp-board-name" name="board_name" value="<?php echo esc_attr($board_name); ?>">

                        <div class="tbp-form-field">
                            <label for="tbp-task-name">Task Name:</label>
                            <input type="text" id="tbp-task-name" name="name" class="tbp-input" required>
                        </div>

                         <div class="tbp-form-field">
                            <label for="tbp-task-description">Description:</label>
                            <textarea id="tbp-task-description" name="description" class="tbp-input tbp-tinymce-editor" rows="6"></textarea>
                        </div>

                        <div class="tbp-form-row">
                            <div class="tbp-form-field tbp-form-field-half">
                                <label for="tbp-task-status">Status:</label>
                                <select id="tbp-task-status" name="status" class="tbp-select">
                                    <option value="pending">Pending</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="done">Done</option>
                                </select>
                            </div>
                             <div class="tbp-form-field tbp-form-field-half">
                                <label for="tbp-task-priority">Priority (1-10):</label>
                                <input type="number" id="tbp-task-priority" name="priority" class="tbp-input" min="1" max="10" value="5" required>
                             </div>
                        </div>

                        <div class="tbp-form-row">
                            <div class="tbp-form-field tbp-form-field-half">
                                <label for="tbp-task-category">Category:</label>
                                <div class="tbp-category-input-group">
                                    <select id="tbp-task-category" name="category_id" class="tbp-select">
                                        <option value="">-- Select Category --</option>
                                        <!-- Categories loaded by JS -->
                                    </select>
                                    <button type="button" class="tbp-icon-button tbp-add-category-inline-btn" title="Add New Category">+</button>
                                </div>
                                <!-- Inline category form - hidden by default -->
                                <div class="tbp-inline-category-form" style="display: none;">
                                    <div class="tbp-inline-form-row">
                                        <input type="text" class="tbp-input tbp-new-category-name-inline" placeholder="New category name">
                                        <button type="button" class="tbp-button tbp-save-category-inline-btn">Add</button>
                                        <button type="button" class="tbp-button tbp-cancel-category-inline-btn">Cancel</button>
                                    </div>
                                    <div class="tbp-inline-form-message" style="display: none;"></div>
                                </div>
                            </div>
                            <div class="tbp-form-field tbp-form-field-half">
                                <label for="tbp-task-deadline">Deadline:</label>
                                <input type="text" id="tbp-task-deadline" name="deadline" class="tbp-input tbp-datepicker" placeholder="YYYY-MM-DD">
                            </div>
                        </div>

                        <div class="tbp-form-field">
                            <label for="tbp-task-assigned-search">Assign Persons:</label>
                            <div class="tbp-user-autocomplete-container">
                                <input type="text" id="tbp-task-assigned-search" class="tbp-input tbp-user-search-input" placeholder="Type to search users...">
                                <div class="tbp-user-suggestions" style="display: none;"></div>
                                <div class="tbp-selected-users-container"></div>
                                <!-- Hidden field to store the actual user IDs -->
                                <input type="hidden" id="tbp-task-assigned" name="assigned_persons" value="">
                            </div>
                        </div>

                        <div class="tbp-form-actions">
                            <button type="submit" class="tbp-button tbp-submit-task-btn">Save Task</button>
                            <button type="button" class="tbp-button tbp-cancel-task-btn">Cancel</button>
                            <div class="tbp-form-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Categories Management Expandable Section -->
            <div class="tbp-expandable-section tbp-categories-section" style="display: none;">
                <div class="tbp-expandable-header">
                    <h3>Manage Categories</h3>
                    <button type="button" class="tbp-icon-button tbp-close-expandable">×</button>
                </div>
                <div class="tbp-expandable-content">
                    <div class="tbp-category-list-container">
                        <ul class="tbp-category-list">
                            <!-- Categories loaded by JS -->
                        </ul>
                    </div>
                    <form class="tbp-form tbp-add-category-form">
                        <div class="tbp-form-field">
                            <label for="tbp-new-category-name">New Category Name:</label>
                            <input type="text" id="tbp-new-category-name" name="name" class="tbp-input" required>
                        </div>
                        <div class="tbp-form-actions">
                            <button type="submit" class="tbp-button tbp-add-category-btn">Add Category</button>
                            <div class="tbp-form-message tbp-category-form-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="tbp-task-list-container">
                <div class="tbp-loading" style="display: none;">Loading...</div>
                <ul class="tbp-task-list">
                    <!-- Tasks will be loaded here by JavaScript -->
                </ul>
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