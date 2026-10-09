<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;
use OCP\TextProcessing\IManager as TextManager;
use OCP\TextProcessing\Task as TextTask;

/**
 * Tasks the app queued in Nextcloud's own AI queues before 5.7 (spec 4.3). Since 5.7 every model
 * call goes through LlmGateway; these tasks would reach whatever provider Nextcloud has, outside
 * the gateway and without the 5.7 consent, so they must not be processed.
 *
 * scan() — once, from the upgrade (MigrateLlmConfiguration):
 * - TaskProcessing, status SCHEDULED: cancelled and deleted.
 * - TaskProcessing, status CANCELLED: deleted. cancelTask() commits CANCELLED on its own, so a run
 *   that died before deleteTask() leaves exactly this; the next run finishes the job.
 * - TaskProcessing, status RUNNING: left to finish and listed in `ai_legacy_tasks_pending`.
 * - TextProcessing (the NC <= 29 queue the same legacy path fell back to), status SCHEDULED:
 *   deleted, which also removes the background job that would run it.
 * Any failure throws; nothing is marked, so the caller can retry the whole scan.
 */
class LegacyAiTaskCleanup {
    public const APP = 'learning';
    public const PENDING_TASKS = 'ai_legacy_tasks_pending';

    public function __construct(
        private TaskManager $tasks,
        private TextManager $textTasks,
        private IUserManager $users,
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * @return array{cancelled: int, deleted: int, running: int, text_deleted: int}
     * @throws \Throwable whatever the managers throw; the scan is then incomplete
     */
    public function scan(): array {
        /** @var list<Task> $found */
        $found = [];
        /** @var list<TextTask> $foundText */
        $foundText = [];
        $this->users->callForAllUsers(function (IUser $user) use (&$found, &$foundText): void {
            foreach ($this->tasks->getUserTasksByApp($user->getUID(), self::APP) as $task) {
                $found[] = $task;
            }
            foreach ($this->textTasks->getUserTasksByApp($user->getUID(), self::APP) as $task) {
                $foundText[] = $task;
            }
        });

        $cancelled = 0;
        $deleted = 0;
        /** @var list<int> $running */
        $running = [];
        foreach ($found as $task) {
            $id = $task->getId();
            if ($id === null) {
                continue;
            }
            $status = $task->getStatus();
            if ($status === Task::STATUS_RUNNING) {
                $running[] = $id;
                continue;
            }
            if ($status === Task::STATUS_SCHEDULED) {
                try {
                    $this->tasks->cancelTask($id);
                    $cancelled++;
                } catch (NotFoundException) {
                    // gone meanwhile (deleted by Nextcloud or a parallel run): nothing to cancel
                }
            }
            if ($status === Task::STATUS_SCHEDULED || $status === Task::STATUS_CANCELLED) {
                $this->tasks->deleteTask($task);
                $deleted++;
            }
        }

        $textDeleted = 0;
        foreach ($foundText as $task) {
            if ($task->getStatus() === TextTask::STATUS_SCHEDULED) {
                $this->textTasks->deleteTask($task);
                $textDeleted++;
            }
        }

        $this->addPending($running);
        return ['cancelled' => $cancelled, 'deleted' => $deleted, 'running' => count($running), 'text_deleted' => $textDeleted];
    }

    /** @param list<int> $ids */
    private function addPending(array $ids): void {
        if ($ids === []) {
            return;
        }
        $pending = array_values(array_unique(array_merge($this->pending(), $ids)));
        $this->appConfig->setValueString(self::APP, self::PENDING_TASKS, json_encode($pending, JSON_THROW_ON_ERROR));
    }

    /** @return list<int> */
    private function pending(): array {
        $pending = json_decode($this->appConfig->getValueString(self::APP, self::PENDING_TASKS, '[]'), true);
        return is_array($pending) ? array_values(array_filter($pending, 'is_int')) : [];
    }
}
