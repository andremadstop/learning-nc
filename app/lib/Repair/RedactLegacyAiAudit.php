<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;

/**
 * Removes the prompt and answer excerpts that the AI facade of 5.5 and earlier wrote into
 * learning_audit_events (spec 5.2, plan Task C3). Called by MigrateLlmConfiguration, once per
 * instance (marker `llm_audit_redacted`).
 *
 * - Only the event keys that GeminiService::writeAuditLog/WithKey wrote in 5.5.1 (EVENT_KEYS).
 * - Only rows outside the compliance hash chain (chain_hash and prev_hash both NULL). If any
 *   allowlisted row is chained, the run stops before changing anything and warns: rewriting it
 *   would break `occ learning:audit:verify`, and that needs a human decision. No marker then, so
 *   the next repair run tries again.
 * - Only rows that still carry content: `input`/`output` other than "[redacted]", or JSON that
 *   cannot be decoded (a truncated excerpt). Metadata-only rows, which 5.7 still writes under
 *   `ai_chat`, keep their context.
 * - The redacted context is {"input":"[redacted]","output":"[redacted]","model":<old model>}.
 * - Paged by id (BATCH_SIZE), so a large table is neither loaded at once nor re-scanned forever
 *   (redacted rows still match the event and chain filter). An interrupted run is safe to repeat:
 *   redacted rows no longer carry content and are skipped.
 */
class RedactLegacyAiAudit {
    public const APP = 'learning';
    public const MARKER = 'llm_audit_redacted';
    public const BATCH_SIZE = 500;
    public const REDACTED = '[redacted]';

    /** Event keys written with content by GeminiService in 5.5.1 (writeAuditLog → ai_chat, writeAuditLogWithKey). */
    public const EVENT_KEYS = ['ai_chat', 'ai_structured', 'ai_telos_extract', 'telos_interview_turn', 'note_generation', 'ticket_triage'];

    public const RESULT_DONE = 'done';
    public const RESULT_ALREADY = 'already';
    public const RESULT_ABORTED_CHAINED = 'aborted_chained';

    public function __construct(
        private IDBConnection $db,
        private IAppConfig $appConfig,
    ) {
    }

    /** @return string one of the RESULT_* constants */
    public function run(IOutput $output): string {
        // Decide on what is stored, not on what this process happens to have cached.
        $this->appConfig->clearCache();
        if ($this->appConfig->getValueString(self::APP, self::MARKER, '') === 'yes') {
            return self::RESULT_ALREADY;
        }

        $chained = $this->countChained();
        if ($chained > 0) {
            $output->warning(
                "Legacy AI audit redaction stopped without changes: $chained audit row(s) with an AI event key are part of the "
                . 'compliance hash chain, and rewriting them would break it. Check with `occ learning:audit:verify`; '
                . 'the redaction is retried on the next repair run.'
            );
            return self::RESULT_ABORTED_CHAINED;
        }

        $output->info('Legacy AI audit redaction: ' . $this->countCandidates() . ' unchained AI audit row(s) to inspect');
        $redacted = 0;
        $lastId = 0;
        do {
            $rows = $this->nextBatch($lastId);
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $redactedContext = self::redactedContext($row['context_json']);
                if ($redactedContext !== null) {
                    $redacted += $this->update($lastId, $redactedContext);
                }
            }
            if (count($rows) === self::BATCH_SIZE) {
                $this->afterBatch();
            }
        } while (count($rows) === self::BATCH_SIZE);

        $this->appConfig->setValueString(self::APP, self::MARKER, 'yes');
        $output->info("Legacy AI audit redaction: removed prompt and answer excerpts from $redacted row(s)");
        return self::RESULT_DONE;
    }

    /**
     * Runs between two batches; does nothing. The integration test overrides it to interrupt a run
     * there and prove that the next run finishes the redaction.
     */
    protected function afterBatch(): void {
    }

    /** A fresh install has no legacy rows; the marker keeps a later upgrade from touching rows it wrote. */
    public function markDone(): void {
        $this->appConfig->setValueString(self::APP, self::MARKER, 'yes');
    }

    /** The context to store instead, or null when the row carries no content. */
    public static function redactedContext(mixed $contextJson): ?string {
        if ($contextJson === null || $contextJson === '') {
            return null;
        }
        $data = json_decode((string)$contextJson, true);
        if (!is_array($data)) {
            $model = null; // unreadable: may hold a truncated excerpt, so it goes
        } else {
            $hasContent = false;
            foreach (['input', 'output'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== self::REDACTED) {
                    $hasContent = true;
                }
            }
            if (!$hasContent) {
                return null;
            }
            $model = is_string($data['model'] ?? null) ? $data['model'] : null;
        }
        return json_encode(
            ['input' => self::REDACTED, 'output' => self::REDACTED, 'model' => $model],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function countChained(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('learning_audit_events')
            ->where($this->allowlisted($qb))
            ->andWhere($qb->expr()->orX($qb->expr()->isNotNull('chain_hash'), $qb->expr()->isNotNull('prev_hash')));
        return $this->fetchCount($qb);
    }

    private function countCandidates(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('learning_audit_events')
            ->where($this->allowlisted($qb))
            ->andWhere($qb->expr()->isNull('chain_hash'))
            ->andWhere($qb->expr()->isNull('prev_hash'));
        return $this->fetchCount($qb);
    }

    /** @return list<array{id: mixed, context_json: mixed}> */
    private function nextBatch(int $afterId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'context_json')
            ->from('learning_audit_events')
            ->where($this->allowlisted($qb))
            ->andWhere($qb->expr()->isNull('chain_hash'))
            ->andWhere($qb->expr()->isNull('prev_hash'))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        $result = $qb->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = ['id' => $row['id'], 'context_json' => $row['context_json']];
        }
        $result->closeCursor();
        return $rows;
    }

    private function update(int $id, string $context): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_audit_events')
            ->set('context_json', $qb->createNamedParameter($context))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            // Re-checked per row: a row that joined the chain since the pre-check stays untouched.
            ->andWhere($qb->expr()->isNull('chain_hash'))
            ->andWhere($qb->expr()->isNull('prev_hash'));
        return $qb->executeStatement();
    }

    private function allowlisted(IQueryBuilder $qb): mixed {
        return $qb->expr()->in('event_key', $qb->createNamedParameter(self::EVENT_KEYS, IQueryBuilder::PARAM_STR_ARRAY));
    }

    private function fetchCount(IQueryBuilder $qb): int {
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }
}
