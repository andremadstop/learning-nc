<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 164-07 post-impl-review pass-2 fix (RECERT-06 delivery guarantee).
 *
 * learning_recert_reminders.delivered_at — outbox state for the reminder row:
 *   NULL     = CLAIMED but not (yet) delivered: insertOnce won the CAS, IManager::notify()
 *              has not confirmed. A stale pending row (sent_at older than the retry window)
 *              is retried on the next job run.
 *   non-NULL = DELIVERED: notify() returned without throwing; never send again.
 *
 * Why: the pass-1 fix compensated a failed notify() by DELETING the idempotency row — but if
 * that delete itself failed, the row survived as a false "sent" marker and the compliance
 * reminder was silently swallowed forever. With the outbox state the row is only ever a
 * delivery PROOF when delivered_at is set; a crashed/failed delivery leaves a retryable
 * pending row instead. sent_at keeps its role as the claim timestamp.
 *
 * Existing rows (devcloud only, feature unreleased): backfilled as delivered so they are
 * never re-sent.
 */
class Version009800Date20260703120000 extends SimpleMigrationStep {
    public function __construct(
        private readonly IDBConnection $db,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_recert_reminders')) {
            $table = $schema->getTable('learning_recert_reminders');
            if (!$table->hasColumn('delivered_at')) {
                $table->addColumn('delivered_at', Types::BIGINT, [
                    'notnull' => false,
                ]);
                return $schema;
            }
        }
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Backfill: pre-outbox rows were only written AFTER the send was assumed successful —
        // treat them as delivered (sent_at as the delivery time) so they are never re-sent.
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_recert_reminders')
            ->set('delivered_at', $qb->createFunction('sent_at'))
            ->where($qb->expr()->isNull('delivered_at'));
        $qb->executeStatement();
    }
}
