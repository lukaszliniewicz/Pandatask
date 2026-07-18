<?php

namespace Pandatask\Http\Rest\V1\Support;

final class RequestHelper {

    public static function bodyParams( $request ) {
        $json_params = $request->get_json_params();

        if ( ! empty( $json_params ) ) {
            return $json_params;
        }

        $body_params = $request->get_body_params();

        return is_array( $body_params ) ? $body_params : array();
    }

    public static function parseIdList( $input ) {
        if ( is_array( $input ) ) {
            return array_map( 'absint', $input );
        }

        if ( is_string( $input ) && '' !== $input ) {
            return array_map( 'absint', explode( ',', $input ) );
        }

        return array();
    }

    public static function renderTask( $task ) {
        if ( ! $task ) {
            return $task;
        }

        $task->description_rendered = '';

        if ( ! empty( $task->description ) ) {
            $task->description_rendered = wp_kses_post( wpautop( wp_kses_post( $task->description ) ) );
        }

        return $task;
    }

    public static function renderTaskCollection( $tasks ) {
        if ( ! is_array( $tasks ) ) {
            return $tasks;
        }

        foreach ( $tasks as $task ) {
            self::renderTask( $task );
        }

        return $tasks;
    }

    public static function isMinimalResponse( $request ) {
        return 'minimal' === $request['response_format'];
    }
}
