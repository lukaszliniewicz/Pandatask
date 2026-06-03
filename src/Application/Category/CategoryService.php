<?php

namespace Pandatask\Application\Category;

use Pandatask\Infrastructure\Persistence\DatabaseContext;
use Pandatask\Infrastructure\Persistence\CategoryRepository;

final class CategoryService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new CategoryRepository();
    }

    public function getCategories( $board_name ) {
        $version       = DatabaseContext::getBoardCacheVersion( $board_name, 'categories' );
        $transient_key = "pandat69_categories_{$board_name}_{$version}";
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $categories = $this->repository->findForBoard( $board_name );
        set_transient( $transient_key, $categories, 12 * HOUR_IN_SECONDS );

        return $categories;
    }

    public function addCategory( $board_name, $category_name ) {
        $category_id = $this->repository->create( $board_name, $category_name );

        if ( $category_id ) {
            DatabaseContext::invalidateBoardCache( $board_name );
        }

        return $category_id;
    }

    public function deleteCategory( $category_id, $board_name ) {
        $result = $this->repository->delete( $category_id, $board_name );

        if ( $result ) {
            DatabaseContext::invalidateBoardCache( $board_name );
        }

        return $result;
    }

    public function isCategoryOnBoard( $category_id, $board_name ) {
        return $this->repository->existsOnBoard( $category_id, $board_name );
    }
}
