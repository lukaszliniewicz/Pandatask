<?php

namespace Pandatask\Domain\Task;

use InvalidArgumentException;
use WP_Error;

/**
 * Canonical task checklist representation and input validation.
 */
final class TaskChecklist {

    public const MAX_ITEMS = 100;

    public const MAX_TEXT_LENGTH = 500;

    /**
     * Keep malformed payloads bounded before WordPress sanitizes them.
     */
    private const MAX_RAW_TEXT_LENGTH = 2000;

    /**
     * Decode the stored JSON value without treating malformed data as empty.
     *
     * @param mixed $raw_json Stored JSON, or null for a legacy row.
     * @return array<int,array{id:string,text:string,checked:bool}>
     * @throws InvalidArgumentException If the stored value is not canonical JSON.
     */
    public static function decode( $raw_json ) {
        if ( null === $raw_json ) {
            return array();
        }

        if ( ! is_string( $raw_json ) || '' === $raw_json ) {
            throw new InvalidArgumentException( 'Task checklist JSON must be a nonempty string or null.' );
        }

        $json = trim( $raw_json );
        if ( '' === $json || '[' !== $json[0] || ']' !== substr( $json, -1 ) ) {
            throw new InvalidArgumentException( 'Task checklist JSON must contain an array.' );
        }

        $decoded = json_decode( $json, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            throw new InvalidArgumentException( 'Task checklist JSON is malformed.' );
        }

        if ( ! is_array( $decoded ) ) {
            throw new InvalidArgumentException( 'Task checklist JSON must contain an array.' );
        }

        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) || ! array_key_exists( 'id', $item ) ) {
                throw new InvalidArgumentException( 'Stored task checklist items are not canonical.' );
            }
        }

        $normalized = self::normalize( $decoded );

        if ( is_wp_error( $normalized ) ) {
            throw new InvalidArgumentException( 'Stored task checklist items are not canonical.' );
        }

        return $normalized;
    }

    /**
     * Validate and canonicalize a checklist submitted by a client.
     *
     * @param mixed $items Candidate checklist list.
     * @return array<int,array{id:string,text:string,checked:bool}>|WP_Error
     */
    public static function normalize( $items ) {
        if ( ! is_array( $items ) ) {
            return self::invalid( 'Checklist items must be an array.', 'items' );
        }

        if ( count( $items ) > self::MAX_ITEMS ) {
            return self::invalid( 'A checklist may contain at most 100 items.', 'items' );
        }

        $normalized = array();
        $seen_ids   = array();

        foreach ( $items as $index => $item ) {
            if ( $index !== count( $normalized ) ) {
                return self::invalid( 'Checklist items must be a plain ordered array.', 'items' );
            }

            if ( ! is_array( $item ) ) {
                return self::invalid( 'Each checklist item must be an object.', 'items' );
            }

            foreach ( array_keys( $item ) as $key ) {
                if ( ! in_array( $key, array( 'id', 'text', 'checked' ), true ) ) {
                    return self::invalid( 'Checklist items contain an unknown field.', 'items' );
                }
            }

            if ( ! array_key_exists( 'text', $item ) || ! array_key_exists( 'checked', $item ) ) {
                return self::invalid( 'Each checklist item requires text and checked.', 'items' );
            }

            if ( ! is_string( $item['text'] ) ) {
                return self::invalid( 'Checklist item text must be a string.', 'items' );
            }

            if ( strlen( $item['text'] ) > self::MAX_RAW_TEXT_LENGTH ) {
                return self::invalid( 'Checklist item text is too long.', 'items' );
            }

            if ( ! is_bool( $item['checked'] ) ) {
                return self::invalid( 'Checklist item checked must be a boolean.', 'items' );
            }

            $text = trim( sanitize_text_field( $item['text'] ) );
            if ( '' === $text ) {
                return self::invalid( 'Checklist item text cannot be empty.', 'items' );
            }

            $text_length = self::textLength( $text );
            if ( $text_length > self::MAX_TEXT_LENGTH ) {
                return self::invalid( 'Checklist item text is too long.', 'items' );
            }

            if ( array_key_exists( 'id', $item ) ) {
                if ( ! is_string( $item['id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $item['id'] ) ) {
                    return self::invalid( 'Checklist item id is invalid.', 'items' );
                }
                $id = $item['id'];
            } else {
                $id = self::generateId();
            }

            if ( isset( $seen_ids[ $id ] ) ) {
                return self::invalid( 'Checklist item ids must be unique.', 'items' );
            }

            $seen_ids[ $id ] = true;
            $normalized[] = array(
                'id'      => (string) $id,
                'text'    => $text,
                'checked' => $item['checked'],
            );
        }

        return $normalized;
    }

    /**
     * Return the fields safe for task API responses.
     *
     * @param object|array $raw_task Task row or an already decorated task.
     * @return array<string,mixed>
     * @throws InvalidArgumentException If stored checklist JSON is malformed.
     */
    public static function fields( $raw_task ) {
        $has_json = is_object( $raw_task ) ? property_exists( $raw_task, 'checklist_json' ) : array_key_exists( 'checklist_json', (array) $raw_task );
        $raw_json = is_object( $raw_task ) ? ( $raw_task->checklist_json ?? null ) : ( $raw_task['checklist_json'] ?? null );

        if ( $has_json ) {
            $checklist = self::decode( $raw_json );
        } else {
            $existing = is_object( $raw_task ) ? ( $raw_task->checklist ?? null ) : ( $raw_task['checklist'] ?? null );
            if ( null === $existing ) {
                $checklist = array();
            } else {
                $checklist = self::normalize( $existing );
                if ( is_wp_error( $checklist ) ) {
                    throw new InvalidArgumentException( 'Task checklist items are not canonical.' );
                }
            }
        }

        $version = is_object( $raw_task ) ? ( $raw_task->checklist_version ?? 0 ) : ( $raw_task['checklist_version'] ?? 0 );
        $version = is_numeric( $version ) ? max( 0, (int) $version ) : 0;
        $checked = count(
            array_filter(
                $checklist,
                static function ( $item ) {
                    return true === $item['checked'];
                }
            )
        );

        return array(
            'checklist'         => $checklist,
            'checklist_version' => $version,
            'checklist_total'   => count( $checklist ),
            'checklist_checked' => $checked,
        );
    }

    private static function generateId() {
        return (string) wp_generate_uuid4();
    }

    private static function textLength( $text ) {
        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $text, 'UTF-8' );
        }

        $count = preg_match_all( '/./us', $text, $matches );

        return false === $count ? strlen( $text ) : $count;
    }

    private static function invalid( $message, $param ) {
        return new WP_Error( 'rest_invalid_param', $message, array( 'status' => 422, 'param' => $param ) );
    }
}
