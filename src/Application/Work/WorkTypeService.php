<?php

namespace Pandatask\Application\Work;

use Pandatask\Domain\Work\ActivityTypes;
use WP_Error;

/**
 * Resolves the built-in work vocabulary and a user's customisations.
 *
 * Built-in keys are intentionally never copied into or removed from user
 * meta. Their labels and active state are represented as per-user overrides.
 */
final class WorkTypeService {

    const USER_META_KEY       = 'pandatask_work_types';
    const MAX_KEY_LENGTH      = 32;
    const MAX_LABEL_LENGTH    = 80;

    public function all( $user_id = null ) {
        $user_id  = $this->resolveUserId( $user_id );
        $overrides = $this->storedTypes( $user_id );
        $types     = array();

        foreach ( ActivityTypes::all() as $key => $default_label ) {
            $override = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();
            $types[]  = array(
                'key'        => $key,
                'label'      => isset( $override['label'] ) && '' !== $override['label'] ? (string) $override['label'] : $default_label,
                'is_system'  => true,
                'is_active'  => ! array_key_exists( 'is_active', $override ) || (bool) $override['is_active'],
            );
        }

        foreach ( $overrides as $key => $override ) {
            if ( ActivityTypes::isValid( $key ) || ! is_array( $override ) ) {
                continue;
            }

            $types[] = array(
                'key'       => (string) $key,
                'label'     => (string) ( $override['label'] ?? $key ),
                'is_system' => false,
                'is_active' => ! array_key_exists( 'is_active', $override ) || (bool) $override['is_active'],
            );
        }

        return $types;
    }

    public function find( $key, $user_id = null ) {
        $key = sanitize_key( (string) $key );
        foreach ( $this->all( $user_id ) as $type ) {
            if ( $type['key'] === $key ) {
                return $type;
            }
        }

        return null;
    }

    public function isKnown( $key, $user_id = null ) {
        return null !== $this->find( $key, $user_id );
    }

    public function isActive( $key, $user_id = null ) {
        $type = $this->find( $key, $user_id );
        return $type && ! empty( $type['is_active'] );
    }

    public function label( $key, $user_id = null ) {
        $type = $this->find( $key, $user_id );
        return $type ? $type['label'] : '';
    }

    public function create( $label, $user_id = null ) {
        $user_id = $this->resolveUserId( $user_id );
        $label   = $this->normalizeLabel( $label );
        if ( is_wp_error( $label ) ) {
            return $label;
        }
        if ( $this->hasActiveLabel( $label, $user_id ) ) {
            return $this->error( 'pandatask_work_type_duplicate', __( 'An active work type already uses that label.', 'pandatask' ), 409 );
        }

        $stored = $this->storedTypes( $user_id );
        $key    = $this->newKey( $label, $stored );
        $stored[ $key ] = array(
            'label'      => $label,
            'is_active'  => true,
            'is_system'  => false,
        );
        if ( ! update_user_meta( $user_id, self::USER_META_KEY, $stored ) ) {
            return $this->error( 'pandatask_work_type_save_failed', __( 'The work type could not be saved.', 'pandatask' ), 500 );
        }

        return $this->find( $key, $user_id );
    }

    public function update( $key, array $changes, $user_id = null ) {
        $user_id = $this->resolveUserId( $user_id );
        $key     = sanitize_key( (string) $key );
        $current = $this->find( $key, $user_id );
        if ( ! $current ) {
            return $this->error( 'rest_not_found', __( 'Work type not found.', 'pandatask' ), 404 );
        }

        $next_label = $current['label'];
        if ( array_key_exists( 'label', $changes ) ) {
            $next_label = $this->normalizeLabel( $changes['label'] );
            if ( is_wp_error( $next_label ) ) {
                return $next_label;
            }
        }

        $next_active = $current['is_active'];
        if ( array_key_exists( 'is_active', $changes ) ) {
            $next_active = $this->normalizeActive( $changes['is_active'] );
            if ( is_wp_error( $next_active ) ) {
                return $next_active;
            }
        }

        if ( $next_active && $this->hasActiveLabel( $next_label, $user_id, $key ) ) {
            return $this->error( 'pandatask_work_type_duplicate', __( 'An active work type already uses that label.', 'pandatask' ), 409 );
        }
        if ( $current['is_active'] && ! $next_active && $this->activeCount( $user_id ) <= 1 ) {
            return $this->error( 'pandatask_work_type_last_active', __( 'At least one work type must remain active.', 'pandatask' ), 409 );
        }

        $stored = $this->storedTypes( $user_id );
        $next_stored = array(
            'label'      => $next_label,
            'is_active'  => (bool) $next_active,
            'is_system'  => (bool) $current['is_system'],
        );
        if ( isset( $stored[ $key ] ) && $stored[ $key ] === $next_stored ) {
            return $current;
        }
        $stored[ $key ] = $next_stored;
        if ( ! update_user_meta( $user_id, self::USER_META_KEY, $stored ) ) {
            return $this->error( 'pandatask_work_type_save_failed', __( 'The work type could not be saved.', 'pandatask' ), 500 );
        }

        return $this->find( $key, $user_id );
    }

    public function archive( $key, $user_id = null ) {
        return $this->update( $key, array( 'is_active' => false ), $user_id );
    }

    private function resolveUserId( $user_id ) {
        $user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
        return max( 0, (int) $user_id );
    }

    private function storedTypes( $user_id ) {
        if ( $user_id <= 0 ) {
            return array();
        }
        $stored = get_user_meta( $user_id, self::USER_META_KEY, true );
        if ( ! is_array( $stored ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $stored as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || strlen( $key ) > self::MAX_KEY_LENGTH || ! is_array( $value ) ) {
                continue;
            }
            $label = isset( $value['label'] ) ? $this->normalizeStoredLabel( $value['label'] ) : '';
            if ( '' === $label ) {
                continue;
            }
            $normalized[ $key ] = array(
                'label'      => $label,
                'is_active'  => ! array_key_exists( 'is_active', $value ) || (bool) $value['is_active'],
                'is_system'  => ! empty( $value['is_system'] ),
            );
        }

        return $normalized;
    }

    private function normalizeLabel( $label ) {
        $label = trim( sanitize_text_field( (string) $label ) );
        if ( '' === $label ) {
            return $this->error( 'pandatask_work_type_invalid_label', __( 'Work type labels cannot be empty.', 'pandatask' ), 422 );
        }
        if ( strlen( $label ) > self::MAX_LABEL_LENGTH ) {
            return $this->error( 'pandatask_work_type_invalid_label', __( 'Work type labels must be 80 characters or fewer.', 'pandatask' ), 422 );
        }
        return $label;
    }

    private function normalizeStoredLabel( $label ) {
        $label = trim( sanitize_text_field( (string) $label ) );
        return strlen( $label ) <= self::MAX_LABEL_LENGTH ? $label : substr( $label, 0, self::MAX_LABEL_LENGTH );
    }

    private function normalizeActive( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( in_array( $value, array( 0, 1, '0', '1' ), true ) ) {
            return (bool) absint( $value );
        }
        return $this->error( 'pandatask_work_type_invalid_active', __( 'is_active must be a boolean.', 'pandatask' ), 422 );
    }

    private function hasActiveLabel( $label, $user_id, $ignore_key = '' ) {
        $needle = $this->foldLabel( $label );
        foreach ( $this->all( $user_id ) as $type ) {
            if ( $type['key'] !== $ignore_key && $type['is_active'] && $this->foldLabel( $type['label'] ) === $needle ) {
                return true;
            }
        }
        return false;
    }

    private function activeCount( $user_id ) {
        return count(
            array_filter(
                $this->all( $user_id ),
                static function ( $type ) {
                    return ! empty( $type['is_active'] );
                }
            )
        );
    }

    private function foldLabel( $label ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
    }

    private function newKey( $label, array $stored ) {
        $base = sanitize_key( $label );
        $base = '' !== $base ? substr( $base, 0, self::MAX_KEY_LENGTH ) : 'work_type';
        $candidate = $base;
        $suffix    = 1;
        while ( ActivityTypes::isValid( $candidate ) || isset( $stored[ $candidate ] ) ) {
            $suffix++;
            $suffix_text = '_' . $suffix;
            $candidate   = substr( $base, 0, self::MAX_KEY_LENGTH - strlen( $suffix_text ) ) . $suffix_text;
        }
        return $candidate;
    }

    private function error( $code, $message, $status ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }
}
