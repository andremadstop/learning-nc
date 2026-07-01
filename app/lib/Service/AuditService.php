<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * AuditService — reusable audit event insertion.
 *
 * Two methods with deliberately different error contracts:
 *
 * logEvent()            — non-compliance events (game sessions, moderation).
 *                         Wraps all exceptions so audit logging never breaks app flow.
 *
 * logComplianceEvent()  — compliance-critical events (course.passed, cert.issued,
 *                         cert.revoked). Propagates ALL exceptions — a failed
 *                         compliance write is a hard error. Uses CAS on
 *                         learning_audit_chain_state for fork-safe hash-chain integrity.
 */
class AuditService {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private \OCP\IConfig $config;
    private \OCP\Security\ISecureRandom $secureRandom;

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        \OCP\IConfig $config,
        \OCP\Security\ISecureRandom $secureRandom,
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
        $this->secureRandom = $secureRandom;
    }

    /**
     * Log an audit event.
     *
     * MUST NOT CHANGE — wraps all exceptions so audit logging never breaks app flow.
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

    /**
     * Return the stable per-instance HMAC pepper for user_ref pseudonymization.
     *
     * SECURITY: This value MUST be stable forever. Changing it invalidates all existing
     * user_ref entries in the audit chain — cross-entry matching becomes impossible.
     * Stored in appconfig ('learning', 'audit_user_ref_pepper'). Generated once on first call.
     */
    private function getUserRefPepper(): string {
        $pepper = $this->config->getAppValue('learning', 'audit_user_ref_pepper', '');
        if ($pepper === '') {
            $pepper = $this->secureRandom->generate(32);
            $this->config->setAppValue('learning', 'audit_user_ref_pepper', $pepper);
        }
        return $pepper;
    }

    /**
     * Log a compliance-critical audit event with hash-chain integrity.
     *
     * DOES NOT swallow exceptions — a failed compliance write MUST propagate.
     * Uses CAS on learning_audit_chain_state for fork-safe serialization.
     *
     * canonical = ksorted_json({course_id, created_at, event_key, seq, user_ref}) — ksort guarantees stable key order
     * user_ref  = hash_hmac('sha256', $userId, pepper) — pseudonymous HMAC; pepper from appconfig 'audit_user_ref_pepper'
     * chain_hash = sha256(canonical_json . '|' . prev_hash) — '|' is explicit domain separator
     *
     * @param array<string, mixed> $context  Should contain 'course_id' for proper chain canonical.
     * @throws \Throwable on DB failure or chain slot exhaustion after retries
     */
    public function logComplianceEvent(string $eventKey, string $userId, array $context = []): void {
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $this->db->beginTransaction();
            try {
                // 1. Read current chain head
                $qb = $this->db->getQueryBuilder();
                $result = $qb->select('last_seq', 'last_hash')
                    ->from('learning_audit_chain_state')
                    ->setMaxResults(1)
                    ->executeQuery();
                $state = $result->fetch();
                $result->closeCursor();

                if ($state === false) {
                    throw new \RuntimeException('Audit chain state not initialized — run occ upgrade');
                }

                $prevSeq  = (int)$state['last_seq'];
                $prevHash = (string)$state['last_hash'];
                $newSeq   = $prevSeq + 1;
                $ts       = time();
                // HMAC-peppered pseudonym — NEVER raw uid (DSGVO-01: NC uids can be email-shaped PII)
                $userRef  = hash_hmac('sha256', $userId, $this->getUserRefPepper());

                // 2. Build canonical (PII excluded: no user_id, display_name, email)
                // CRITICAL: ksort guarantees stable key order — JSON key order is undefined without it
                $canonicalFields = [
                    'seq'        => $newSeq,
                    'event_key'  => $eventKey,
                    'user_ref'   => $userRef,
                    'course_id'  => $context['course_id'] ?? null,
                    'created_at' => $ts,
                ];
                ksort($canonicalFields);
                $canonical = json_encode($canonicalFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                // Explicit '|' domain separator prevents ambiguity between canonical JSON and prev_hash
                $chainHash = hash('sha256', $canonical . '|' . $prevHash);

                // 3. Insert audit event row
                $qb2 = $this->db->getQueryBuilder();
                $qb2->insert('learning_audit_events')
                    ->values([
                        'event_key'    => $qb2->createNamedParameter($eventKey),
                        'user_id'      => $qb2->createNamedParameter($userId),
                        'user_ref'     => $qb2->createNamedParameter($userRef),
                        'context_json' => $qb2->createNamedParameter(
                            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        ),
                        'created_at'   => $qb2->createNamedParameter($ts, IQueryBuilder::PARAM_INT),
                        'seq_num'      => $qb2->createNamedParameter($newSeq, IQueryBuilder::PARAM_INT),
                        'prev_hash'    => $qb2->createNamedParameter($prevHash),
                        'chain_hash'   => $qb2->createNamedParameter($chainHash),
                    ]);
                $qb2->executeStatement();

                // 4. CAS update — 0 affected rows = concurrent writer raced ahead, retry
                $qb3 = $this->db->getQueryBuilder();
                $affected = (int)$qb3->update('learning_audit_chain_state')
                    ->set('last_seq',  $qb3->createNamedParameter($newSeq, IQueryBuilder::PARAM_INT))
                    ->set('last_hash', $qb3->createNamedParameter($chainHash))
                    ->where($qb3->expr()->eq('last_seq', $qb3->createNamedParameter($prevSeq, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                if ($affected === 0) {
                    $this->db->rollBack();
                    continue; // race — retry
                }

                $this->db->commit();
                return; // success

            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e; // MUST propagate — no swallowing
            }
        }

        throw new \RuntimeException(
            'logComplianceEvent: could not acquire chain slot after ' . $maxRetries . ' attempts'
        );
    }
}
