<?php

namespace Pandatask\Domain\Task;

/**
 * Pure directed-graph operations shared by hierarchy and dependency rules.
 */
final class TaskGraph {

    /**
     * Determine whether adding from -> to would close a directed cycle.
     *
     * @param array<int,array<int,int>> $edges Existing adjacency list.
     */
    public static function wouldCreateCycle( array $edges, int $from, int $to ): bool {
        if ( $from <= 0 || $to <= 0 ) {
            return false;
        }

        if ( $from === $to ) {
            return true;
        }

        $frontier = array( $to );
        $visited  = array();

        while ( $frontier ) {
            $node = (int) array_pop( $frontier );

            if ( $node === $from ) {
                return true;
            }

            if ( $node <= 0 || isset( $visited[ $node ] ) ) {
                continue;
            }

            $visited[ $node ] = true;

            foreach ( $edges[ $node ] ?? array() as $next ) {
                $next = (int) $next;

                if ( ! isset( $visited[ $next ] ) ) {
                    $frontier[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * Return every node that participates in at least one directed cycle.
     *
     * @param array<int,array<int,int>> $edges Adjacency list.
     * @return array<int,int>
     */
    public static function findCycleNodes( array $edges ): array {
        $state       = array();
        $stack       = array();
        $stack_index = array();
        $cycle_nodes = array();

        $visit = static function ( int $node ) use ( &$visit, $edges, &$state, &$stack, &$stack_index, &$cycle_nodes ): void {
            $state[ $node ]       = 1;
            $stack_index[ $node ] = count( $stack );
            $stack[]              = $node;

            foreach ( $edges[ $node ] ?? array() as $next ) {
                $next = (int) $next;

                if ( ! isset( $state[ $next ] ) ) {
                    $visit( $next );
                } elseif ( 1 === $state[ $next ] ) {
                    $cycle_start = $stack_index[ $next ];

                    foreach ( array_slice( $stack, $cycle_start ) as $cycle_node ) {
                        $cycle_nodes[ $cycle_node ] = $cycle_node;
                    }
                }
            }

            array_pop( $stack );
            unset( $stack_index[ $node ] );
            $state[ $node ] = 2;
        };

        foreach ( array_keys( $edges ) as $node ) {
            $node = (int) $node;

            if ( ! isset( $state[ $node ] ) ) {
                $visit( $node );
            }
        }

        sort( $cycle_nodes );

        return array_values( $cycle_nodes );
    }
}
