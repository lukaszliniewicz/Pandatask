<?php

namespace Pandatask\Application\Settings;

/**
 * Canonical application feature settings.
 */
final class FeatureSettings {

    const OPTION_NAME = 'pandatask_feature_settings';

    /**
     * Work logging is enabled by default for backwards compatibility.
     *
     * @return bool
     */
    public function workLogEnabled() {
        $settings = get_option( self::OPTION_NAME, array() );
        $enabled  = ! is_array( $settings ) || ! array_key_exists( 'work_log_enabled', $settings )
            ? true
            : (bool) $settings['work_log_enabled'];

        if ( function_exists( 'apply_filters' ) ) {
            $enabled = apply_filters( 'pandatask_work_log_enabled', $enabled, $settings );
        }

        return (bool) $enabled;
    }
}
