<?php

namespace Pandatask\Infrastructure\Media;

use Pandatask\Application\Security\BoardAccessPolicy;
use Pandatask\Infrastructure\Persistence\TaskRepository;
use WP_Error;
use WP_REST_Request;

final class ProtectedAttachmentService {
    const OPTION_PREFIX = 'pandatask_protected_attachment_';

    public static function registerHooks() {
        add_action( 'rest_api_init', array( __CLASS__, 'registerRoutes' ) );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'pandatask migrate-protected-attachments', array( __CLASS__, 'cliMigrate' ) );
        }
    }

    public static function registerRoutes() {
        register_rest_route( 'pandatask/v1', '/protected-attachments/(?P<task_id>\d+)', array(
            'methods'             => array( 'GET', 'HEAD' ),
            'callback'            => array( __CLASS__, 'serve' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function syncTask( $task_id ) {
        $task_id = (int) $task_id;
        $task = ( new TaskRepository() )->findById( $task_id );
        if ( ! $task || 'file' !== (string) $task->attachment_type || empty( $task->attachment_post_id ) ) {
            self::deleteTaskFiles( $task_id );
            return false;
        }

        $attachment_id = (int) $task->attachment_post_id;
        $registry = self::registry( $task_id );
        if ( ! empty( $registry['attachment_id'] ) && (int) $registry['attachment_id'] !== $attachment_id ) {
            self::deleteTaskFiles( $task_id );
            $registry = array();
        }

        $existing = ! empty( $registry['key'] ) ? self::pathForKey( $registry['key'], true ) : false;
        if ( ! $existing ) {
            $source = get_attached_file( $attachment_id );
            if ( ! $source || ! is_readable( $source ) ) {
                $source = self::findProtectedSourceForAttachment( $attachment_id, $task_id );
            }
            if ( ! $source ) {
                return new WP_Error( 'pandatask_attachment_source_missing', 'The task attachment source file is missing.' );
            }
            $extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ) ?: 'bin';
            $key = $task_id . '/original.' . sanitize_key( $extension );
            $target = self::pathForKey( $key, false );
            if ( is_wp_error( $target ) ) return $target;
            if ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $source, $target ) ) {
                return new WP_Error( 'pandatask_attachment_copy_failed', 'The task attachment could not be copied into protected storage.' );
            }
            self::normalizePermissions( $target );
            $registry = array(
                'task_id'        => $task_id,
                'attachment_id'  => $attachment_id,
                'key'            => $key,
                'filename'       => (string) ( $task->attachment_filename ?: wp_basename( $source ) ),
                'mime_type'      => (string) get_post_mime_type( $attachment_id ),
                'generation'     => time(),
                'public_removed' => false,
            );
        } else {
            self::normalizePermissions( $existing );
        }

        self::saveRegistry( $task_id, $registry );
        // Never remove Media Library originals automatically. A selected attachment may
        // be referenced outside post content (templates, options, drafts, CSS, or another
        // plugin), so automatic unlinking is not a recoverable ownership boundary.
        $registry['public_removed'] = false;
        self::saveRegistry( $task_id, $registry );
        return array( 'protected' => true, 'public_removed' => ! empty( $registry['public_removed'] ) );
    }

    public static function prepareTask( $task, $viewer_id = null ) {
        if ( ! $task || empty( $task->id ) || 'file' !== (string) $task->attachment_type ) return $task;
        $registry = self::registry( (int) $task->id );
        if ( empty( $registry['key'] ) || ! self::pathForKey( $registry['key'], true ) ) return $task;
        $viewer_id = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;
        $task->attachment_url = self::viewerCanAccess( $task, $viewer_id )
            ? self::signedUrl( (int) $task->id, $viewer_id, $registry )
            : '';
        return $task;
    }

    public static function prepareTasks( $tasks, $viewer_id = null ) {
        foreach ( (array) $tasks as $task ) self::prepareTask( $task, $viewer_id );
        return $tasks;
    }

    public static function serve( WP_REST_Request $request ) {
        $task_id = (int) $request['task_id'];
        $task = ( new TaskRepository() )->findById( $task_id );
        $registry = self::registry( $task_id );
        if ( ! $task || empty( $registry['key'] ) ) return self::notFound();
        $viewer_id = self::resolveSignedViewer( $request, $task_id, $registry );
        if ( $viewer_id <= 0 || ! self::viewerCanAccess( $task, $viewer_id ) ) return self::notFound();
        $path = self::pathForKey( $registry['key'], true );
        if ( ! $path || ! is_readable( $path ) ) return self::notFound();
        return self::streamFile( $request, $path, (string) ( $registry['mime_type'] ?? '' ), (string) ( $registry['filename'] ?? 'attachment' ) );
    }

    public static function deleteTaskFiles( $task_id ) {
        $task_id = (int) $task_id;
        $registry = self::registry( $task_id );
        $path = ! empty( $registry['key'] ) ? self::pathForKey( $registry['key'], true ) : false;
        if ( $path ) @unlink( $path );
        $directory = self::baseDir() . '/' . $task_id;
        if ( is_dir( $directory ) ) @rmdir( $directory );
        delete_option( self::OPTION_PREFIX . $task_id );
    }

    public static function cliMigrate( $args, $assoc_args ) {
        unset( $args );
        global $wpdb;
        $limit = max( 1, min( 500, (int) ( $assoc_args['limit'] ?? 100 ) ) );
        $cursor = max( 0, (int) ( $assoc_args['cursor'] ?? 0 ) );
        $write = isset( $assoc_args['write'] );
        $table = $wpdb->prefix . 'pandat69_tasks';
        $tasks = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id > %d AND attachment_type = 'file' AND COALESCE(attachment_post_id, 0) > 0 ORDER BY id ASC LIMIT %d",
            $cursor,
            $limit
        ) );
        $stats = array( 'dry_run' => ! $write, 'scanned' => 0, 'protected' => 0, 'failed' => 0, 'next_cursor' => $cursor, 'done' => count( $tasks ) < $limit );
        foreach ( $tasks as $task ) {
            $stats['scanned']++;
            $stats['next_cursor'] = (int) $task->id;
            if ( ! $write ) continue;
            $result = self::syncTask( (int) $task->id );
            if ( is_wp_error( $result ) ) {
                $stats['failed']++;
                \WP_CLI::warning( 'Task ' . (int) $task->id . ': ' . $result->get_error_message() );
            } else {
                $stats['protected']++;
            }
        }
        \WP_CLI::line( wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
    }

    private static function streamFile( $request, $path, $mime_type, $filename ) {
        $size = (int) filesize( $path );
        $header = trim( (string) $request->get_header( 'range' ) );
        if ( '' === $header && isset( $_SERVER['HTTP_RANGE'] ) ) $header = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) );
        $range = self::parseRange( $header, $size );
        if ( is_wp_error( $range ) ) {
            status_header( 416 ); header( 'Content-Range: bytes */' . $size ); exit;
        }
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) return self::notFound();
        $start = $range['start']; $length = $range['length'];
        status_header( $range['partial'] ? 206 : 200 );
        header( 'Content-Type: ' . ( sanitize_mime_type( $mime_type ) ?: 'application/octet-stream' ) );
        header( 'Content-Length: ' . $length );
        header( 'Accept-Ranges: bytes' );
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode( sanitize_file_name( $filename ) ) );
        header( 'X-Content-Type-Options: nosniff' ); header( 'X-Robots-Tag: noindex' );
        if ( $range['partial'] ) header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $start + $length - 1, $size ) );
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
        if ( 'head' !== $request_method ) {
            fseek( $handle, $start ); $remaining = $length;
            while ( $remaining > 0 && ! feof( $handle ) ) {
                $chunk = fread( $handle, min( 1048576, $remaining ) );
                if ( false === $chunk || '' === $chunk ) break;
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $remaining -= strlen( $chunk );
            }
        }
        fclose( $handle ); exit;
    }

    private static function parseRange( $header, $size ) {
        if ( '' === $header ) return array( 'start' => 0, 'length' => $size, 'partial' => false );
        if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $header, $matches ) || ( '' === $matches[1] && '' === $matches[2] ) ) return new WP_Error( 'invalid_range' );
        if ( '' === $matches[1] ) {
            $length = min( (int) $matches[2], $size );
            return $length > 0 ? array( 'start' => $size - $length, 'length' => $length, 'partial' => true ) : new WP_Error( 'invalid_range' );
        }
        $start = (int) $matches[1]; $end = '' === $matches[2] ? $size - 1 : min( (int) $matches[2], $size - 1 );
        return $start < $size && $end >= $start ? array( 'start' => $start, 'length' => $end - $start + 1, 'partial' => true ) : new WP_Error( 'invalid_range' );
    }

    private static function viewerCanAccess( $task, $viewer_id ) {
        if ( $viewer_id <= 0 || ! $task ) return false;
        if ( user_can( $viewer_id, 'manage_options' ) ) return true;
        if ( ! empty( $task->assigned_user_ids ) && in_array( (string) $viewer_id, $task->assigned_user_ids, true ) ) return true;
        if ( ! empty( $task->supervisor_user_ids ) && in_array( (string) $viewer_id, $task->supervisor_user_ids, true ) ) return true;
        if ( (int) ( $task->creator_id ?? 0 ) === $viewer_id ) return true;
        return true === ( new BoardAccessPolicy() )->canReadBoard( (string) $task->board_name, $viewer_id );
    }

    private static function signedUrl( $task_id, $viewer_id, $registry ) {
        $expires = time() + 900; $generation = (int) ( $registry['generation'] ?? 1 );
        $payload = implode( '|', array( $task_id, $viewer_id, $expires, $generation ) );
        return add_query_arg( array( 'viewer' => $viewer_id, 'expires' => $expires, 'generation' => $generation, 'signature' => hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) ), rest_url( 'pandatask/v1/protected-attachments/' . $task_id ) );
    }

    private static function resolveSignedViewer( $request, $task_id, $registry ) {
        $viewer = (int) $request->get_param( 'viewer' ); $expires = (int) $request->get_param( 'expires' );
        $generation = (int) $request->get_param( 'generation' ); $signature = (string) $request->get_param( 'signature' );
        if ( $viewer <= 0 || $expires < time() || $generation !== (int) ( $registry['generation'] ?? 1 ) || '' === $signature ) return 0;
        $expected = hash_hmac( 'sha256', implode( '|', array( $task_id, $viewer, $expires, $generation ) ), wp_salt( 'auth' ) );
        return hash_equals( $expected, $signature ) ? $viewer : 0;
    }

    private static function registry( $task_id ) {
        $value = get_option( self::OPTION_PREFIX . (int) $task_id, array() ); return is_array( $value ) ? $value : array();
    }
    private static function saveRegistry( $task_id, $value ) { update_option( self::OPTION_PREFIX . (int) $task_id, $value, false ); }
    private static function baseDir() {
        $configured = defined( 'PANDATASK_PROTECTED_MEDIA_DIR' ) ? PANDATASK_PROTECTED_MEDIA_DIR : '';
        return wp_normalize_path( (string) apply_filters( 'pandatask_protected_media_dir', $configured ?: dirname( rtrim( ABSPATH, '/\\' ) ) . '/.pandatask-media' ) );
    }

    private static function pathForKey( $key, $must_exist ) {
        $key = ltrim( wp_normalize_path( (string) $key ), '/' ); if ( '' === $key || false !== strpos( $key, '..' ) ) return false;
        $base = self::baseDir(); if ( self::pathIsPublic( $base ) ) return $must_exist ? false : new WP_Error( 'pandatask_storage_public' );
        if ( ! is_dir( $base ) && ! $must_exist && ! wp_mkdir_p( $base ) ) return new WP_Error( 'pandatask_storage_unavailable' );
        if ( is_dir( $base ) ) self::normalizePermissions( $base );
        $path = wp_normalize_path( $base . '/' . $key ); if ( 0 !== strpos( $path, trailingslashit( $base ) ) ) return false;
        return $must_exist && ! is_file( $path ) ? false : $path;
    }

    private static function normalizePermissions( $path ) {
        $owner = @fileowner( ABSPATH ); $group = @filegroup( ABSPATH ); $base = wp_normalize_path( self::baseDir() );
        $directory = is_dir( $path ) ? $path : dirname( $path ); $directories = array();
        while ( $directory ) {
            $normalized = wp_normalize_path( $directory );
            if ( $normalized !== $base && 0 !== strpos( $normalized . '/', trailingslashit( $base ) ) ) break;
            $directories[] = $directory; if ( $normalized === $base ) break; $directory = dirname( $directory );
        }
        foreach ( array_reverse( $directories ) as $item ) { @chmod( $item, 0750 ); if ( false !== $owner ) @chown( $item, $owner ); if ( false !== $group ) @chgrp( $item, $group ); }
        if ( is_file( $path ) ) { @chmod( $path, 0640 ); if ( false !== $owner ) @chown( $path, $owner ); if ( false !== $group ) @chgrp( $path, $group ); }
    }

    private static function pathIsPublic( $path ) {
        $path = trailingslashit( wp_normalize_path( $path ) ); $uploads = wp_get_upload_dir();
        foreach ( array_filter( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', empty( $uploads['error'] ) ? $uploads['basedir'] : '' ) ) as $root ) if ( 0 === strpos( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) return true;
        return false;
    }

    private static function findProtectedSourceForAttachment( $attachment_id, $exclude_task_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pandat69_tasks';
        $task_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_post_id = %d AND id <> %d ORDER BY id ASC",
            $attachment_id,
            $exclude_task_id
        ) );
        foreach ( $task_ids as $task_id ) {
            $registry = self::registry( (int) $task_id );
            if ( empty( $registry['key'] ) ) continue;
            $path = self::pathForKey( $registry['key'], true );
            if ( $path && is_readable( $path ) ) return $path;
        }
        return false;
    }

    private static function notFound() { return new WP_Error( 'pandatask_attachment_not_found', 'Protected task attachment was not found.', array( 'status' => 404 ) ); }
}
