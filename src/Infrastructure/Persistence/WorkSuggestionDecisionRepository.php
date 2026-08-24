<?php

namespace Pandatask\Infrastructure\Persistence;

final class WorkSuggestionDecisionRepository {

    public function find( $user_id, $provider_key, $external_key ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_suggestion_decisions';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND provider_key = %s AND external_key = %s",
                (int) $user_id,
                sanitize_key( (string) $provider_key ),
                sanitize_text_field( (string) $external_key )
            )
        );
    }

    public function findForUser( $user_id ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_suggestion_decisions';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC",
                (int) $user_id
            )
        );
    }

    public function save( $user_id, $provider_key, $external_key, $decision, $work_entry_id = null ) {
        global $wpdb;
        $table = DatabaseContext::getDbPrefix() . 'work_suggestion_decisions';
        $now = gmdate( 'Y-m-d H:i:s' );
        $data = array(
            'user_id'       => (int) $user_id,
            'provider_key'  => sanitize_key( (string) $provider_key ),
            'external_key'  => sanitize_text_field( (string) $external_key ),
            'decision'      => sanitize_key( (string) $decision ),
            'work_entry_id' => $work_entry_id ? (int) $work_entry_id : null,
            'decided_at'    => $now,
            'updated_at'    => $now,
        );
        $existing = $this->find( $user_id, $provider_key, $external_key );
        if ( $existing ) {
            return false !== $wpdb->update(
                $table,
                $data,
                array( 'id' => (int) $existing->id )
            );
        }
        $data['created_at'] = $now;
        return false !== $wpdb->insert( $table, $data );
    }
}
