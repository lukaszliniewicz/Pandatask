<?php

namespace Pandatask\Application\Task;

use Pandatask\Infrastructure\Persistence\TaskHistoryRepository;

final class HistoryService {

    private $repository;

    public function __construct( $repository = null ) {
        $this->repository = $repository ?: new TaskHistoryRepository();
    }

    public function addEntry( $task_id, $user_id, $field_changed, $old_value = '', $new_value = '', $change_comment = '' ) {
        return $this->repository->addEntry( $task_id, $user_id, $field_changed, $old_value, $new_value, $change_comment );
    }

    public function getTaskHistory( $task_id ) {
        return $this->repository->getTaskHistory( $task_id );
    }
}
