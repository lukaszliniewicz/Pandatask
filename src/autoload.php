<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

spl_autoload_register(
    static function ( $class ) {
        $prefix = 'Pandatask\\';

        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative_class = substr( $class, strlen( $prefix ) );
        $file           = __DIR__ . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
);
