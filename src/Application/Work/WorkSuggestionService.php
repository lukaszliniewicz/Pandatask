<?php

namespace Pandatask\Application\Work;

use Throwable;
use Pandatask\Domain\Work\ActivityTypes;
use Pandatask\Infrastructure\Persistence\WorkEntryRepository;
use Pandatask\Infrastructure\Persistence\WorkSuggestionDecisionRepository;
use WP_Error;

final class WorkSuggestionService {

    private $decision_repository;
    private $work_service;
    private $work_repository;

    public function __construct( $decision_repository = null, $work_service = null, $work_repository = null ) {
        $this->decision_repository = $decision_repository ?: new WorkSuggestionDecisionRepository();
        $this->work_service        = $work_service ?: new WorkEntryService();
        $this->work_repository     = $work_repository ?: new WorkEntryRepository();
    }

    public function listForUser( $user_id, array $context = array() ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }

        $decisions = array();
        foreach ( $this->decision_repository->findForUser( $user_id ) as $decision ) {
            $decisions[ $this->decisionKey( $decision->provider_key, $decision->external_key ) ] = $decision;
        }

        $suggestions = array();
        foreach ( WorkSuggestionProviderRegistry::all() as $provider_key => $provider ) {
            try {
                $items = call_user_func( $provider['list_callback'], $user_id, $context );
            } catch ( Throwable $exception ) {
                do_action( 'pandatask_work_suggestion_provider_error', $provider_key, $exception );
                continue;
            }
            if ( is_wp_error( $items ) || ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $item ) {
                $candidate = $this->normalizeCandidate( $provider_key, $provider, $item );
                if ( ! $candidate ) {
                    continue;
                }
                if ( isset( $decisions[ $this->decisionKey( $provider_key, $candidate['external_key'] ) ] ) ) {
                    continue;
                }
                $suggestions[] = $candidate;
            }
        }

        usort(
            $suggestions,
            static function ( $left, $right ) {
                $left_time = (string) ( $left['started_at_utc'] ?? $left['work_date'] );
                $right_time = (string) ( $right['started_at_utc'] ?? $right['work_date'] );
                return strcmp( $right_time, $left_time );
            }
        );

        return array_slice( $suggestions, 0, 100 );
    }

    public function confirm( $user_id, $provider_key, $external_key, array $overrides = array(), $actor_id = null ) {
        $user_id = (int) $user_id;
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        if ( $user_id <= 0 || ( $user_id !== $actor_id && ! user_can( $actor_id, 'manage_options' ) ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You cannot confirm work for that user.', 'pandatask' ), array( 'status' => 403 ) );
        }

        $provider_key = sanitize_key( (string) $provider_key );
        $external_key = sanitize_text_field( (string) $external_key );
        $provider = WorkSuggestionProviderRegistry::get( $provider_key );
        if ( ! $provider || '' === $external_key || strlen( $external_key ) > 191 ) {
            return new WP_Error( 'pandatask_work_suggestion_not_found', __( 'That work suggestion is no longer available.', 'pandatask' ), array( 'status' => 404 ) );
        }

        $decision = $this->decision_repository->find( $user_id, $provider_key, $external_key );
        if ( $decision && 'confirmed' === $decision->decision ) {
            $entry = $decision->work_entry_id ? $this->work_repository->findById( (int) $decision->work_entry_id ) : null;
            return array( 'decision' => $decision, 'entry' => $entry, 'already_confirmed' => true );
        }
        if ( $decision && 'dismissed' === $decision->decision ) {
            return new WP_Error( 'pandatask_work_suggestion_dismissed', __( 'That work suggestion was already dismissed.', 'pandatask' ), array( 'status' => 409 ) );
        }

        $candidate = $this->resolveCandidate( $user_id, $provider_key, $provider, $external_key );
        if ( is_wp_error( $candidate ) ) {
            return $candidate;
        }

        $duration = array_key_exists( 'duration_seconds', $overrides )
            ? absint( $overrides['duration_seconds'] )
            : (int) $candidate['duration_seconds'];
        if ( $duration <= 0 ) {
            return new WP_Error( 'rest_invalid_param', __( 'Work duration must be greater than zero.', 'pandatask' ), array( 'status' => 422 ) );
        }

        $has_allocation_override = array_key_exists( 'allocations', $overrides ) && is_array( $overrides['allocations'] );
        $allocations = $has_allocation_override
            ? array_values( $overrides['allocations'] )
            : (array) ( $candidate['allocations'] ?? array() );
        if (
            ! $has_allocation_override
            && 1 === count( $allocations )
            && (int) ( $allocations[0]['seconds'] ?? 0 ) === (int) $candidate['duration_seconds']
        ) {
            $allocations[0]['seconds'] = $duration;
        }

        $started_at_utc = $candidate['started_at_utc'] ?? null;
        $ended_at_utc = $candidate['ended_at_utc'] ?? null;
        if ( $started_at_utc && $duration !== (int) $candidate['duration_seconds'] ) {
            try {
                $ended_at_utc = ( new \DateTimeImmutable( $started_at_utc ) )
                    ->modify( '+' . $duration . ' seconds' )
                    ->setTimezone( new \DateTimeZone( 'UTC' ) )
                    ->format( DATE_ATOM );
            } catch ( \Exception $exception ) {
                $ended_at_utc = null;
            }
        }

        $entry_input = array(
            'user_id'          => $user_id,
            'title'            => sanitize_text_field( $overrides['title'] ?? $candidate['title'] ),
            'notes'            => isset( $overrides['notes'] ) ? wp_kses_post( $overrides['notes'] ) : ( $candidate['notes'] ?? null ),
            'activity_type'    => sanitize_key( $overrides['activity_type'] ?? $candidate['activity_type'] ),
            'capacity'         => isset( $overrides['capacity'] ) ? sanitize_key( $overrides['capacity'] ) : ( $candidate['capacity'] ?? null ),
            'work_date'        => sanitize_text_field( $overrides['work_date'] ?? $candidate['work_date'] ),
            'started_at_utc'   => $started_at_utc,
            'ended_at_utc'     => $ended_at_utc,
            'timezone'         => $candidate['timezone'] ?? null,
            'duration_seconds' => $duration,
            'visibility'       => $candidate['visibility'] ?? 'private',
            'allocations'      => $allocations,
        );

        $source_key = $this->sourceKey( $user_id, $provider_key, $external_key );
        $entry = $this->work_service->createSourcedEntry(
            $entry_input,
            $source_key,
            $candidate['source_url'] ?? null,
            $actor_id
        );
        if ( is_wp_error( $entry ) ) {
            return $entry;
        }
        if ( ! $this->decision_repository->save( $user_id, $provider_key, $external_key, 'confirmed', (int) $entry->id ) ) {
            return new WP_Error( 'pandatask_work_suggestion_decision_failed', __( 'The work was recorded, but its suggestion state could not be saved. Confirm it again to reconcile the state.', 'pandatask' ), array( 'status' => 500, 'work_entry_id' => (int) $entry->id ) );
        }

        return array(
            'decision' => $this->decision_repository->find( $user_id, $provider_key, $external_key ),
            'entry'    => $entry,
            'already_confirmed' => false,
        );
    }

    public function dismiss( $user_id, $provider_key, $external_key, $actor_id = null ) {
        $user_id = (int) $user_id;
        $actor_id = null === $actor_id ? get_current_user_id() : (int) $actor_id;
        if ( $user_id <= 0 || ( $user_id !== $actor_id && ! user_can( $actor_id, 'manage_options' ) ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You cannot dismiss work suggestions for that user.', 'pandatask' ), array( 'status' => 403 ) );
        }
        $provider_key = sanitize_key( (string) $provider_key );
        $external_key = sanitize_text_field( (string) $external_key );
        if ( ! WorkSuggestionProviderRegistry::get( $provider_key ) || '' === $external_key || strlen( $external_key ) > 191 ) {
            return new WP_Error( 'pandatask_work_suggestion_not_found', __( 'That work suggestion is no longer available.', 'pandatask' ), array( 'status' => 404 ) );
        }
        $existing = $this->decision_repository->find( $user_id, $provider_key, $external_key );
        if ( $existing && 'confirmed' === $existing->decision ) {
            return new WP_Error( 'pandatask_work_suggestion_confirmed', __( 'Confirmed work cannot be dismissed as a suggestion. Delete or edit the work entry instead.', 'pandatask' ), array( 'status' => 409 ) );
        }
        if ( ! $this->decision_repository->save( $user_id, $provider_key, $external_key, 'dismissed', null ) ) {
            return new WP_Error( 'pandatask_work_suggestion_decision_failed', __( 'The suggestion could not be dismissed.', 'pandatask' ), array( 'status' => 500 ) );
        }
        return $this->decision_repository->find( $user_id, $provider_key, $external_key );
    }

    private function resolveCandidate( $user_id, $provider_key, array $provider, $external_key ) {
        try {
            if ( ! empty( $provider['resolve_callback'] ) ) {
                $item = call_user_func( $provider['resolve_callback'], $user_id, $external_key );
            } else {
                $items = call_user_func( $provider['list_callback'], $user_id, array( 'external_key' => $external_key ) );
                $item = null;
                foreach ( is_array( $items ) ? $items : array() as $possible ) {
                    if ( (string) ( $possible['external_key'] ?? '' ) === (string) $external_key ) {
                        $item = $possible;
                        break;
                    }
                }
            }
        } catch ( Throwable $exception ) {
            do_action( 'pandatask_work_suggestion_provider_error', $provider_key, $exception );
            return new WP_Error( 'pandatask_work_suggestion_provider_failed', __( 'The work provider could not verify that suggestion.', 'pandatask' ), array( 'status' => 503 ) );
        }
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        $candidate = $this->normalizeCandidate( $provider_key, $provider, $item );
        return $candidate ?: new WP_Error( 'pandatask_work_suggestion_not_found', __( 'That work suggestion is no longer available.', 'pandatask' ), array( 'status' => 404 ) );
    }

    private function normalizeCandidate( $provider_key, array $provider, $item ) {
        if ( ! is_array( $item ) ) {
            return null;
        }
        $external_key = sanitize_text_field( (string) ( $item['external_key'] ?? '' ) );
        $activity_type = sanitize_key( (string) ( $item['activity_type'] ?? '' ) );
        $work_date = sanitize_text_field( (string) ( $item['work_date'] ?? '' ) );
        $duration = absint( $item['duration_seconds'] ?? 0 );
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $work_date, wp_timezone() );
        if ( '' === $external_key || strlen( $external_key ) > 191 || ! ActivityTypes::isValid( $activity_type ) || $duration <= 0 || ! $date || $date->format( 'Y-m-d' ) !== $work_date ) {
            return null;
        }
        return array(
            'provider_key'     => $provider_key,
            'provider_label'   => $provider['label'],
            'external_key'     => $external_key,
            'title'            => sanitize_text_field( $item['title'] ?? ActivityTypes::label( $activity_type ) ),
            'reason'           => sanitize_text_field( $item['reason'] ?? '' ),
            'notes'            => isset( $item['notes'] ) ? wp_kses_post( $item['notes'] ) : null,
            'activity_type'    => $activity_type,
            'capacity'         => in_array( sanitize_key( $item['capacity'] ?? '' ), array( 'paid', 'volunteer', 'other' ), true ) ? sanitize_key( $item['capacity'] ) : null,
            'work_date'        => $work_date,
            'started_at_utc'   => isset( $item['started_at_utc'] ) ? sanitize_text_field( $item['started_at_utc'] ) : null,
            'ended_at_utc'     => isset( $item['ended_at_utc'] ) ? sanitize_text_field( $item['ended_at_utc'] ) : null,
            'timezone'         => isset( $item['timezone'] ) ? sanitize_text_field( $item['timezone'] ) : null,
            'duration_seconds' => $duration,
            'source_url'       => isset( $item['source_url'] ) ? esc_url_raw( $item['source_url'] ) : null,
            'visibility'       => in_array( sanitize_key( $item['visibility'] ?? 'private' ), array( 'private', 'aggregate', 'shared' ), true ) ? sanitize_key( $item['visibility'] ?? 'private' ) : 'private',
            'allocations'      => is_array( $item['allocations'] ?? null ) ? array_values( $item['allocations'] ) : array(),
            'metadata'         => is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array(),
        );
    }

    private function sourceKey( $user_id, $provider_key, $external_key ) {
        return sprintf(
            'work-suggestion:%s:%d:%s',
            sanitize_key( (string) $provider_key ),
            (int) $user_id,
            hash( 'sha256', (string) $external_key )
        );
    }

    private function decisionKey( $provider_key, $external_key ) {
        return sanitize_key( (string) $provider_key ) . ':' . (string) $external_key;
    }
}
