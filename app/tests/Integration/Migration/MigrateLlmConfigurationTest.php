<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Repair\MigrateLlmConfiguration;
use OCA\Learning\Repair\RedactLegacyAiAudit;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Tests\Integration\Support\RecordingOutput;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use OCP\TaskProcessing\IManager as TaskManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task C1 against a real Nextcloud (both engines): the repair step resolves through DI,
 * moves the key with the real ICrypto/IAppConfig, and handles real TaskProcessing rows. The
 * instance under test was installed fresh, so the <install> step must have set the markers.
 */
final class MigrateLlmConfigurationTest extends TestCase {
    private const UID = 'c1-legacy-task-probe';
    private const FAKE_KEY = 'c1-integration-fake-key-0000';
    private const KEYS = [
        MigrateLlmConfiguration::MARKER_TASKS,
        MigrateLlmConfiguration::MARKER_MEMORY,
        MigrateLlmConfiguration::PENDING_TASKS,
        RedactLegacyAiAudit::MARKER,
        ApiKeyStore::LEGACY_GEMINI_KEY,
    ];

    private IAppConfig $appConfig;
    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void {
        $this->appConfig = Server::get(IAppConfig::class);
        $this->appConfig->clearCache();
        foreach (self::KEYS as $key) {
            $this->before[$key] = $this->appConfig->hasKey('learning', $key)
                ? $this->appConfig->getValueString('learning', $key, '') : null;
        }
    }

    protected function tearDown(): void {
        foreach ($this->before as $key => $value) {
            $this->appConfig->deleteKey('learning', $key);
            if ($value !== null) {
                $this->appConfig->setValueString('learning', $key, $value);
            }
        }
        $this->appConfig->deleteKey('learning', ApiKeyStore::keyName(ConnectionStore::legacyGemini(false)));
        $tasks = Server::get(TaskManager::class);
        foreach ($tasks->getUserTasksByApp(self::UID, 'learning') as $task) {
            $tasks->deleteTask($task);
        }
        Server::get(IUserManager::class)->get(self::UID)?->delete();
    }

    public function testAFreshInstallMarkedTheOneTimePartsDone(): void {
        $this->assertSame('yes', $this->before[MigrateLlmConfiguration::MARKER_TASKS]);
        $this->assertSame('yes', $this->before[MigrateLlmConfiguration::MARKER_MEMORY]);
        $this->assertSame('yes', $this->before[RedactLegacyAiAudit::MARKER]);
    }

    public function testThePlaintextKeyMovesIntoEncryptedStorage(): void {
        $this->appConfig->setValueString('learning', ApiKeyStore::LEGACY_GEMINI_KEY, self::FAKE_KEY);

        Server::get(MigrateLlmConfiguration::class)->run(new RecordingOutput());

        $this->appConfig->clearCache();
        $this->assertFalse($this->appConfig->hasKey('learning', ApiKeyStore::LEGACY_GEMINI_KEY));
        $this->assertSame(self::FAKE_KEY, Server::get(ApiKeyStore::class)->get(ConnectionStore::legacyGemini(true)));
    }

    public function testLegacyTasksScheduledAreRemovedRunningAreListed(): void {
        Server::get(IUserManager::class)->createUser(self::UID, bin2hex(random_bytes(16)) . 'Aa1!');
        $scheduled = $this->insertTask(Task::STATUS_SCHEDULED);
        $running = $this->insertTask(Task::STATUS_RUNNING);
        $this->appConfig->deleteKey('learning', MigrateLlmConfiguration::MARKER_TASKS);
        $this->appConfig->deleteKey('learning', MigrateLlmConfiguration::PENDING_TASKS);

        $output = new RecordingOutput();
        Server::get(MigrateLlmConfiguration::class)->run($output);

        $left = array_map(static fn (Task $t): ?int => $t->getId(), Server::get(TaskManager::class)->getUserTasksByApp(self::UID, 'learning'));
        $this->assertNotContains($scheduled, $left, 'a scheduled legacy task is cancelled and deleted');
        $this->assertContains($running, $left, 'a running legacy task is left to finish');
        $this->appConfig->clearCache();
        $this->assertContains($running, json_decode($this->appConfig->getValueString('learning', MigrateLlmConfiguration::PENDING_TASKS, '[]'), true));
        $this->assertSame('yes', $this->appConfig->getValueString('learning', MigrateLlmConfiguration::MARKER_TASKS, ''));
        $this->assertSame([], $output->warnings);
    }

    /** Same rows IManager::scheduleTask() writes; scheduleTask() itself refuses a type without provider. */
    private function insertTask(int $status): int {
        $task = new Task('core:text2text', ['input' => 'c1 legacy prompt'], 'learning', self::UID);
        $task->setStatus($status);
        $task->setScheduledAt(time());
        if ($status === Task::STATUS_RUNNING) {
            $task->setStartedAt(time());
        }
        $entity = Server::get(\OC\TaskProcessing\Db\TaskMapper::class)->insert(\OC\TaskProcessing\Db\Task::fromPublicTask($task));
        return (int)$entity->getId();
    }
}
