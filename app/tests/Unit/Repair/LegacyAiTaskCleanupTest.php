<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\BackgroundJob\LegacyAiTaskCleanupJob;
use OCA\Learning\Repair\LegacyAiTaskCleanup;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;
use OCP\TextProcessing\IManager as TextManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Spec 4.3 / review finding (MED): a legacy task that was RUNNING at upgrade time is deleted once
 * it has finished. The upgrade lists it in `ai_legacy_tasks_pending` and registers
 * LegacyAiTaskCleanupJob, which sweeps the list and unregisters itself when nothing is left.
 */
class LegacyAiTaskCleanupTest extends TestCase {
    private FakeAppConfigValues $values;
    private IAppConfig $appConfig;
    /** @var array<int, Task> */
    private array $store = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<int> */
    private array $cancelled = [];
    /** @var IJobList&MockObject */
    private IJobList $jobList;
    /** @var list<array{string, string}> */
    private array $jobCalls = [];

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        $this->appConfig = $appConfig;
        $this->jobList = $this->createMock(IJobList::class);
        $this->jobList->method('add')->willReturnCallback(function (string $job): void {
            $this->jobCalls[] = ['add', $job];
        });
        $this->jobList->method('remove')->willReturnCallback(function (string $job): void {
            $this->jobCalls[] = ['remove', $job];
        });
    }

    private function cleanup(): LegacyAiTaskCleanup {
        $tasks = $this->createMock(TaskManager::class);
        $tasks->method('getUserTasksByApp')->willReturnCallback(
            fn (?string $uid, string $appId): array => array_values(array_filter(
                $this->store, fn (Task $t): bool => $t->getAppId() === $appId && $t->getUserId() === $uid)));
        $tasks->method('getTask')->willReturnCallback(function (int $id): Task {
            if (!isset($this->store[$id])) {
                throw new NotFoundException('Couldn\'t find task with id ' . $id);
            }
            return $this->store[$id];
        });
        $tasks->method('cancelTask')->willReturnCallback(function (int $id): void {
            $this->cancelled[] = $id;
            $this->store[$id]->setStatus(Task::STATUS_CANCELLED);
        });
        $tasks->method('deleteTask')->willReturnCallback(function (Task $t): void {
            $this->deleted[] = (int)$t->getId();
            unset($this->store[(int)$t->getId()]);
        });
        $text = $this->createMock(TextManager::class);
        $text->method('getUserTasksByApp')->willReturn([]);
        $users = $this->createMock(IUserManager::class);
        $users->method('callForAllUsers')->willReturnCallback(function (\Closure $cb): void {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn('alice');
            $cb($u);
        });
        return new LegacyAiTaskCleanup($tasks, $text, $users, $this->appConfig, $this->jobList);
    }

    private function task(int $id, int $status, string $app = 'learning'): void {
        $t = new Task('core:text2text', ['input' => 'legacy prompt'], $app, 'alice');
        $t->setId($id);
        $t->setStatus($status);
        $this->store[$id] = $t;
    }

    /** @param list<int> $ids */
    private function pending(array $ids): void {
        $this->values->set('learning', 'ai_legacy_tasks_pending', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    /** @return list<int>|null */
    private function pendingNow(): ?array {
        $v = $this->values->get('learning', 'ai_legacy_tasks_pending');
        return $v === null ? null : json_decode($v, true);
    }

    public function testTheScanRegistersTheJobOnlyForRunningTasks(): void {
        $this->task(1, Task::STATUS_SCHEDULED);
        $this->cleanup()->scan();
        $this->assertSame([], $this->jobCalls);

        $this->task(2, Task::STATUS_RUNNING);
        $this->cleanup()->scan();
        $this->assertSame([['add', LegacyAiTaskCleanupJob::class]], $this->jobCalls);
        $this->assertSame([2], $this->pendingNow());
    }

    public function testFinishedTasksAreDeletedRunningOnesStayListed(): void {
        $this->task(11, Task::STATUS_SUCCESSFUL);
        $this->task(12, Task::STATUS_FAILED);
        $this->task(13, Task::STATUS_CANCELLED);
        $this->task(14, Task::STATUS_RUNNING);
        $this->pending([11, 12, 13, 14]);

        $this->assertSame(1, $this->cleanup()->sweepPending());

        $this->assertSame([11, 12, 13], $this->deleted);
        $this->assertSame([14], array_keys($this->store));
        $this->assertSame([14], $this->pendingNow());
    }

    public function testTasksThatAreGoneLeaveTheListAndAnEmptyListIsRemoved(): void {
        $this->task(11, Task::STATUS_SUCCESSFUL);
        $this->pending([11, 99]);

        $this->assertSame(0, $this->cleanup()->sweepPending());

        $this->assertSame([11], $this->deleted);
        $this->assertNull($this->pendingNow(), 'nothing left to watch');
    }

    public function testAScheduledTaskOnTheListIsCancelledAndDeleted(): void {
        $this->task(11, Task::STATUS_SCHEDULED);
        $this->pending([11]);

        $this->assertSame(0, $this->cleanup()->sweepPending());

        $this->assertSame([11], $this->cancelled);
        $this->assertSame([11], $this->deleted);
    }

    public function testATaskOfAnotherAppIsNeverTouched(): void {
        $this->task(11, Task::STATUS_SUCCESSFUL, 'other_app');
        $this->pending([11]);

        $this->assertSame(0, $this->cleanup()->sweepPending());

        $this->assertSame([], $this->deleted);
        $this->assertArrayHasKey(11, $this->store);
    }

    public function testTheJobUnregistersItselfWhenNothingIsLeft(): void {
        $this->task(14, Task::STATUS_RUNNING);
        $this->pending([14]);
        $job = new LegacyAiTaskCleanupJob($this->createMock(ITimeFactory::class), $this->cleanup(), $this->jobList, $this->createMock(LoggerInterface::class));

        $run = new \ReflectionMethod($job, 'run');
        $run->invoke($job, null);
        $this->assertSame([], $this->jobCalls, 'still running: the job stays');

        $this->store[14]->setStatus(Task::STATUS_SUCCESSFUL);
        $run->invoke($job, null);
        $this->assertSame([14], $this->deleted);
        $this->assertSame([['remove', LegacyAiTaskCleanupJob::class]], $this->jobCalls);
    }
}
