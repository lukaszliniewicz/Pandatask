<?php
namespace Pandatask\Frontend;

use Pandatask\Bootstrap\AssetRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TaskBoardShortcode {

    public function register() {
        add_shortcode( 'task_board', array( $this, 'render_shortcode' ) );
        add_shortcode( 'pandatask_bug_tracker', array( $this, 'render_bug_tracker_shortcode' ) );
    }

    public function render_bug_tracker_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'board_name' => 'default_board',
            'default_assignee_id' => 0,
        ), $atts, 'pandatask_bug_tracker' );

        $this->enqueue_assets();

        $board_name = sanitize_key($atts['board_name']);
        $default_assignee_id = absint($atts['default_assignee_id']);
        
        // Output React mounting point
        return sprintf(
            '<div class="pandat69-bug-tracker-container pandat69-root iarf-app iarf-app--pandatask iarf-plugin iarf-plugin--pandatask" id="pandat69-bug-tracker-%s" data-iarf-product="pandatask" data-iarf-app="pandatask" data-iarf-plugin="pandatask" data-iarf-product-kind="react-plugin" data-board-name="%s" data-default-assignee-id="%s"></div>',
            esc_attr($board_name),
            esc_attr($board_name),
            esc_attr($default_assignee_id)
        );
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
        $is_user_board = preg_match('/^user_(\d+)$/', $board_name);
        
        // Ensure scripts are enqueued in case the shortcode is rendered from a widget or another late path.
        $this->enqueue_assets();

        $attributes = sprintf(
            'id="pandat69-container-%1$s" data-board-name="%1$s" data-is-user-board="%2$s"',
            esc_attr($board_name),
            $is_user_board ? 'true' : 'false'
        );

        if ($group_id > 0) {
            $attributes .= ' data-group-id="' . esc_attr($group_id) . '"';
        }
        if (!empty($page_name)) {
            $attributes .= ' data-page-name="' . esc_attr($page_name) . '"';
        }
        
        // Output clean container for React to mount into
        return '<div class="pandat69-container pandat69-root iarf-app iarf-app--pandatask iarf-plugin iarf-plugin--pandatask" data-iarf-product="pandatask" data-iarf-app="pandatask" data-iarf-plugin="pandatask" data-iarf-product-kind="react-plugin" ' . $attributes . '></div>';
    }

    private function enqueue_assets() {
        AssetRegistrar::enqueueFrontendAssetHandles();
    }
    
    /**
     * Get a human-readable display name for the board
     *
     * @param string $board_name The internal board name
     * @param int $group_id The group ID if available
     * @return string The display name for the board
     */
    private function get_board_display_name($board_name, $group_id = 0) {
        $board_service = new \Pandatask\Application\Board\BoardService();

        return $board_service->getBoardDisplayName($board_name, $group_id);
    }
}
