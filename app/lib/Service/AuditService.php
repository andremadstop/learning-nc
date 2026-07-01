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
 *                         cert.revoked). Propagates ALL failures as ComplianceAuditException
 *                         (fail-closed) — a failed compliance write is a hard error. Uses CAS
 *                         on learning_audit_chain_state for fork-safe hash-chain integrity.
 *
 * THREAT-MODEL SCOPE (Phase 160 = Layer 1): this hash chain is tamper-DETECTING WITHIN the DB —
 * any edit/delete/reorder of a compliance row breaks the sha256 links and is detectable by
 * re-walking the chain. It is NOT yet tamper-EVIDENT against a DB-level adversary who can rewrite
 * BOTH the events AND learning_audit_chain_state consistently. Full tamper-evidence (a trust anchor
 * outside the mutable DB) is Phase 161: Ed25519-signed periodic checkpoints anchored to an external
 * append-only store (Forgejo). Do NOT treat the Layer-1 chain as a signed audit log on its own.
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
     *
     * FIX-5 (pepper out of DB, no race): the pepper is derived deterministically from NC's
     * instance secret (config.php 'secret'), which lives OUTSIDE the app database. This removes
     * two weaknesses of the old appconfig-generated pepper: (a) a DB dump no longer leaks the
     * pepper alongside the user_ref values it protects, and (b) there is no first-use write race
     * (nothing is generated/stored — it is a pure HMAC of a value that already exists). The
     * domain-separation label keeps this pepper distinct from any other use of the instance secret.
     *
     * Fallback: on an instance with an empty 'secret' (unusual; some unit/test setups), fall back
     * to the legacy appconfig-generated pepper so behaviour stays deterministic and tests still work.
     */
    private function getUserRefPepper(): string {
        $secret = (string)$this->config->getSystemValue('secret', '');
        if ($secret !== '') {
            return hash_hmac('sha256', 'learning:audit_user_ref', $secret);
        }
        // Legacy fallback (empty instance secret): appconfig-generated pepper, created once.
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
     * DOES NOT swallow failures — every failure surfaces as ComplianceAuditException (fail-closed).
     * Uses CAS on the SINGLE chain-state row (id = 1) for fork-safe serialization.
     *
     * canonical  = ksorted_json({course_id, created_at, event_key, payload_hash, seq, user_ref})
     *              — ksort guarantees stable key order (JSON key order is otherwise undefined).
     * user_ref   = hash_hmac('sha256', $userId, pepper) — pseudonymous HMAC (pepper from instance secret).
     * payload_hash = sha256($contextJson) over the EXACT context_json string stored in the row —
     *              FIX-4: binds the compliance FACTS (score, threshold, passed_at, verification_id,
     *              revoked_at, …) into the chain. Without it those facts live only in context_json and
     *              could be altered undetectably. INVARIANT: compliance context_json carries ONLY
     *              non-PII facts (no names/emails). This keeps the bound hash compatible with DSGVO-01
     *              erasure, which nulls the user_id column while leaving user_ref/chain_hash immutable.
     *              All three callers (COURSE_PASSED, CERT_ISSUED, CERT_REVOKED) already pass facts-only
     *              context — do NOT put PII into a compliance context_json or erasure would break the chain.
     * chain_hash = sha256(canonical_json . '|' . prev_hash) — '|' is an explicit domain separator.
     *
     * @param array<string, mixed> $context  Should contain 'course_id' for proper chain canonical.
     * @throws ComplianceAuditException on DB failure, missing chain state, or slot exhaustion after retries
     */
    public function logComplianceEvent(string $eventKey, string $userId, array $context = []): void {
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $this->db->beginTransaction();

                // 1. Read current chain head — FIX-6: pin to the single canonical row id = 1.
                $qb = $this->db->getQueryBuilder();
                $result = $qb->select('last_seq', 'last_hash')
                    ->from('learning_audit_chain_state')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $state = $result->fetch();
                $result->closeCursor();

                if ($state === false) {
                    // FIX-6: fail hard — a missing state row means the chain is unusable.
                    throw new ComplianceAuditException('Audit chain state row (id=1) missing — run occ upgrade');
                }

                $prevSeq  = (int)$state['last_seq'];
                $prevHash = (string)$state['last_hash'];
                $newSeq   = $prevSeq + 1;
                $ts       = time();
                // HMAC-peppered pseudonym — NEVER raw uid (DSGVO-01: NC uids can be email-shaped PII)
                $userRef  = hash_hmac('sha256', $userId, $this->getUserRefPepper());

                // context_json is serialized ONCE — the SAME string is both stored and hashed (FIX-4).
                $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $payloadHash = hash('sha256', $contextJson);

                // 2. Build canonical (PII excluded: no user_id, display_name, email; facts bound via payload_hash)
                // CRITICAL: ksort guarantees stable key order — JSON key order is undefined without it
                $canonicalFields = [
                    'seq'          => $newSeq,
                    'event_key'    => $eventKey,
                    'user_ref'     => $userRef,
                    'course_id'    => $context['course_id'] ?? null,
                    'created_at'   => $ts,
                    'payload_hash' => $payloadHash,
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
                        'context_json' => $qb2->createNamedParameter($contextJson),
                        'created_at'   => $qb2->createNamedParameter($ts, IQueryBuilder::PARAM_INT),
                        'seq_num'      => $qb2->createNamedParameter($newSeq, IQueryBuilder::PARAM_INT),
                        'prev_hash'    => $qb2->createNamedParameter($prevHash),
                        'chain_hash'   => $qb2->createNamedParameter($chainHash),
                    ]);
                $qb2->executeStatement();

                // 4. CAS update — FIX-6: WHERE id = 1 AND last_seq = :expected. 0 affected rows =
                //    concurrent writer raced ahead, retry.
                $qb3 = $this->db->getQueryBuilder();
                $affected = (int)$qb3->update('learning_audit_chain_state')
                    ->set('last_seq',  $qb3->createNamedParameter($newSeq, IQueryBuilder::PARAM_INT))
                    ->set('last_hash', $qb3->createNamedParameter($chainHash))
                    ->where($qb3->expr()->eq('id', $qb3->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb3->expr()->eq('last_seq', $qb3->createNamedParameter($prevSeq, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                if ($affected === 0) {
                    $this->db->rollBack();
                    continue; // race — retry
                }

                $this->db->commit();
                return; // success

            } catch (\OCP\DB\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                // FIX-7: with the UNIQUE index on seq_num, a lost seq race now surfaces as a
                // unique-constraint violation on the INSERT (instead of a 0-row CAS). Treat it as a
                // retryable race — NOT a fatal error — so concurrency stays graceful.
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    continue;
                }
                throw new ComplianceAuditException('Compliance audit append failed: ' . $e->getMessage(), 0, $e);
            } catch (ComplianceAuditException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e; // already typed — propagate as-is (fail-closed)
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new ComplianceAuditException('Compliance audit append failed: ' . $e->getMessage(), 0, $e);
            }
        }

        throw new ComplianceAuditException(
            'logComplianceEvent: could not acquire chain slot after ' . $maxRetries . ' attempts'
        );
    }
}
