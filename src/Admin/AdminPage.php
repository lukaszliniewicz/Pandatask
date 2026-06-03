<?php
namespace Pandatask\Admin;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AdminPage {
    public function register() {
        add_action('admin_menu', array($this, 'add_admin_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu_page() {
        add_menu_page(
            __('Pandatask AI Assistant', 'pandatask'),
            __('Pandatask AI', 'pandatask'),
            'manage_options', // Capability required
            'pandatask-ai-assistant',
            array($this, 'render_ai_assistant_page'),
            'dashicons-superhero', // Icon
            26
        );

        add_submenu_page(
            'pandatask-ai-assistant',
            __('Pandatask Settings', 'pandatask'),
            __('Settings', 'pandatask'),
            'manage_options',
            'pandatask-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_ai_assistant_page() {
        ?>
        <div class="wrap pandat69-admin-wrap">
            <h1><?php esc_html_e('Pandatask AI Assistant', 'pandatask'); ?></h1>
            <p><?php esc_html_e('Use natural language to perform task operations. This tool converts your request into a structured format for an AI model, and then executes the AI\'s response.', 'pandatask'); ?></p>
            
            <div id="pandat69-ai-assistant">
                
                <!-- Step 1: Board Selection & User Prompt -->
                <div class="pandat69-ai-step">
                    <h2>Step 1: Write Your Request</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">
                                <label for="pandat69-board-select"><?php esc_html_e('Select Board', 'pandatask'); ?></label>
                            </th>
                            <td>
                                <select id="pandat69-board-select" name="pandat69-board-select" style="min-width: 300px;">
                                    <option value=""><?php esc_html_e('Loading boards...', 'pandatask'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Choose the task board you want to interact with.', 'pandatask'); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="pandat69-user-prompt"><?php esc_html_e('Your Request', 'pandatask'); ?></label>
                            </th>
                            <td>
                                <textarea id="pandat69-user-prompt" rows="5" class="large-text" placeholder="<?php esc_attr_e('e.g., Create a new task called "Finalize quarterly report" and assign it to user ID 1. Set the deadline to next Friday. Then, update task 123 to be "in-progress".', 'pandatask'); ?>"></textarea>
                                <p class="description"><?php esc_html_e('Describe what you want to do in plain English. You can describe multiple actions.', 'pandatask'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button id="pandat69-generate-prompt-btn" class="button button-primary"><?php esc_html_e('Generate AI Prompt', 'pandatask'); ?></button>
                    </p>
                </div>

                <!-- Step 2: Generated Prompt for LLM -->
                <div class="pandat69-ai-step" id="pandat69-generated-prompt-container" style="display: none;">
                    <h2>Step 2: Copy Prompt and Query AI</h2>
                    <p><?php esc_html_e('Copy the prompt below and paste it into your preferred AI model (e.g., ChatGPT, Claude, Gemini).', 'pandatask'); ?></p>
                    <label for="pandat69-generated-prompt"><strong><?php esc_html_e('Generated Prompt:', 'pandatask'); ?></strong></label>
                    <div class="pandat69-prompt-wrapper">
                        <pre id="pandat69-generated-prompt" class="pandat69-code-block"></pre>
                        <button class="button pandat69-copy-btn" data-target="pandat69-generated-prompt"><?php esc_html_e('Copy Prompt', 'pandatask'); ?></button>
                    </div>
                </div>

                <!-- Step 3: Paste LLM Response -->
                <div class="pandat69-ai-step" id="pandat69-llm-response-container" style="display: none;">
                    <h2>Step 3: Paste AI Response and Execute</h2>
                     <p><?php esc_html_e('Paste the JSON output from the AI model into the text area below.', 'pandatask'); ?></p>
                    <label for="pandat69-llm-response"><?php esc_html_e('AI JSON Response:', 'pandatask'); ?></label>
                    <textarea id="pandat69-llm-response" rows="10" class="large-text" placeholder='[{"action": "create_task", "data": {"name": "My new task", ...}}]'></textarea>
                    <p class="submit">
                        <button id="pandat69-execute-actions-btn" class="button button-primary"><?php esc_html_e('Execute Actions', 'pandatask'); ?></button>
                    </p>
                </div>
                
                <!-- Step 4: Results -->
                <div class="pandat69-ai-step" id="pandat69-results-container" style="display: none;">
                    <h2>Execution Results</h2>
                    <div id="pandat69-results" class="pandat69-results-area"></div>
                </div>

            </div>
            <div id="pandat69-spinner" class="spinner" style="float:none; visibility: hidden;"></div>
        </div>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pandatask Settings', 'pandatask'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pandatask_settings_group');
                do_settings_sections('pandatask-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('pandatask_settings_group', 'pandatask_bug_tracker_settings');

        add_settings_section(
            'pandatask_bug_tracker_section',
            __('Floating Bug Tracker Settings', 'pandatask'),
            null,
            'pandatask-settings'
        );

        add_settings_field(
            'pandatask_bug_tracker_enable',
            __('Enable Floating Bug Tracker', 'pandatask'),
            array($this, 'render_bug_tracker_enable_field'),
            'pandatask-settings',
            'pandatask_bug_tracker_section'
        );
        
        add_settings_field(
            'pandatask_bug_tracker_board',
            __('Target Board', 'pandatask'),
            array($this, 'render_bug_tracker_board_field'),
            'pandatask-settings',
            'pandatask_bug_tracker_section'
        );
        
        add_settings_field(
            'pandatask_bug_tracker_assignee',
            __('Default Assignee', 'pandatask'),
            array($this, 'render_bug_tracker_assignee_field'),
            'pandatask-settings',
            'pandatask_bug_tracker_section'
        );
    }

    public function render_bug_tracker_enable_field() {
        $options = get_option('pandatask_bug_tracker_settings', array());
        // Default to 'off' unless 'enable' was previously set to 1, then 'logged_in'
        $default_visibility = (isset($options['enable']) && $options['enable']) ? 'logged_in' : 'off';
        $visibility = $options['visibility'] ?? $default_visibility;
        ?>
        <select name="pandatask_bug_tracker_settings[visibility]">
            <option value="off" <?php selected($visibility, 'off'); ?>><?php esc_html_e('Disabled', 'pandatask'); ?></option>
            <option value="logged_in" <?php selected($visibility, 'logged_in'); ?>><?php esc_html_e('Logged-in Users Only', 'pandatask'); ?></option>
            <option value="logged_out" <?php selected($visibility, 'logged_out'); ?>><?php esc_html_e('Logged-out Users Only', 'pandatask'); ?></option>
            <option value="both" <?php selected($visibility, 'both'); ?>><?php esc_html_e('Everyone', 'pandatask'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Control who can see and use the floating bug report button.', 'pandatask'); ?></p>
        <?php
    }
    
    public function render_bug_tracker_board_field() {
        $options = get_option('pandatask_bug_tracker_settings', array());
        $board_service = new \Pandatask\Application\Board\BoardService();
        $boards = $board_service->getAllBoardNames();
        $selected_board = $options['board'] ?? '';
        ?>
        <select name="pandatask_bug_tracker_settings[board]">
            <option value=""><?php esc_html_e('-- Select a Board --', 'pandatask'); ?></option>
            <?php foreach ($boards as $board): ?>
                <option value="<?php echo esc_attr($board->id); ?>" <?php selected($selected_board, $board->id); ?>>
                    <?php echo esc_html($board->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Select the board where new bug reports from the floating widget will be created.', 'pandatask'); ?></p>
        <?php
    }
    
    public function render_bug_tracker_assignee_field() {
        $options = get_option('pandatask_bug_tracker_settings', array());
        $admin_users_raw = get_users(array('role' => 'administrator', 'orderby' => 'display_name'));
        $users = array();
        foreach ($admin_users_raw as $user) {
            $users[] = array('id' => $user->ID, 'name' => $user->display_name);
        }
        $selected_assignee = $options['assignee'] ?? 0;
        ?>
        <select name="pandatask_bug_tracker_settings[assignee]">
            <option value="0"><?php esc_html_e('-- None --', 'pandatask'); ?></option>
            <?php foreach ($users as $user): ?>
                <option value="<?php echo esc_attr($user['id']); ?>" <?php selected($selected_assignee, $user['id']); ?>>
                    <?php echo esc_html($user['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Optionally select a default user to assign all new bug reports to.', 'pandatask'); ?></p>
        <?php
    }
}
