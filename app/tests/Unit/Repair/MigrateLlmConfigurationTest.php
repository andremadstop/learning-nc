<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

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
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;
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
    /** @var array<string, list<Task>> */
    private array $userTasks = [];
    /** @var list<int> */
    private array $cancelled = [];
    /** @var list<int> */
    private array $deleted = [];
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

        $this->tasks = $this->createMock(TaskManager::class);
        $this->tasks->method('getUserTasksByApp')->willReturnCallback(
            fn (?string $uid, string $appId): array => $appId === 'learning' ? ($this->userTasks[(string)$uid] ?? []) : []);
        $this->tasks->method('cancelTask')->willReturnCallback(function (int $id): void {
            $this->cancelled[] = $id;
        });
        $this->tasks->method('deleteTask')->willReturnCallback(function (Task $t): void {
            $this->deleted[] = (int)$t->getId();
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
        $args = [$this->keys(), $connections, $this->appConfig, $this->tasks, $this->users(), $this->db, $this->redaction, $this->createMock(LoggerInterface::class)];
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

    private function task(int $id, string $uid, int $status): Task {
        $t = new Task('core:text2text', ['input' => 'x'], 'learning', $uid);
        $t->setId($id);
        $t->setStatus($status);
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
        $this->userTasks = [
            'alice' => [$this->task(11, 'alice', Task::STATUS_SCHEDULED), $this->task(13, 'alice', Task::STATUS_SUCCESSFUL)],
            'bob' => [$this->task(12, 'bob', Task::STATUS_RUNNING)],
        ];
        $this->values->set('learning', 'ai_legacy_tasks_pending', '[7]');

        $this->step()->run($this->out());

        $this->assertSame([11], $this->cancelled);
        $this->assertSame([11], $this->deleted);
        $this->assertSame([7, 12], json_decode((string)$this->values->get('learning', 'ai_legacy_tasks_pending'), true));
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_tasks_done'));
    }

    public function testTheTaskScanRunsOnce(): void {
        $this->step()->run($this->out());
        $this->step()->run($this->out());
        $this->assertSame(1, $this->userScans);
    }

    public function testAFailingTaskScanWarnsSetsNoMarkerAndTheRestRuns(): void {
        $tasks = $this->createMock(TaskManager::class);
        $tasks->method('getUserTasksByApp')->willThrowException(new \OCP\TaskProcessing\Exception\Exception('db down'));
        $this->tasks = $tasks;

        $this->step()->run($this->out());

        $this->assertFalse($this->values->has('learning', 'llm_migration_tasks_done'));
        $this->assertCount(1, $this->warnings);
        $this->assertSame(1, $this->memoryDeletes());
    }

    // (4) chat memory

    public function testChatMemoryIsClearedOnce(): void {
        $this->step()->run($this->out());
        $this->step()->run($this->out());
        $this->assertSame(1, $this->memoryDeletes());
        $this->assertSame('yes', $this->values->get('learning', 'llm_migration_memory_cleared'));
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
        $this->userTasks = ['alice' => [$this->task(11, 'alice', Task::STATUS_SCHEDULED)]];
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
