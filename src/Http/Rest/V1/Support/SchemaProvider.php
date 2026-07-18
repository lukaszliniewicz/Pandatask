<?php

namespace Pandatask\Http\Rest\V1\Support;

final class SchemaProvider {

    public function get_task_schema( $is_update = false ) {
        $schema = array(
            'id'                  => array( 'description' => __( 'Unique identifier for the task.', 'pandatask' ), 'type' => 'integer', 'context' => array( 'view', 'edit', 'embed' ), 'readonly' => true ),
            'name'                => array( 'description' => __( 'The name of the task.', 'pandatask' ), 'type' => 'string', 'required' => ! $is_update, 'sanitize_callback' => 'sanitize_text_field' ),
            'description'         => array( 'description' => __( 'The description of the task.', 'pandatask' ), 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ),
            'status'              => array( 'description' => __( 'The status of the task.', 'pandatask' ), 'type' => 'string', 'enum' => array( 'pending', 'in-progress', 'done' ) ),
            'priority'            => array( 'description' => __( 'Priority from 1 to 10.', 'pandatask' ), 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
            'deadline'            => array( 'description' => __( 'Deadline in YYYY-MM-DD format.', 'pandatask' ), 'type' => 'string' ),
            'start_date'          => array( 'description' => __( 'Start date in YYYY-MM-DD format.', 'pandatask' ), 'type' => 'string' ),
            'assigned_persons'    => array( 'description' => __( 'Array of user IDs assigned to the task.', 'pandatask' ), 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
            'supervisor_persons'  => array( 'description' => __( 'Array of user IDs supervising the task.', 'pandatask' ), 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
            'predecessors'        => array( 'description' => __( 'Array of parent task IDs that this task depends on.', 'pandatask' ), 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
            'category_id'         => array( 'description' => __( 'The ID of the category.', 'pandatask' ), 'type' => 'integer' ),
            'project_id'          => array( 'description' => __( 'The ID of the project.', 'pandatask' ), 'type' => 'integer' ),
            'parent_task_id'      => array( 'description' => __( 'The ID of the parent task.', 'pandatask' ), 'type' => 'integer' ),
            'board_name'          => array( 'description' => __( 'Destination board for a task move.', 'pandatask' ), 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
            'task_type'           => array( 'description' => __( 'Task type.', 'pandatask' ), 'type' => 'string', 'enum' => array( 'task', 'bug' ) ),
            'bug_url'             => array( 'description' => __( 'Page associated with a bug report.', 'pandatask' ), 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
            'archived'            => array( 'description' => __( 'Whether the task is archived.', 'pandatask' ), 'type' => 'boolean' ),
            'notify_deadline'     => array( 'description' => __( 'Whether deadline notifications are enabled.', 'pandatask' ), 'type' => 'boolean' ),
            'notify_days_before'  => array( 'description' => __( 'Days before the deadline to notify.', 'pandatask' ), 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ),
            'deadline_days_after_start' => array( 'description' => __( 'Dynamic deadline duration in days.', 'pandatask' ), 'type' => 'integer', 'minimum' => 1 ),
            'is_recurring'        => array( 'description' => __( 'Whether the task is a recurring template.', 'pandatask' ), 'type' => 'boolean' ),
            'recurrence_frequency'=> array( 'description' => __( 'Frequency of recurrence.', 'pandatask' ), 'type' => 'string', 'enum' => array( 'weekly', 'bi-weekly', 'monthly', 'custom_weekly' ) ),
            'recurrence_interval' => array( 'description' => __( 'Interval for recurrence.', 'pandatask' ), 'type' => 'integer' ),
            'recurrence_days'     => array( 'description' => __( 'Comma-separated days for custom weekly recurrence.', 'pandatask' ), 'type' => 'string' ),
            'recurrence_ends_on'  => array( 'description' => __( 'Date the recurrence should end.', 'pandatask' ), 'type' => 'string' ),
            'attachment_type'     => array( 'description' => __( 'Type of attachment.', 'pandatask' ), 'type' => 'string', 'enum' => array( '', 'file', 'link' ) ),
            'attachment_url'      => array( 'description' => __( 'URL of the attachment.', 'pandatask' ), 'type' => 'string' ),
            'attachment_post_id'  => array( 'description' => __( 'WP post ID of the attachment.', 'pandatask' ), 'type' => 'integer' ),
            'attachment_filename' => array( 'description' => __( 'Filename of the attachment.', 'pandatask' ), 'type' => 'string' ),
            'default_assignee_id' => array( 'description' => __( 'Suggested default assignee.', 'pandatask' ), 'type' => 'integer' ),
        );

        if ( ! $is_update ) {
            $schema['status']['default']       = 'pending';
            $schema['priority']['default']     = 5;
            $schema['task_type']['default']    = 'task';
            $schema['is_recurring']['default'] = false;
        }

        return $schema;
    }

    public function get_project_schema( $is_update = false ) {
        return array(
            'id'                 => array( 'description' => __( 'Unique identifier.', 'pandatask' ), 'type' => 'integer', 'context' => array( 'view', 'edit', 'embed' ), 'readonly' => true ),
            'name'               => array( 'description' => __( 'The name of the project.', 'pandatask' ), 'type' => 'string', 'required' => ! $is_update, 'sanitize_callback' => 'sanitize_text_field' ),
            'description'        => array( 'type' => 'string', 'description' => __( 'Project description', 'pandatask' ), 'sanitize_callback' => 'sanitize_textarea_field' ),
            'deadline'           => array( 'type' => 'string', 'description' => __( 'Project deadline', 'pandatask' ) ),
            'assigned_persons'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Array of user IDs assigned to the project.', 'pandatask' ) ),
            'supervisor_persons' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Array of user IDs supervising the project.', 'pandatask' ) ),
        );
    }

    public function get_category_schema() {
        return array(
            'id'   => array( 'description' => __( 'Unique identifier.', 'pandatask' ), 'type' => 'integer', 'context' => array( 'view', 'edit', 'embed' ), 'readonly' => true ),
            'name' => array( 'description' => __( 'The name of the category.', 'pandatask' ), 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
        );
    }

    public function get_comment_schema( $is_update = false ) {
        return array(
            'id'           => array( 'description' => __( 'Unique identifier.', 'pandatask' ), 'type' => 'integer', 'context' => array( 'view', 'edit', 'embed' ), 'readonly' => true ),
            'task_id'      => array( 'description' => __( 'The ID of the task.', 'pandatask' ), 'type' => 'integer', 'required' => ! $is_update ),
            'comment_text' => array( 'description' => __( 'The content of the comment.', 'pandatask' ), 'type' => 'string', 'required' => true, 'sanitize_callback' => 'wp_kses_post' ),
        );
    }

    public function get_api_schema_for_prompt() {
        $prompt  = "API ENDPOINTS AND SCHEMA:\n";
        $prompt .= "You can perform one or more of the following actions: create_task, update_task, delete_task, create_project, update_project, delete_project, create_category, delete_category, create_comment, update_comment, delete_comment.\n\n";
        $prompt .= "--- TASKS ---\n";
        $prompt .= "1. 'create_task': Creates a new task. Required: 'name'. Optional: 'description', 'status', 'priority', 'deadline', 'start_date', 'assigned_persons' (array of user IDs), 'supervisor_persons' (array of user IDs), 'predecessors' (array of task IDs), 'category_id', 'parent_task_id', 'is_recurring' (boolean), 'recurrence_frequency' ('weekly', 'monthly', 'custom_weekly'), 'recurrence_interval' (integer), 'recurrence_days' (string), 'recurrence_ends_on', 'attachment_type', 'attachment_url', 'attachment_filename'.\n";
        $prompt .= "2. 'update_task': Updates an existing task. Required: 'id'. Optional: any field from create_task.\n";
        $prompt .= "3. 'delete_task': Deletes a task. Required: 'id'.\n\n";
        $prompt .= "--- PROJECTS ---\n";
        $prompt .= "4. 'create_project': Creates a new project. Required: 'name'. Optional: 'description', 'deadline', 'assigned_persons', 'supervisor_persons'.\n";
        $prompt .= "5. 'update_project': Updates an existing project. Required: 'id'.\n";
        $prompt .= "6. 'delete_project': Deletes a project. Required: 'id'.\n\n";
        $prompt .= "--- CATEGORIES ---\n";
        $prompt .= "7. 'create_category': Creates a new category. Required: 'name'.\n";
        $prompt .= "8. 'delete_category': Deletes a category. Required: 'id'.\n\n";
        $prompt .= "--- COMMENTS ---\n";
        $prompt .= "9. 'create_comment': Adds a comment. Required: 'task_id', 'comment_text'.\n";
        $prompt .= "10. 'update_comment': Updates a comment. Required: 'id', 'comment_text'.\n";
        $prompt .= "11. 'delete_comment': Deletes a comment. Required: 'id'.\n\n";
        $prompt .= "Today's date is " . wp_date( 'Y-m-d' ) . ".\n";

        return $prompt;
    }
}
