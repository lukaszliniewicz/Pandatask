<?php

namespace Pandatask\Application\Work;

final class WorkSuggestionProviderRegistry {

    private static $providers = array();

    public static function register( $provider_key, array $definition ) {
        $provider_key = sanitize_key( (string) $provider_key );
        if ( '' === $provider_key || strlen( $provider_key ) > 64 || empty( $definition['list_callback'] ) || ! is_callable( $definition['list_callback'] ) ) {
            return false;
        }

        $resolve_callback = $definition['resolve_callback'] ?? null;
        if ( null !== $resolve_callback && ! is_callable( $resolve_callback ) ) {
            return false;
        }

        self::$providers[ $provider_key ] = array(
            'key'              => $provider_key,
            'label'            => sanitize_text_field( $definition['label'] ?? $provider_key ),
            'list_callback'    => $definition['list_callback'],
            'resolve_callback' => $resolve_callback,
        );

        return true;
    }

    public static function get( $provider_key ) {
        $provider_key = sanitize_key( (string) $provider_key );
        return self::$providers[ $provider_key ] ?? null;
    }

    public static function all() {
        return self::$providers;
    }

    public static function reset() {
        self::$providers = array();
    }
}
