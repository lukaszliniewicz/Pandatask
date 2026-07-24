<?php

namespace Pandatask\Http\Rest\V1;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Provides 24-hour, per-user idempotency for authenticated Pandatask mutations.
 */
final class IdempotencyMiddleware {

    private const LOCK_TTL_SECONDS = 300;

    private static $registered = false;

    private static $pending = array();

    public static function register() {
        if ( self::$registered ) {
            return;
        }

        self::$registered = true;
        add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'beforeCallbacks' ), 10, 3 );
        add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'afterCallbacks' ), 10, 3 );
    }

    public static function beforeCallbacks( $response, $handler, $request ) {
        if ( null !== $response || ! self::isEligibleRequest( $request ) ) {
            return $response;
        }

        $key = trim( (string) $request->get_header( 'idempotency-key' ) );

        if ( '' === $key ) {
            return $response;
        }

        if ( strlen( $key ) < 8 || strlen( $key ) > 128 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $key ) ) {
            return new WP_Error(
                'pandatask_invalid_idempotency_key',
                __( 'Idempotency-Key must contain 8-128 letters, numbers, dots, underscores, colons, or hyphens.', 'pandatask' ),
                array( 'status' => 400 )
            );
        }

        $fingerprint = self::fingerprint( $request );
        $cache_key   = self::cacheKey( $request, $key );
        $cached      = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            if ( ! hash_equals( (string) ( $cached['fingerprint'] ?? '' ), $fingerprint ) ) {
                return new WP_Error(
                    'pandatask_idempotency_conflict',
                    __( 'This idempotency key was already used with a different request.', 'pandatask' ),
                    array( 'status' => 409 )
                );
            }

            $replay = new WP_REST_Response(
                $cached['data'] ?? null,
                (int) ( $cached['status'] ?? 200 ),
                (array) ( $cached['headers'] ?? array() )
            );
            $replay->header( 'X-Pandatask-Idempotent-Replay', 'true' );

            return $replay;
        }

        $lock_key = self::lockKey( $cache_key );
        $lock     = self::acquireLock( $lock_key, $fingerprint );

        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        self::$pending[ spl_object_hash( $request ) ] = array(
            'cache_key'   => $cache_key,
            'fingerprint' => $fingerprint,
            'lock_key'    => $lock_key,
        );

        return $response;
    }

    public static function afterCallbacks( $response, $handler, $request ) {
        $request_key = spl_object_hash( $request );

        if ( ! isset( self::$pending[ $request_key ] ) ) {
            return $response;
        }

        $pending = self::$pending[ $request_key ];
        unset( self::$pending[ $request_key ] );

        if ( is_wp_error( $response ) ) {
            delete_option( $pending['lock_key'] );
            return $response;
        }

        $rest_response = rest_ensure_response( $response );
        $status        = (int) $rest_response->get_status();

        if ( $status < 200 || $status >= 300 ) {
            delete_option( $pending['lock_key'] );
            return $response;
        }

        set_transient(
            $pending['cache_key'],
            array(
                'fingerprint' => $pending['fingerprint'],
                'status'      => $status,
                'headers'     => $rest_response->get_headers(),
                'data'        => $rest_response->get_data(),
            ),
            DAY_IN_SECONDS
        );
        delete_option( $pending['lock_key'] );
        $rest_response->header( 'X-Pandatask-Idempotency-Stored', 'true' );

        return $rest_response;
    }

    private static function isEligibleRequest( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return false;
        }

        if ( 0 !== strpos( $request->get_route(), '/pandatask/v1/' ) ) {
            return false;
        }

        return in_array( strtoupper( $request->get_method() ), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
    }

    private static function cacheKey( $request, $key ) {
        $scope = implode(
            '|',
            array(
                (string) get_current_user_id(),
                strtoupper( $request->get_method() ),
                $request->get_route(),
                $key,
            )
        );

        return 'pandatask_idem_' . hash_hmac( 'sha256', $scope, wp_salt( 'auth' ) );
    }

    private static function lockKey( $cache_key ) {
        return 'pandatask_idem_lock_' . hash( 'sha256', $cache_key );
    }

    private static function acquireLock( $lock_key, $fingerprint ) {
        $lock = array(
            'fingerprint' => $fingerprint,
            'expires'     => time() + self::LOCK_TTL_SECONDS,
        );

        if ( add_option( $lock_key, $lock, '', false ) ) {
            return true;
        }

        $existing = get_option( $lock_key );

        if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) < time() ) {
            delete_option( $lock_key );
            if ( add_option( $lock_key, $lock, '', false ) ) {
                return true;
            }
            $existing = get_option( $lock_key );
        }

        if ( is_array( $existing ) && ! hash_equals( (string) ( $existing['fingerprint'] ?? '' ), $fingerprint ) ) {
            return new WP_Error(
                'pandatask_idempotency_conflict',
                __( 'This idempotency key is already processing a different request.', 'pandatask' ),
                array( 'status' => 409 )
            );
        }

        return new WP_Error(
            'pandatask_idempotency_in_progress',
            __( 'An identical request with this idempotency key is still processing. Retry shortly.', 'pandatask' ),
            array( 'status' => 409 )
        );
    }

    private static function fingerprint( $request ) {
        $params = self::normalize( $request->get_params() );

        return hash(
            'sha256',
            strtoupper( $request->get_method() ) . '|' . $request->get_route() . '|' . wp_json_encode( $params )
        );
    }

    private static function normalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
            ksort( $value );
        }

        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::normalize( $item );
        }

        return $value;
    }
}
