<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * AuditService -- reusable audit event insertion.
 *
 * Inserts into learning_audit_events table. Wraps in try/catch so
 * audit logging never breaks the main application flow.
 */
class AuditService {
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Log an audit event.
     *
     * @param string $eventKey   Event identifier (e.g. 'moderation_action')
     * @param string $userId     The user who performed the action
     * @param array<string, mixed> $context  Additional context data
     */
    public function logEvent(string $eventKey, string $userId, array $context = []): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_audit_events')
                ->values([
                    'event_key' => $qb->createNamedParameter($eventKey),
                    'user_id' => $qb->createNamedParameter($userId),
                    'context_json' => $qb->createNamedParameter(json_encode($context, JSON_UNESCAPED_UNICODE)),
                    'created_at' => $qb->createNamedParameter(time()),
                ]);
            $qb->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->error('AuditService::logEvent failed: ' . $e->getMessage(), [
                'app' => 'learning',
                'event_key' => $eventKey,
                'user_id' => $userId,
            ]);
        }
    }
}
