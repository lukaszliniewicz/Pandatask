<?php

namespace Pandatask\Application\User;

use Pandatask\Infrastructure\Persistence\UserDirectoryRepository;

final class UserDirectoryService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new UserDirectoryRepository();
    }

    public function getUsersForBoard( $board_name, $search = '', $include = array() ) {
        if ( $board_name && preg_match( '/^group_(\d+)$/', $board_name, $matches ) ) {
            return $this->getBuddyPressUsers( $search, intval( $matches[1] ), $include );
        }

        return $this->getWordPressUsers( $search, $include );
    }

    public function getBuddyPressUsers( $search = '', $group_id = 0, $include = array() ) {
        $cache_version = defined( 'PANDAT69_VERSION' ) ? PANDAT69_VERSION : '1.0';
        $search_key    = md5( $search . '_' . $group_id . '_' . implode( ',', $include ) );
        $transient_key = 'pandat69_bp_users_v2_' . $cache_version . '_' . $search_key;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $users = $this->repository->findBuddyPressUsers( $search, $group_id, $include );
        set_transient( $transient_key, $users, 5 * MINUTE_IN_SECONDS );

        return $users;
    }

    public function getWordPressUsers( $search = '', $include = array() ) {
        $cache_version = defined( 'PANDAT69_VERSION' ) ? PANDAT69_VERSION : '1.0';
        $search_key    = md5( $search . '_' . implode( ',', $include ) );
        $transient_key = 'pandat69_wp_users_v2_' . $cache_version . '_' . $search_key;
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $users = $this->repository->findWordPressUsers( $search, $include );
        set_transient( $transient_key, $users, 5 * MINUTE_IN_SECONDS );

        return $users;
    }
}
