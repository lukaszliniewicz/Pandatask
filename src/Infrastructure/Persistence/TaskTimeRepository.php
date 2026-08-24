<?php

namespace Pandatask\Infrastructure\Persistence;

final class TaskTimeRepository {

    public function latest( $occurrence_id, $user_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_time_resolutions';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE occurrence_id = %d AND user_id = %d
                 ORDER BY revision DESC, id DESC LIMIT 1",
                (int) $occurrence_id,
                (int) $user_id
            )
        );
    }

    public function insertRevision( array $data ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_time_resolutions';
        $latest = $this->latest( $data['occurrence_id'], $data['user_id'] );
        $data['revision'] = $latest ? (int) $latest->revision + 1 : 1;
        $data['created_at'] = gmdate( 'Y-m-d H:i:s' );
        $data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $result = $wpdb->insert( $table, $data );
        return false === $result ? false : (int) $wpdb->insert_id;
    }

    public function countUnresolvedForOccurrence( $occurrence_id, array $user_ids ) {
        if ( empty( $user_ids ) ) {
            return 0;
        }
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'task_time_resolutions';
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params = array_merge( array( (int) $occurrence_id ), $ids );
        $sql = "SELECT COUNT(*) FROM (
                    SELECT user_id, MAX(revision) AS revision
                    FROM {$table}
                    WHERE occurrence_id = %d AND user_id IN ({$placeholders})
                    GROUP BY user_id
                ) latest
                INNER JOIN {$table} resolution
                    ON resolution.occurrence_id = %d
                   AND resolution.user_id = latest.user_id
                   AND resolution.revision = latest.revision
                WHERE resolution.state = 'unresolved'";
        $params[] = (int) $occurrence_id;
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }
}
