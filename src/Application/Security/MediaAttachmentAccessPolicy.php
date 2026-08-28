<?php

namespace Pandatask\Application\Security;

use WP_Error;

/**
 * Keeps Media Library object authorization consistent across task entrypoints.
 */
final class MediaAttachmentAccessPolicy {

    /**
     * @param int         $attachment_post_id Media Library attachment ID.
     * @param object|null $current_task Existing task when validating an update.
     * @return true|WP_Error
     */
    public function authorize( $attachment_post_id, $current_task = null ) {
        $attachment_post_id = absint( $attachment_post_id );

        if ( $attachment_post_id <= 0 || 'attachment' !== get_post_type( $attachment_post_id ) ) {
            return new WP_Error( 'rest_invalid_attachment', __( 'A valid Media Library attachment is required.', 'pandatask' ), array( 'status' => 422 ) );
        }

        if ( $current_task && (int) ( $current_task->attachment_post_id ?? 0 ) === $attachment_post_id ) {
            return true;
        }

        if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_post_id ) ) {
            return new WP_Error( 'rest_forbidden_attachment', __( 'You cannot attach this Media Library item.', 'pandatask' ), array( 'status' => 403 ) );
        }

        return true;
    }
}
