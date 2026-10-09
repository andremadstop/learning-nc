<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\LegacyAiTaskCleanup;
use OCA\Learning\Repair\MarkLlmConfigurationMigrated;
use OCA\Learning\Repair\MigrateLlmConfiguration;
use OCA\Learning\Repair\RedactLegacyAiAudit;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Security\ICrypto;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;
use OCP\TextProcessing\IManager as TextManager;
use OCP\TextProcessing\Task as TextTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.7.0 Task C1: the upgrade repair step. Every part is idempotent and can be repeated on its own:
 * (1) Gemini key out of plaintext, with the lifecycle probe's injection point between write and
 * delete; (2) inactive legacy Ollama values kept as saved Ollama connection; (3) open legacy
 * TaskProcessing tasks: scheduled ones cancelled + deleted, running ones listed for later;
 * (4) chat memory cleared once; (5) audit redaction (Task C3). A fresh install only sets the
 * markers, so that a later upgrade does not wipe what 5.7 itself wrote.
 */
class MigrateLlmConfigurationTest extends TestCase {
    private const LEGACY = 'AIza-C1-LEGACY-MARKER';

    private FakeAppConfigValues $values;
    private IAppConfig $appConfig;
    private FakeDbConnection $db;
    /** @var TaskManager&MockObject */
    private TaskManager $tasks;
    /** @var TextManager&MockObject */
    private TextManager $textTasks;
    /** @var array<int, Task> TaskProcessing rows of every app, as Nextcloud stores them */
    private array $store = [];
    /** @var array<int, TextTask> TextProcessing rows */
    private array $textStore = [];
    /** @var list<int> */
    private array $cancelled = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<int> */
    private array $textDeleted = [];
    /** the next deleteTask() throws, as a process dying between cancel and delete would leave it */
    private bool $failNextDelete = false;
    /** cancelTask() finds the task already gone */
    private bool $cancelFindsNothing = false;
    private int $userScans = 0;
    /** @var RedactLegacyAiAudit&MockObject */
    private RedactLegacyAiAudit $redaction;
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> */
    private array $infos = [];

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        $this->appConfig = $appConfig;
        $this->db = new FakeDbConnection();

        // Stateful like Nextcloud's manager: cancelTask() commits status CANCELLED on its own,
        // deleteTask() removes the row. getUserTasksByApp() returns every status.
        $this->tasks = $this->createMock(TaskManager::class);
        $this->tasks->method('getUserTasksByApp')->willReturnCallback(
            fn (?string $uid, string $appId): array => array_values(array_filter(
                $this->store, fn (Task $t): bool => $t->getAppId() === $appId && $t->getUserId() === $uid)));
        $this->tasks->method('cancelTask')->willReturnCallback(function (int $id): void {
            if ($this->cancelFindsNothing || !isset($this->store[$id])) {
                throw new NotFoundException('Couldn\'t find task with id ' . $id);
            }
            $this->cancelled[] = $id;
            if (in_array($this->store[$id]->getStatus(), [Task::STATUS_SCHEDULED, Task::STATUS_RUNNING], true)) {
                $this->store[$id]->setStatus(Task::STATUS_CANCELLED);
            }
        });
        $this->tasks->method('deleteTask')->willReturnCallback(function (Task $t): void {
            if ($this->failNextDelete) {
                $this->failNextDelete = false;
                throw new \OCP\TaskProcessing\Exception\Exception('process died');
            }
            $this->deleted[] = (int)$t->getId();
            unset($this->store[(int)$t->getId()]);
        });
        $this->textTasks = $this->createMock(TextManager::class);
        $this->textTasks->method('getUserTasksByApp')->willReturnCallback(
            fn (string $uid, string $appId): array => $appId === 'learning' ? array_values(array_filter(
                $this->textStore, fn (TextTask $t): bool => $t->getUserId() === $uid)) : []);
        $this->textTasks->method('deleteTask')->willReturnCallback(function (TextTask $t): void {
            $this->textDeleted[] = (int)$t->getId();
            unset($this->textStore[(int)$t->getId()]);
        });

        $this->redaction = $this->createMock(RedactLegacyAiAudit::class);
        $this->redaction->method('run')->willReturn(RedactLegacyAiAudit::RESULT_DONE);
    }

    private function users(): IUserManager {
        $users = $this->createMock(IUserManager::class);
        $users->method('callForAllUsers')->willReturnCallback(function (\Closure $cb): void {
            $this->userScans++;
            foreach (['alice', 'bob'] as $uid) {
                $u = $this->createMock(IUser::class);
                $u->method('getUID')->willReturn($uid);
                $cb($u);
            }
        });
        return $users;
    }

    private function keys(): ApiKeyStore {
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(fn (string $v): string => 'enc:' . $v);
        $crypto->method('decrypt')->willReturnCallback(function (string $v): string {
            if (!str_starts_with($v, 'enc:')) {
                throw new \Exception('HMAC does not match.');
            }
            return substr($v, 4);
        });
        return new ApiKeyStore($this->appConfig, $crypto, $this->createMock(LoggerInterface::class));
    }

    private function step(string $probe = ''): MigrateLlmConfiguration {
        $connections = new ConnectionStore($this->appConfig, $this->createMock(AuditService::class), $this->createMock(LoggerInterface::class));
        $legacyTasks = new LegacyAiTaskCleanup($this->tasks, $this->textTasks, $this->users(), $this->appConfig);
        $args = [$this->keys(), $connections, $this->appConfig, $legacyTasks, $this->db, $this->redaction, $this->createMock(LoggerInterface::class)];
        return new class($probe, ...$args) extends MigrateLlmConfiguration {
            public function __construct(private string $probe, mixed ...$args) {
                parent::__construct(...$args);
            }

            protected function probeFailAfter(): string {
                return $this->probe;
            }
        };
    }

    private function out(): IOutput {
        $out = $this->createMock(IOutput::class);
        $out->method('warning')->willReturnCallback(function ($m): void {
            $this->warnings[] = (string)$m;
        });
        $out->method('info')->willReturnCallback(function ($m): void {
            $this->infos[] = (string)$m;
        });
        return $out;
    }

    private function task(int $id, string $uid, int $status, string $app = 'learning'): Task {
        $t = new Task('core:text2text', ['input' => 'x'], $app, $uid);
        $t->setId($id);
        $t->setStatus($status);
        $this->store[$id] = $t;
        return $t;
    }

    private function textTask(int $id, string $uid, int $status): TextTask {
        $t = new TextTask('OCP\\TextProcessing\\FreePromptTaskType', 'legacy prompt', 'learning', $uid);
        $t->setId($id);
        $t->setStatus($status);
        $this->textStore[$id] = $t;
        return $t;
    }

    private function geminiTarget(): string {
        return ApiKeyStore::keyName(ConnectionStore::legacyGemini(false));
    }

    private function memoryDeletes(): int {
        $n = 0;
        foreach ($this->db->issuedBuilders as $qb) {
            if ($qb->operation === 'delete' && $qb->table === 'learning_ai_chat_memory') {
                $n++;
            }
        }
        return $n;
    }

    // (1) key

    public function testTheGeminiKeyLeavesPlaintext(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->step()->run($this->out());
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
        $this->assertSame('enc:' . self::LEGACY, $this->values->get('learning', $this->geminiTarget()));
        $this->assertNoKeyIn(array_merge($this->infos, $this->warnings));
    }

    public function testAKeyThatCannotBeMovedIsKeptAndReported(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->set('learning', $this->geminiTarget(), 'bad:broken');
        $this->step()->run($this->out());
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'));
        $this->assertCount(1, $this->warnings);
        $this->assertNoKeyIn($this->warnings);
    }

    public function testProbeInjectionAbortsAfterTheKeyWriteAndTheNextRunHeals(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->redaction->expects($this->once())->method('run');
        try {
            $this->step('key_write')->run($this->out());
            $this->fail('the injected run must abort');
        } catch (\Error $e) {
            // An \Exception would be swallowed by Nextcloud's Repair::run and the upgrade would
            // report success; only an \Error ends `occ upgrade` with a non-zero exit code.
            $this->assertNotInstanceOf(\Exception::class, $e);
            $this->assertStringContainsString('PROBE-INJECTED key_write', $e->getMessage());
        }
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'), 'aborted before the plaintext delete');
        $this->assertSame('enc:' . self::LEGACY, $this->values->get('learning', $this->geminiTarget()), 'aborted after the write');
        $this->assertSame(0, $this->memoryDeletes(), 'later parts did not run');

        $this->step()->run($this->out());
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
        $this->assertSame('enc:' . self::LEGACY, $this->values->get('learning', $this->geminiTarget()));
    }

    public function testProbeInjectionIsInertWithoutAKeyToWrite(): void {
        $this->step('key_write')->run($this->out());
        $this->assertSame(1, $this->memoryDeletes());
    }

    // (2) Ollama

    public function testInactiveOllamaValuesBecomeTheSavedOllamaConnection(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'ai_ollama_url', 'http://ollama.lan:11434');
        $this->values->set('learning', 'ai_ollama_model', 'llama3.1:8b');

        $this->step()->run($this->out());

        $saved = json_decode((string)$this->values->get('learning', 'ai_connection_saved_ollama_'), true);
        $this->assertIsArray($saved);
        $this->assertSame('ollama', $saved['provider']);
        $this->assertSame('http://ollama.lan:11434', $saved['baseUrl']);
        $this->assertSame('llama3.1:8b', $saved['model']);
        $this->assertFalse($saved['enabled']);
        $this->assertFalse($saved['processingLocal']);
        $this->assertFalse($this->values->has('learning', 'ai_connection'), 'the active connection is still derived from the 5.5 keys');
    }

    public function testAnExistingSavedOllamaConnectionIsKept(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_ollama_url', 'http://ollama.lan:11434');
        $this->values->set('learning', 'ai_connection_saved_ollama_', '{"kept":true}');
        $this->step()->run($this->out());
        $this->assertSame('{"kept":true}', $this->values->get('learning', 'ai_connection_saved_ollama_'));
    }

    public function testActiveOllamaIsNotCopied(): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $this->values->set('learning', 'ai_ollama_url', 'http://ollama.lan:11434');
        $this->step()->run($this->out());
        $this->assertFalse($this->values->has('learning', 'ai_connection_saved_ollama_'));
    }

    public function testNoOllamaValuesNoSavedConnection(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->step()->run($this->out());
        $this->assertFalse($this->values->has('learning', 'ai_connection_saved_ollama_'));
    }

    public function testAnInvalidOllamaUrlIsSkippedWithAWarningAndTheRestRuns(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_ollama_url', 'not a url');
        $this->step()->run($this->out());
        $this->assertFalse($this->values->has('learning', 'ai_connection_saved_ollama_'));
        $this->assertCount(1, $this->warnings);
        $this->assertSame(1, $this->memoryDeletes());
    }

    // (3) legacy tasks

    public function testScheduledTasksAreCancelledAndDeletedRunningOnesListed(): void {
        $this->task(11, 'alice', Task::STATUS_SCHEDULED);
        $this->task(13, 'alice', Task::STATUS_SUCCESSFUL);
        $this->task(12, 'bob', Task::STATUS_RUNNING);
        $this->task(14, 'bob', Task::STATUS_SCHEDULED, 'other_app');
        $this->values->set('learning', 'ai_legacy_tasks_pending', '[7]');

        $this->step()->run($this->out());

        $this->assertSame([11], $this->cancelled);
        $this->assertSame([11], $this->deleted);
        $left = array_keys($this->store);
        sort($left);
        $this->assertSame([12, 13, 14], $left, 'running, finished and other apps\' tasks stay');
        $this->assertSame([7, 12], json_decode((string)$this->values->get('learning', 'ai_legacy_tasks_pending'), true));
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
    }

    /**
     * Review finding (HIGH): cancelTask() commits CANCELLED before deleteTask() runs. A process
     * that dies in between left a CANCELLED row, which the rescan skipped before writing the
     * marker — the prompt stayed in taskprocessing_tasks for good.
     */
    public function testACancelledLeftoverOfAnInterruptedRunIsDeletedBeforeTheMarkerIsSet(): void {
        $this->task(11, 'alice', Task::STATUS_SCHEDULED);
        $this->failNextDelete = true;
        try {
            $this->step()->run($this->out());
            $this->fail('the interrupted run must not complete');
        } catch (\Error) {
        }
        $this->assertSame(Task::STATUS_CANCELLED, $this->store[11]->getStatus(), 'the cancel was committed');
        $this->assertFalse($this->values->has('learning', 'llm_migration_tasks_done'));

        $this->step()->run($this->out());

        $this->assertArrayNotHasKey(11, $this->store, 'the cancelled leftover is deleted');
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
    }

    public function testATaskThatIsAlreadyGoneWhenCancelledDoesNotStopTheRest(): void {
        $this->task(11, 'alice', Task::STATUS_SCHEDULED);
        $this->task(12, 'bob', Task::STATUS_SCHEDULED);
        $this->cancelFindsNothing = true;

        $this->step()->run($this->out());

        $this->assertSame([], $this->store, 'a task the manager no longer finds is treated as gone; delete is still attempted');
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
        $this->assertSame([], $this->warnings);
    }

    /**
     * Review finding (MED): the app's own TextProcessing queue (NC <= 29 fallback of the removed
     * legacy path) is cleaned up as well: deleteTask() also removes the job that would send it.
     */
    public function testQueuedTextProcessingTasksOfTheAppAreDeleted(): void {
        $this->textTask(21, 'alice', TextTask::STATUS_SCHEDULED);
        $this->textTask(22, 'bob', TextTask::STATUS_RUNNING);
        $this->textTask(23, 'bob', TextTask::STATUS_SUCCESSFUL);

        $this->step()->run($this->out());

        $this->assertSame([21], $this->textDeleted);
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
        $this->assertStringContainsString('1 queued TextProcessing', implode("\n", $this->infos));
    }

    public function testTheTaskScanRunsOnce(): void {
        $this->step()->run($this->out());
        $this->step()->run($this->out());
        $this->assertSame(1, $this->userScans);
    }

    /**
     * Review finding (HIGH): a failed cleanup used to warn and let the upgrade finish, and the
     * Nextcloud worker then sent the queued 5.5 prompts outside the gateway and the new consent.
     * It now ends the upgrade with an \Error (Repair::run swallows \Exception): the app stays
     * "needs upgrade", so neither cron nor the task worker runs until `occ upgrade` succeeds.
     */
    public function testAFailingTaskScanAbortsTheUpgradeAndSetsNoMarker(): void {
        $tasks = $this->createMock(TaskManager::class);
        $tasks->method('getUserTasksByApp')->willThrowException(new \OCP\TaskProcessing\Exception\Exception('db down'));
        $this->tasks = $tasks;
        $this->redaction->expects($this->never())->method('run');

        try {
            $this->step()->run($this->out());
            $this->fail('a failed legacy task cleanup must end the upgrade');
        } catch (\Error $e) {
            $this->assertNotInstanceOf(\Exception::class, $e);
            $this->assertStringContainsString('legacy AI tasks', $e->getMessage());
            $this->assertInstanceOf(\OCP\TaskProcessing\Exception\Exception::class, $e->getPrevious());
        }

        $this->assertFalse($this->values->has('learning', 'llm_migration_tasks_done'));
        $this->assertSame(0, $this->memoryDeletes(), 'later parts do not run');
    }

    public function testAFailingTextProcessingScanAbortsTheUpgradeToo(): void {
        $text = $this->createMock(TextManager::class);
        $text->method('getUserTasksByApp')->willThrowException(new \RuntimeException('db down'));
        $this->textTasks = $text;

        $this->expectException(\Error::class);
        try {
            $this->step()->run($this->out());
        } finally {
            $this->assertFalse($this->values->has('learning', 'llm_migration_tasks_done'));
        }
    }

    // (4) chat memory

    public function testChatMemoryIsClearedOnce(): void {
        $this->step()->run($this->out());
        $this->step()->run($this->out());
        $this->assertSame(1, $this->memoryDeletes());
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_memory_cleared'));
        $this->assertSame(1, $this->db->beginTransactionCalls, 'delete and marker in one transaction');
        $this->assertSame(1, $this->db->commitCalls);
        $this->assertSame(0, $this->db->rollBackCalls);
    }

    /**
     * Review finding (MED): DELETE and marker write were two separate steps. A failing marker
     * write after a successful DELETE let the next repair delete the memory learners built under
     * the 5.7 consent. Both now commit together or not at all.
     */
    public function testAFailingMarkerWriteRollsTheMemoryDeleteBack(): void {
        $this->values->failingWrites = ['llm_migration_memory_cleared'];

        $this->step()->run($this->out());

        $this->assertSame(1, $this->memoryDeletes());
        $this->assertSame(1, $this->db->beginTransactionCalls);
        $this->assertSame(0, $this->db->commitCalls);
        $this->assertSame(1, $this->db->rollBackCalls);
        $this->assertFalse($this->values->has('learning', 'llm_migration_memory_cleared'));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('clearChatMemory', $this->warnings[0]);
    }

    // (5) audit redaction

    public function testTheAuditRedactionRunsWithTheSameOutput(): void {
        $out = $this->out();
        $this->redaction->expects($this->once())->method('run')->with($out);
        $this->step()->run($out);
    }

    // whole step

    public function testASecondRunChangesNothing(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_ollama_url', 'http://ollama.lan:11434');
        $this->task(11, 'alice', Task::STATUS_SCHEDULED);
        $this->step()->run($this->out());
        $after = $this->values->values;

        $this->step()->run($this->out());

        $this->assertSame($after, $this->values->values);
        $this->assertSame([11], $this->cancelled);
    }

    public function testAFreshInstallOnlySetsTheMarkers(): void {
        $this->redaction->expects($this->once())->method('markDone');
        $this->redaction->expects($this->never())->method('run');
        $this->step()->markFreshInstall();

        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_memory_cleared'));
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
        $this->assertSame(0, $this->memoryDeletes());
        $this->assertSame(0, $this->userScans);
    }

    public function testTheInstallStepDelegatesToMarkFreshInstall(): void {
        $migration = $this->createMock(MigrateLlmConfiguration::class);
        $migration->expects($this->once())->method('markFreshInstall');
        $migration->expects($this->never())->method('run');
        (new MarkLlmConfigurationMigrated($migration))->run($this->out());
    }

    /** @param list<string> $lines */
    private function assertNoKeyIn(array $lines): void {
        foreach ($lines as $line) {
            $this->assertStringNotContainsString(self::LEGACY, $line);
        }
    }
}
