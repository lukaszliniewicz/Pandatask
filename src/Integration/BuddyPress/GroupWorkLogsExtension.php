<?php
/**
 * Work Logs BuddyPress group extension.
 */
namespace Pandatask\Integration\BuddyPress;

use Pandatask\Application\Settings\FeatureSettings;
use Pandatask\Bootstrap\AssetRegistrar;
use Pandatask\Infrastructure\Persistence\WorkLogShareRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'BP_Group_Extension' ) ) {
    return;
}

final class GroupWorkLogsExtension extends \BP_Group_Extension {

    public function __construct() {
        parent::init(
            array(
                'slug'              => 'work-logs',
                'name'              => __( 'Work logs', 'pandatask' ),
                'nav_item_position' => 82,
                'visibility'        => 'private',
                'show_in_create'    => true,
                'enable_nav_item'   => false,
                'screens'           => array(
                    'admin'  => array(
                        'enabled'  => true,
                        'name'     => __( 'Work log settings', 'pandatask' ),
                        'slug'     => 'work-log-settings',
                        'position' => 52,
                    ),
                    'create' => array(
                        'enabled'  => true,
                        'name'     => __( 'Work log settings', 'pandatask' ),
                        'position' => 52,
                    ),
                ),
            )
        );

        add_action( 'bp_actions', array( $this, 'setup_nav_visibility' ), 22 );
    }

    /**
     * Add the tab only when the group has enabled the surface and the viewer
     * is a logged-in member, group administrator, or group moderator.
     */
    public function setup_nav_visibility() {
        if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
            return;
        }

        $group_id = function_exists( 'bp_get_current_group_id' ) ? absint( bp_get_current_group_id() ) : 0;
        if ( ! $group_id || ! $this->work_log_available() || ! $this->is_enabled( $group_id ) ) {
            return;
        }

        $group = groups_get_group( $group_id );
        if ( ! $group ) {
            return;
        }

        $current_user_id = get_current_user_id();
        $has_access       = is_user_logged_in()
            && (
                groups_is_user_member( $current_user_id, $group_id )
                || groups_is_user_admin( $current_user_id, $group_id )
                || groups_is_user_mod( $current_user_id, $group_id )
            );

        bp_core_new_subnav_item(
            array(
                'name'            => __( 'Work logs', 'pandatask' ),
                'slug'            => $this->slug,
                'parent_url'      => BuddyPressSupport::groupUrl( $group ),
                'parent_slug'     => $group->slug,
                'screen_function' => array( $this, 'display_screen_callback' ),
                'position'        => $this->nav_item_position,
                'user_has_access' => $has_access,
            ),
            'groups'
        );
    }

    public function display_screen_callback() {
        AssetRegistrar::enqueueFrontendAssetHandles();

        add_action( 'bp_template_title', array( $this, 'display_screen_title' ) );
        add_action( 'bp_template_content', array( $this, 'display_screen_content' ) );
        bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'groups/single/plugins' ) );
    }

    public function display_screen_title() {
        echo esc_html__( 'Work logs', 'pandatask' );
    }

    public function display_screen_content() {
        $group_id = function_exists( 'bp_get_current_group_id' ) ? absint( bp_get_current_group_id() ) : 0;

        if ( ! $this->current_user_can_view( $group_id ) ) {
            echo '<div id="message" class="bp-feedback error"><p>';
            esc_html_e( 'You must be a member of this group to view work logs.', 'pandatask' );
            echo '</p></div>';
            return;
        }

        if ( ! $this->work_log_available() ) {
            echo '<div id="message" class="bp-feedback info"><p>';
            esc_html_e( 'Work Log is currently disabled for this site.', 'pandatask' );
            echo '</p></div>';
            return;
        }

        if ( ! $this->is_enabled( $group_id ) ) {
            echo '<div id="message" class="bp-feedback info"><p>';
            esc_html_e( 'Work logs are currently disabled for this group.', 'pandatask' );
            echo '</p></div>';
            return;
        }

        echo do_shortcode(
            '[pandatask_group_work_logs group_id="' . esc_attr( $group_id ) . '"]'
        );
    }

    public function create_screen( $group_id = null ) {
        if ( function_exists( 'bp_is_group_creation_step' ) && ! bp_is_group_creation_step( $this->slug ) ) {
            return;
        }
        ?>
        <h4 class="bp-create-step-title"><?php esc_html_e( 'Work log settings', 'pandatask' ); ?></h4>

        <label for="pandat69_work_logs_enabled" class="bp-label">
            <input type="checkbox" name="pandat69_work_logs_enabled" id="pandat69_work_logs_enabled" value="1">
            <?php esc_html_e( 'Enable member work logs for this group', 'pandatask' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Adds a Work logs tab for group members. This only enables the group surface; it does not opt any member into sharing their personal log.', 'pandatask' ); ?>
        </p>
        <?php
        wp_nonce_field( 'groups_create_save_' . $this->slug );
    }

    public function create_screen_save( $group_id = null ) {
        check_admin_referer( 'groups_create_save_' . $this->slug );

        if ( ! $group_id ) {
            $group_id = bp_get_new_group_id();
        }
        if ( ! $group_id ) {
            return;
        }

        $enabled = isset( $_POST['pandat69_work_logs_enabled'] ) ? '1' : '0';
        groups_update_groupmeta( $group_id, 'pandat69_work_logs_enabled', $enabled );
    }

    public function edit_screen( $group_id = null ) {
        if ( ! bp_is_item_admin() && ! bp_is_item_mod() ) {
            echo '<div id="message" class="bp-feedback error"><p>';
            esc_html_e( 'You do not have permission to manage work log settings.', 'pandatask' );
            echo '</p></div>';
            return;
        }

        if ( ! $group_id ) {
            $group_id = bp_get_current_group_id();
        }

        $enabled = $this->is_enabled( $group_id );
        ?>
        <h4><?php esc_html_e( 'Work log settings', 'pandatask' ); ?></h4>

        <label for="pandat69_work_logs_enabled" class="bp-label">
            <input type="checkbox" name="pandat69_work_logs_enabled" id="pandat69_work_logs_enabled" value="1" <?php checked( $enabled ); ?>>
            <?php esc_html_e( 'Enable member work logs for this group', 'pandatask' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'When enabled, a Work logs tab will be available for group members. This enables the surface only; each member chooses whether to share their own personal log, and group administrators cannot share it for them.', 'pandatask' ); ?>
        </p>

        <p class="submit">
            <input type="submit" name="save" value="<?php esc_attr_e( 'Save Settings', 'pandatask' ); ?>" class="button-primary">
        </p>
        <?php
        wp_nonce_field( 'groups_edit_save_' . $this->slug );
    }

    public function edit_screen_save( $group_id = null ) {
        if ( ! isset( $_POST['save'] ) ) {
            return;
        }

        check_admin_referer( 'groups_edit_save_' . $this->slug );

        if ( ! bp_is_item_admin() && ! bp_is_item_mod() ) {
            bp_core_add_message( __( 'You do not have permission to save work log settings.', 'pandatask' ), 'error' );
            return;
        }

        if ( ! $group_id ) {
            $group_id = bp_get_current_group_id();
        }
        if ( ! $group_id ) {
            return;
        }

        $enabled = isset( $_POST['pandat69_work_logs_enabled'] ) ? '1' : '0';
        groups_update_groupmeta( $group_id, 'pandat69_work_logs_enabled', $enabled );
        $saved = $enabled === (string) groups_get_groupmeta( $group_id, 'pandat69_work_logs_enabled', true );

        // Disabling the surface revokes every persisted opt-in. Re-enabling it
        // therefore requires fresh, explicit consent from each member.
        if ( $saved && '0' === $enabled ) {
            WorkLogShareRepository::deleteForGroup( $group_id );
        }

        if ( $saved ) {
            bp_core_add_message( __( 'Work log settings saved successfully.', 'pandatask' ) );
        } else {
            bp_core_add_message( __( 'The work log settings could not be saved. Please try again.', 'pandatask' ), 'error' );
        }

        $group        = groups_get_current_group();
        $redirect_url = trailingslashit( BuddyPressSupport::groupUrl( $group ) . 'admin/work-log-settings' );
        bp_core_redirect( $redirect_url );
    }

    private function is_enabled( $group_id ) {
        if ( ! $group_id || ! function_exists( 'groups_get_groupmeta' ) ) {
            return false;
        }

        // This feature is deliberately default-off. Do not use the generic
        // groupFeatureEnabled helper, whose legacy behavior treats missing
        // metadata as enabled for older group features.
        return '1' === (string) groups_get_groupmeta( $group_id, 'pandat69_work_logs_enabled', true );
    }

    private function work_log_available() {
        return ( new FeatureSettings() )->workLogEnabled();
    }

    private function current_user_can_view( $group_id ) {
        if ( ! is_user_logged_in() || ! $group_id ) {
            return false;
        }

        $user_id = get_current_user_id();

        return groups_is_user_member( $user_id, $group_id )
            || groups_is_user_admin( $user_id, $group_id )
            || groups_is_user_mod( $user_id, $group_id );
    }
}
