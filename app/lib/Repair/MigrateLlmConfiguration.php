<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Upgrade from 5.5/5.6 to the single LLM gateway (spec 2.3, 3.6, 4.3, 5.2; plan Task C1).
 * Registered under <post-migration>, so it also runs on every `occ maintenance:repair`: each part
 * is idempotent and can be repeated on its own, and a failing part warns without stopping the rest
 * — except part 3, whose failure ends the upgrade (LegacyTaskCleanupFailed).
 *
 * 1. Gemini key: plaintext `gemini_api_key` → encrypted, fingerprint-bound slot
 *    (ApiKeyStore::migrateLegacyGeminiKey). Lifecycle probe contract: with PROBE_FAIL_AFTER=key_write
 *    in the environment of `occ upgrade`, the run aborts after the encrypted copy was written and
 *    before the plaintext is deleted, printing `PROBE-INJECTED key_write`; the next run heals it.
 * 2. Inactive legacy Ollama settings (ai_ollama_url/model while another provider is active) →
 *    `ai_connection_saved_ollama_`, so switching back keeps them. Never writes `ai_connection`:
 *    the active connection stays derived from the 5.5 keys until an admin saves one.
 * 3. Open tasks of this app in Nextcloud's AI queues from the removed legacy path
 *    (LegacyAiTaskCleanup::scan). Once (marker `llm_migration_tasks_done`), since it walks all
 *    users. A failure throws LegacyTaskCleanupFailed (\Error): left queued, the tasks would be
 *    sent outside the gateway and the 5.7 consent, so the upgrade must not complete without it.
 * 4. Chat memory summaries are deleted once (marker `llm_migration_memory_cleared`): they may
 *    replay other learners' contributions and were made under the old consent. DELETE and marker
 *    commit in one transaction, so a failed marker write cannot cost a later run 5.7's memory.
 * 5. Audit redaction of 5.5 prompt/answer excerpts (RedactLegacyAiAudit, own marker).
 */
class MigrateLlmConfiguration implements IRepairStep {
    public const APP = 'learning';
    public const MARKER_TASKS = 'llm_migration_tasks_done';
    public const MARKER_MEMORY = 'llm_migration_memory_cleared';
    public const PENDING_TASKS = LegacyAiTaskCleanup::PENDING_TASKS;
    public const PROBE_ENV = 'PROBE_FAIL_AFTER';
    public const PROBE_KEY_WRITE = 'key_write';

    public function __construct(
        private ApiKeyStore $keys,
        private ConnectionStore $connections,
        private IAppConfig $appConfig,
        private LegacyAiTaskCleanup $legacyTasks,
        private IDBConnection $db,
        private RedactLegacyAiAudit $redaction,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Migrate the AI configuration to the consent-bound LLM gateway';
    }

    public function run(IOutput $output): void {
        // Decide on what is stored, not on what this process happens to have cached.
        $this->appConfig->clearCache();

        // \Exception only: ProbeInjectedFailure and LegacyTaskCleanupFailed are \Errors and must end
        // the upgrade.
        $parts = [
            'migrateKey' => fn () => $this->migrateKey($output),
            'keepInactiveOllama' => fn () => $this->keepInactiveOllama($output),
            'cleanUpLegacyTasks' => fn () => $this->cleanUpLegacyTasks($output),
            'clearChatMemory' => fn () => $this->clearChatMemory($output),
            'redactAudit' => fn () => $this->redaction->run($output),
        ];
        foreach ($parts as $part => $runPart) {
            try {
                $runPart();
            } catch (\Exception $e) {
                $this->logger->warning('AI configuration migration: ' . $part . ' failed', ['app' => self::APP, 'exception' => $e]);
                $output->warning("AI configuration migration: $part failed (" . get_class($e) . '); it is retried on the next repair run');
            }
        }
    }

    /** Fresh install: nothing to migrate; the one-time parts must never run on 5.7's own data later. */
    public function markFreshInstall(): void {
        $this->appConfig->setValueString(self::APP, self::MARKER_TASKS, 'yes');
        $this->appConfig->setValueString(self::APP, self::MARKER_MEMORY, 'yes');
        $this->redaction->markDone();
    }

    /** The lifecycle probe's fault injection point; empty outside the CLI. */
    protected function probeFailAfter(): string {
        if (PHP_SAPI !== 'cli') {
            return '';
        }
        $v = getenv(self::PROBE_ENV);
        return is_string($v) ? $v : '';
    }

    private function migrateKey(IOutput $output): void {
        $inject = $this->probeFailAfter() === self::PROBE_KEY_WRITE;
        $result = $this->keys->migrateLegacyGeminiKey(static function () use ($inject, $output): void {
            if ($inject) {
                $output->warning('PROBE-INJECTED ' . self::PROBE_KEY_WRITE);
                throw new ProbeInjectedFailure('PROBE-INJECTED ' . self::PROBE_KEY_WRITE . ': aborted after the encrypted Gemini key was written, before the plaintext key is deleted');
            }
        });
        if ($result === 'migrated') {
            $output->info('Moved the Gemini API key from plaintext into encrypted storage');
        } elseif ($result === 'kept_plaintext') {
            $output->warning('The Gemini API key could not be moved into encrypted storage and stays in plaintext; it keeps working. Enter it again in the AI admin settings.');
        }
    }

    private function keepInactiveOllama(IOutput $output): void {
        $url = trim($this->appConfig->getValueString(self::APP, 'ai_ollama_url', ''));
        $model = trim($this->appConfig->getValueString(self::APP, 'ai_ollama_model', ''));
        $savedKey = ConnectionStore::SAVED_PREFIX . 'ollama_';
        if (($url === '' && $model === '') || $this->appConfig->hasKey(self::APP, $savedKey)) {
            return;
        }
        if ($this->connections->load()->provider === 'ollama') {
            return; // active: the connection is derived from exactly these values
        }
        try {
            $c = ConnectionStore::fromLegacyValues('ollama', false, $url, $model);
        } catch (\InvalidArgumentException $e) {
            $output->warning('The inactive legacy Ollama settings are invalid and were not kept: ' . $e->getMessage());
            return;
        }
        $json = json_encode($c->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->appConfig->setValueString(self::APP, $savedKey, $json);
        $output->info('Kept the inactive Ollama settings for switching back');
    }

    private function cleanUpLegacyTasks(IOutput $output): void {
        if ($this->appConfig->getValueString(self::APP, self::MARKER_TASKS, '') === 'yes') {
            return;
        }
        try {
            $r = $this->legacyTasks->scan();
        } catch (\Throwable $e) {
            $this->logger->error('AI configuration migration: the legacy AI task cleanup failed; the upgrade is stopped', ['app' => self::APP, 'exception' => $e]);
            $output->warning('AI configuration migration: cleaning up the legacy AI tasks failed (' . get_class($e) . '). '
                . 'The upgrade is stopped so that the queued tasks are not sent; run `occ upgrade` again.');
            throw new LegacyTaskCleanupFailed('The legacy AI tasks could not be cleaned up: ' . $e->getMessage(), 0, $e);
        }
        $this->appConfig->setValueString(self::APP, self::MARKER_TASKS, 'yes');
        $output->info(sprintf(
            'Legacy AI tasks: %d scheduled cancelled, %d deleted, %d running listed for cleanup, %d queued TextProcessing deleted',
            $r['cancelled'], $r['deleted'], $r['running'], $r['text_deleted'],
        ));
    }

    private function clearChatMemory(IOutput $output): void {
        if ($this->appConfig->getValueString(self::APP, self::MARKER_MEMORY, '') === 'yes') {
            return;
        }
        // AppConfig writes through the same connection, so the marker commits with the DELETE.
        $this->db->beginTransaction();
        try {
            $deleted = $this->db->getQueryBuilder()->delete('learning_ai_chat_memory')->executeStatement();
            $this->appConfig->setValueString(self::APP, self::MARKER_MEMORY, 'yes');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->appConfig->clearCache(); // the process cache may still hold the rolled-back marker
            throw $e;
        }
        $output->info("Deleted $deleted AI chat memory entries made under the previous consent");
    }
}
