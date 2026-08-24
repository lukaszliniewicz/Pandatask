<?php

namespace Pandatask\Application\Security;

use Pandatask\Infrastructure\Persistence\WorkEntryRepository;
use WP_Error;

final class WorkEntryAccessPolicy {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new WorkEntryRepository();
    }

    public function canManageEntry( $entry_id, $user_id ) {
        $entry = $this->repository->findById( (int) $entry_id );
        if ( ! $entry ) {
            return new WP_Error( 'rest_not_found', __( 'Work entry not found.', 'pandatask' ), array( 'status' => 404 ) );
        }
        if ( (int) $entry->user_id === (int) $user_id || user_can( (int) $user_id, 'manage_options' ) ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', __( 'You cannot manage this work entry.', 'pandatask' ), array( 'status' => 403 ) );
    }
}
