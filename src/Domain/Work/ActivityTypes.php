<?php

namespace Pandatask\Domain\Work;

final class ActivityTypes {

    private const TYPES = array(
        'meeting'        => 'Meeting',
        'call'           => 'Call',
        'correspondence' => 'Email / messages',
        'research'       => 'Research',
        'writing'        => 'Writing / documents',
        'development'    => 'Development',
        'planning'       => 'Planning / coordination',
        'administration' => 'Administration',
        'event'          => 'Event / representation',
        'travel'         => 'Travel',
        'other'          => 'Other',
    );

    public static function all() {
        return self::TYPES;
    }

    public static function isValid( $activity_type ) {
        return is_string( $activity_type ) && array_key_exists( $activity_type, self::TYPES );
    }

    public static function label( $activity_type ) {
        return self::TYPES[ $activity_type ] ?? '';
    }
}
