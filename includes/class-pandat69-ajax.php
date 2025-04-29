<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pandat69_Ajax {

    public function register() {
        $ajax_actions = array(
            'fetch_tasks',
            'get_task_details',
            'add_task',
            'update_task',
            'delete_task',
            'fetch_categories',
            'add_category',
            'delete_category',
            'add_comment',
            'fetch_users',
            'quick_update_status',
            'toggle_archive_task',
            'fetch_potential_parent_tasks', 
        );

        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_pandat69_' . $action, array($this, $action));
            // Add wp_ajax_nopriv_ if needed for public actions (not typical for task management)
        }
    }

    private function verify_nonce($action = 'pandat69_ajax_nonce') {
        // Try standard nonce parameter
        if (isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field($_POST['nonce']), $action)) {
            return true;
        }
        
        // More strictly named nonce - better practice going forward
        if (isset($_POST['pandat69_nonce']) && wp_verify_nonce(sanitize_text_field($_POST['pandat69_nonce']), $action)) {
            return true;
        }
        
        // If nonce verification fails, send error response
        wp_send_json_error(array('message' => 'Nonce verification failed.'), 403);
    }

     private function check_permissions() {
         if (!is_user_logged_in()) {
             wp_send_json_error(array('message' => 'You must be logged in.'), 401);
         }
         // Add more capability checks if needed, e.g., based on board or task ownership
         // if (!current_user_can('edit_posts')) { // Example capability
         //    wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
         //}
     }

    // --- AJAX Handlers ---

    public function fetch_tasks() {
        $this->verify_nonce();
        $this->check_permissions();
    
        $board_name = isset($_POST['board_name']) ? sanitize_key($_POST['board_name']) : '';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'name_asc';
        $status_filter = isset($_POST['status_filter']) ? sanitize_text_field($_POST['status_filter']) : '';
        
        // Add date filtering parameters
        $date_filter = isset($_POST['date_filter']) ? sanitize_text_field($_POST['date_filter']) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        
        // Add archived parameter (default to 0 - unarchived)
        $archived = isset($_POST['archived']) ? intval($_POST['archived']) : 0;
        
        // Validate date format if provided (YYYY-MM-DD)
        if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date = ''; // Invalid format
        }
        
        if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $end_date = ''; // Invalid format
        }
    
        list($sort_by, $sort_order) = explode('_', $sort . '_'); // Add '_' to handle cases without order
        $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
    
        if (empty($board_name)) {
            wp_send_json_error(array('message' => 'Board name is required.'));
        }
    
        $tasks = Pandat69_DB::get_tasks($board_name, $search, $sort_by, $sort_order, $status_filter, $date_filter, $start_date, $end_date, $archived);
    
        if (is_array($tasks)) {
            wp_send_json_success(array('tasks' => $tasks));
        } else {
            wp_send_json_error(array('message' => 'Failed to fetch tasks.'));
        }
    }

    public function get_task_details() {
        $this->verify_nonce();
        $this->check_permissions();

        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;

        if (!$task_id) {
            wp_send_json_error(array('message' => 'Invalid Task ID.'));
        }

        $task = Pandat69_DB::get_task($task_id);

        if ($task) {
            // Ensure description is not overly escaped if wp_kses_post was used correctly
             //$task->description = wp_kses_post($task->description); // Re-apply kses? Or just trust DB? Trust DB for now.
            wp_send_json_success(array('task' => $task));
        } else {
            wp_send_json_error(array('message' => 'Task not found.'));
        }
    }

    public function add_task() {
        $this->verify_nonce();
        $this->check_permissions();
    
        // Basic validation
        $required_fields = ['board_name', 'name', 'status', 'priority'];
        foreach($required_fields as $field) {
            if (empty($_POST[$field])) {
                 wp_send_json_error(array('message' => 'Missing required field: ' . $field));
            }
        }
    
        // Process assigned users - handle both array and comma-separated string
        $assigned_persons = [];
        if (!empty($_POST['assigned_persons'])) {
            if (is_array($_POST['assigned_persons'])) {
                $assigned_persons = array_map('absint', $_POST['assigned_persons']);
            } elseif (is_string($_POST['assigned_persons'])) {
                // Handle comma-separated string format from our new autocomplete UI
                $assigned_persons = array_map('absint', explode(',', $_POST['assigned_persons']));
            }
        }
        
        // Process supervisor users - handle both array and comma-separated string
        $supervisor_persons = [];
        if (!empty($_POST['supervisor_persons'])) {
            if (is_array($_POST['supervisor_persons'])) {
                $supervisor_persons = array_map('absint', $_POST['supervisor_persons']);
            } elseif (is_string($_POST['supervisor_persons'])) {
                // Handle comma-separated string format from our autocomplete UI
                $supervisor_persons = array_map('absint', explode(',', $_POST['supervisor_persons']));
            }
        }
    
        $data = array(
            'board_name' => sanitize_key($_POST['board_name']),
            'name' => sanitize_text_field($_POST['name']),
            'description' => isset($_POST['description']) ? wp_kses_post($_POST['description']) : '',
            'status' => sanitize_text_field($_POST['status']),
            'category_id' => isset($_POST['category_id']) ? absint($_POST['category_id']) : null,
            'priority' => absint($_POST['priority']),
            'deadline' => isset($_POST['deadline']) && !empty($_POST['deadline']) ? sanitize_text_field($_POST['deadline']) : null,
            'deadline_days_after_start' => isset($_POST['deadline_days_after_start']) && !empty($_POST['deadline_days_after_start']) 
                ? absint($_POST['deadline_days_after_start']) 
                : null,
            'start_date' => isset($_POST['start_date']) && !empty($_POST['start_date']) 
                ? sanitize_text_field($_POST['start_date']) 
                : null,
            'assigned_persons' => $assigned_persons,
            'supervisor_persons' => $supervisor_persons,
            'notify_deadline' => (isset($_POST['notify_deadline']) && $_POST['notify_deadline'] == 1) ? 1 : 0,
            'notify_days_before' => isset($_POST['notify_days_before']) ? max(1, min(30, absint($_POST['notify_days_before']))) : 3,
            'parent_task_id' => isset($_POST['parent_task_id']) && !empty($_POST['parent_task_id']) ? absint($_POST['parent_task_id']) : null,
        );
    
         // Validate deadline format if provided
         if ($data['deadline'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['deadline'])) {
              wp_send_json_error(array('message' => 'Invalid deadline format. Use YYYY-MM-DD.'));
         }
    
        $task_id = Pandat69_DB::add_task($data);
    
        if ($task_id) {
            // Optionally fetch the newly added task to return full data
            $new_task = Pandat69_DB::get_task($task_id);
            wp_send_json_success(array('message' => 'Task added successfully.', 'task' => $new_task));
        } else {
            wp_send_json_error(array('message' => 'Failed to add task. Check data or logs.'));
        }
    }

    public function update_task() {
        $this->verify_nonce();
        $this->check_permissions();
    
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
    
        if (!$task_id) {
             wp_send_json_error(array('message' => 'Invalid Task ID.'));
        }
    
         // Basic validation
        $required_fields = ['name', 'status', 'priority'];
        foreach($required_fields as $field) {
            if (empty($_POST[$field])) {
                 wp_send_json_error(array('message' => 'Missing required field: ' . $field));
            }
        }
    
        // Process assigned users - handle both array and comma-separated string
        $assigned_persons = [];
        if (!empty($_POST['assigned_persons'])) {
            if (is_array($_POST['assigned_persons'])) {
                $assigned_persons = array_map('absint', $_POST['assigned_persons']);
            } elseif (is_string($_POST['assigned_persons'])) {
                // Handle comma-separated string format from our new autocomplete UI
                $assigned_persons = array_map('absint', explode(',', $_POST['assigned_persons']));
            }
        }
        
        // Process supervisor users - handle both array and comma-separated string
        $supervisor_persons = [];
        if (!empty($_POST['supervisor_persons'])) {
            if (is_array($_POST['supervisor_persons'])) {
                $supervisor_persons = array_map('absint', $_POST['supervisor_persons']);
            } elseif (is_string($_POST['supervisor_persons'])) {
                // Handle comma-separated string format from our autocomplete UI
                $supervisor_persons = array_map('absint', explode(',', $_POST['supervisor_persons']));
            }
        }
    
        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'description' => isset($_POST['description']) ? wp_kses_post($_POST['description']) : '',
            'status' => sanitize_text_field($_POST['status']),
            'category_id' => isset($_POST['category_id']) ? absint($_POST['category_id']) : null,
            'priority' => absint($_POST['priority']),
            'deadline' => isset($_POST['deadline']) && !empty($_POST['deadline']) ? sanitize_text_field($_POST['deadline']) : null,
            'deadline_days_after_start' => isset($_POST['deadline_days_after_start']) && !empty($_POST['deadline_days_after_start']) 
                ? absint($_POST['deadline_days_after_start']) 
                : null,
            'start_date' => isset($_POST['start_date']) && !empty($_POST['start_date']) 
                ? sanitize_text_field($_POST['start_date']) 
                : null,
            'assigned_persons' => $assigned_persons,
            'supervisor_persons' => $supervisor_persons,
            'notify_deadline' => (isset($_POST['notify_deadline']) && $_POST['notify_deadline'] == 1) ? 1 : 0,
            'notify_days_before' => isset($_POST['notify_days_before']) ? max(1, min(30, absint($_POST['notify_days_before']))) : 3,
            'parent_task_id' => isset($_POST['parent_task_id']) && !empty($_POST['parent_task_id']) ? absint($_POST['parent_task_id']) : null,
        );
    
         // Validate deadline format if provided
         if ($data['deadline'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['deadline'])) {
              wp_send_json_error(array('message' => 'Invalid deadline format. Use YYYY-MM-DD.'));
         }
    
        $result = Pandat69_DB::update_task($task_id, $data);
    
        if ($result) {
             // Optionally fetch the updated task to return full data
            $updated_task = Pandat69_DB::get_task($task_id);
            wp_send_json_success(array('message' => 'Task updated successfully.', 'task' => $updated_task));
        } else {
            wp_send_json_error(array('message' => 'Failed to update task.'));
        }
    }

    public function delete_task() {
        $this->verify_nonce();
        $this->check_permissions();

        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;

        if (!$task_id) {
            wp_send_json_error(array('message' => 'Invalid Task ID.'));
        }

         // Add capability check: Can the current user delete this specific task?
         // e.g., check if user is admin or assigned to the task, or task creator etc.

        $result = Pandat69_DB::delete_task($task_id);

        if ($result) {
            wp_send_json_success(array('message' => 'Task deleted successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete task.'));
        }
    }

     public function fetch_categories() {
        $this->verify_nonce();
        $this->check_permissions();

        $board_name = isset($_POST['board_name']) ? sanitize_key($_POST['board_name']) : '';
        if (empty($board_name)) {
            wp_send_json_error(array('message' => 'Board name is required.'));
        }

        $categories = Pandat69_DB::get_categories($board_name);
         if (is_array($categories)) {
            wp_send_json_success(array('categories' => $categories));
        } else {
            wp_send_json_error(array('message' => 'Failed to fetch categories.'));
        }
    }

    public function add_category() {
         $this->verify_nonce();
         $this->check_permissions();

        $board_name = isset($_POST['board_name']) ? sanitize_key($_POST['board_name']) : '';
        $category_name = isset($_POST['name']) ? sanitize_text_field(trim($_POST['name'])) : '';

         if (empty($board_name) || empty($category_name)) {
            wp_send_json_error(array('message' => 'Board name and category name are required.'));
        }

        $category_id = Pandat69_DB::add_category($board_name, $category_name);

        if ($category_id) {
             wp_send_json_success(array('message' => 'Category added successfully.', 'category' => array('id' => $category_id, 'name' => $category_name)));
        } else {
            wp_send_json_error(array('message' => 'Failed to add category. It might already exist or the name is invalid.'));
        }
    }

    public function delete_category() {
        $this->verify_nonce();
        $this->check_permissions();

        $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
        $board_name = isset($_POST['board_name']) ? sanitize_key($_POST['board_name']) : '';

        if (!$category_id || empty($board_name)) {
            wp_send_json_error(array('message' => 'Invalid Category ID or Board Name.'));
        }

         // Add capability check if needed

        $result = Pandat69_DB::delete_category($category_id, $board_name);

        if ($result) {
            wp_send_json_success(array('message' => 'Category deleted successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete category.'));
        }
    }

    public function add_comment() {
        $this->verify_nonce();
        $this->check_permissions();
    
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        $comment_text = isset($_POST['comment_text']) ? wp_kses_post(trim($_POST['comment_text'])) : '';
        $user_id = get_current_user_id();
    
        if (!$task_id || empty($comment_text) || !$user_id) {
             wp_send_json_error(array('message' => 'Missing required comment data.'));
        }
    
        // Process @mentions in format @[Username](123)
        $mentioned_users = array(); // Track mentioned users for notifications
        $comment_text = preg_replace_callback('/@\[(.*?)\]\((\d+)\)/', function($matches) use (&$mentioned_users) {
            $mentioned_user_name = $matches[1];
            $mentioned_user_id = intval($matches[2]);
            
            // Add to our tracking array for notifications
            if (!in_array($mentioned_user_id, $mentioned_users)) {
                $mentioned_users[] = $mentioned_user_id;
            }
            
            // Verify user exists
            $user = get_userdata($mentioned_user_id);
            if ($user) {
                // Create link based on user ID
                if (function_exists('bp_core_get_user_domain')) {
                    // BuddyPress profile link
                    return '<a href="' . bp_core_get_user_domain($mentioned_user_id) . '" class="pandat69-mention">@' . $mentioned_user_name . '</a>';
                } else {
                    // WordPress author page link
                    return '<a href="' . get_author_posts_url($mentioned_user_id) . '" class="pandat69-mention">@' . $mentioned_user_name . '</a>';
                }
            }
            return $matches[0]; // Return original if user not found
        }, $comment_text);
    
        $comment = Pandat69_DB::add_comment($task_id, $user_id, $comment_text);
    
        if ($comment) {
            // Get task to find assigned users for notifications
            $task = Pandat69_DB::get_task($task_id);
            
            // Send email notifications to assigned users
            if (class_exists('Pandat69_Email')) {
                Pandat69_Email::send_comment_notification($task_id, $user_id, $comment_text);
            }
            
            // Add BuddyPress notifications
            if (class_exists('Pandat69_Notifications') && $task) {
                // 1. Send comment notifications to assigned users
                if (!empty($task->assigned_user_ids)) {
                    foreach ($task->assigned_user_ids as $assigned_id) {
                        // Skip sending to the commenter themselves
                        if ($assigned_id != $user_id) {
                            Pandat69_Notifications::add_comment_notification($task_id, $assigned_id, $user_id);
                        }
                    }
                }
                
                // 2. Send mention notifications
                foreach ($mentioned_users as $mentioned_id) {
                    // Skip if mentioning yourself
                    if ($mentioned_id != $user_id) {
                        Pandat69_Notifications::add_mention_notification($task_id, $mentioned_id, $user_id);
                    }
                }
            }
            
            wp_send_json_success(array('message' => 'Comment added successfully.', 'comment' => $comment));
        } else {
            wp_send_json_error(array('message' => 'Failed to add comment.'));
        }
    }

    public function toggle_archive_task() {
        $this->verify_nonce();
        $this->check_permissions();
    
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        $archive = isset($_POST['archive']) ? absint($_POST['archive']) : 1; // Default to archive
    
        if (!$task_id) {
            wp_send_json_error(array('message' => 'Invalid Task ID.'));
        }
    
        // Only update the archived field
        $data = array(
            'archived' => $archive
        );
    
        $result = Pandat69_DB::update_task($task_id, $data);
    
        if ($result) {
            wp_send_json_success(array(
                'message' => $archive ? 'Task archived successfully.' : 'Task restored from archive.'
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to update archive status.'));
        }
    }

     public function fetch_users() {

         $this->verify_nonce(); // Add if needed for security context
         $this->check_permissions(); // Still check login status

        $search = isset($_REQUEST['search']) ? sanitize_text_field($_REQUEST['search']) : ''; // Use REQUEST for flexibility

        if (pandat69_is_buddypress_active()) {
            $users = Pandat69_DB::get_buddypress_users($search);
        } else {
             $users = Pandat69_DB::get_wp_users($search);
        }

        wp_send_json_success(array('users' => $users));
    }

    /**
     * Fetch potential parent tasks for the task form
     */
    public function fetch_potential_parent_tasks() {
        $this->verify_nonce();
        $this->check_permissions();

        $board_name = isset($_POST['board_name']) ? sanitize_key($_POST['board_name']) : '';
        $current_task_id = isset($_POST['current_task_id']) ? absint($_POST['current_task_id']) : 0;

        if (empty($board_name)) {
            wp_send_json_error(array('message' => 'Board name is required.'));
        }

        $parent_tasks = Pandat69_DB::get_potential_parent_tasks($board_name, $current_task_id);

        if (is_array($parent_tasks)) {
            wp_send_json_success(array('parent_tasks' => $parent_tasks));
        } else {
            wp_send_json_error(array('message' => 'Failed to fetch potential parent tasks.'));
        }
    }

    public function quick_update_status() {
        $this->verify_nonce();
        $this->check_permissions();
    
        $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    
        if (!$task_id || empty($status)) {
            wp_send_json_error(array('message' => 'Task ID and status are required.'));
        }
    
        // Get the current task before updating
        $current_task = Pandat69_DB::get_task($task_id);
        if (!$current_task) {
            wp_send_json_error(array('message' => 'Task not found.'));
        }
    
        // Prepare the update data
        $data = array('status' => $status);
        
        // If changing from pending to in-progress, automatically set start date
        $start_date = null;
        $deadline = null;
        $deadline_days_after_start = null;
        
        if ($status === 'in-progress' && $current_task->status === 'pending' && empty($current_task->start_date)) {
            $data['start_date'] = date('Y-m-d', current_time('timestamp'));
            $start_date = $data['start_date'];
            
            // If using days after start for deadline, calculate the actual deadline
            if (!empty($current_task->deadline_days_after_start)) {
                $start_date_obj = new DateTime($data['start_date']);
                $start_date_obj->add(new DateInterval('P' . $current_task->deadline_days_after_start . 'D'));
                $data['deadline'] = $start_date_obj->format('Y-m-d');
                $deadline = $data['deadline'];
                $deadline_days_after_start = $current_task->deadline_days_after_start;
            }
        }
    
        $result = Pandat69_DB::update_task($task_id, $data);
    
        if ($result) {
            $response = array(
                'message' => 'Status updated successfully.',
                'status_text' => ucfirst(str_replace('-', ' ', $status))
            );
            
            // Include start date in response if it was set
            if ($start_date) {
                $response['start_date'] = $start_date;
            }
            
            // Include deadline in response if it was calculated
            if ($deadline) {
                $response['deadline'] = $deadline;
                $response['deadline_days_after_start'] = $deadline_days_after_start;
            }
            
            wp_send_json_success($response);
        } else {
            wp_send_json_error(array('message' => 'Failed to update status.'));
        }
    }

} // End class