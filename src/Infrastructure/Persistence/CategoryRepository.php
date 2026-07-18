<?php

namespace Pandatask\Infrastructure\Persistence;

final class CategoryRepository {

    public function findForBoard( $board_name ) {
        global $wpdb;

        $categories_table = DatabaseContext::getDbPrefix() . 'categories';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name FROM {$categories_table} WHERE board_name = %s ORDER BY name ASC",
                $board_name
            )
        );
    }

    public function create( $board_name, $category_name ) {
        global $wpdb;

        $categories_table = DatabaseContext::getDbPrefix() . 'categories';
        $name             = sanitize_text_field( $category_name );

        if ( empty( $name ) ) {
            return false;
        }

        $data = array(
            'board_name' => sanitize_key( $board_name ),
            'name'       => $name,
        );

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$categories_table} WHERE board_name = %s AND name = %s",
                $data['board_name'],
                $data['name']
            )
        );

        if ( $exists > 0 ) {
            return false;
        }

        $result = $wpdb->insert( $categories_table, $data, array( '%s', '%s' ) );

        if ( $result ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    public function delete( $category_id, $board_name ) {
        global $wpdb;

        $prefix           = DatabaseContext::getDbPrefix();
        $categories_table = $prefix . 'categories';
        $tasks_table      = $prefix . 'tasks';

        if ( ! DatabaseContext::beginTransaction() ) {
            return false;
        }

        $task_update_result = $wpdb->update(
            $tasks_table,
            array( 'category_id' => null ),
            array(
                'category_id' => $category_id,
                'board_name'  => $board_name,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        if ( false === $task_update_result ) {
            DatabaseContext::rollback();

            return false;
        }

        $result = $wpdb->delete(
            $categories_table,
            array(
                'id'         => $category_id,
                'board_name' => $board_name,
            ),
            array( '%d', '%s' )
        );

        if ( false === $result || ! DatabaseContext::commit() ) {
            DatabaseContext::rollback();

            return false;
        }

        return true;
    }

    public function existsOnBoard( $category_id, $board_name ) {
        global $wpdb;

        $categories_table = DatabaseContext::getDbPrefix() . 'categories';
        $count            = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$categories_table} WHERE id = %d AND board_name = %s",
                $category_id,
                $board_name
            )
        );

        return $count > 0;
    }
}
