<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkAuditRepository {

    public function record( $entity_type, $entity_id, $action, $actor_id, $before = null, $after = null ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_audit_log';
        $result = $wpdb->insert(
            $table,
            array(
                'entity_type' => sanitize_key( $entity_type ),
                'entity_id'   => (int) $entity_id,
                'action'      => sanitize_key( $action ),
                'actor_id'    => max( 0, (int) $actor_id ),
                'before_data' => null === $before ? null : wp_json_encode( $before ),
                'after_data'  => null === $after ? null : wp_json_encode( $after ),
                'created_at'  => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
        );
        return false !== $result;
    }
}
