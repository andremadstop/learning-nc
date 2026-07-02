<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * DSGVO-03 retention / crypto-erasure service (Phase 164).
 *
 * anonymizeExpired() — impl in 164-07:
 *   For each cert where anonymized_at IS NULL AND issued_at < (now - retention_years * YEAR):
 *     1. Null user_id on the Certificate row (pseudonymization).
 *     2. Scrub credential_json ← empty string / tombstone (crypto-erasure; breaks signature
 *        verifiability — accepted: the DSGVO-03 semantics explicitly allow this, and the public
 *        verify route reads anonymized_at IS NOT NULL as a "data erased" terminal state).
 *     3. Set anonymized_at = now (tombstone, non-null) ← 164-01 migration column.
 *     4. Linked learning_audit_events rows: null user_id ONLY — chain_hash is NOT recomputed
 *        (hash inputs do NOT include user_id → chain stays verifiable; see DSGVO-01 pattern).
 *     5. Linked learning_assignments rows: null subject_id (best-effort, non-cascading).
 *
 * ⚠ RETENTION_YEARS_DEFAULT = '3' is FLAGGED for AWO/DSGVO confirmation before prod rollout.
 *    Art.17(3)(b) ArbSchG/AGG may require a longer window. See ConfigDefaults.
 *
 * ⚠ AWO Betriebsvereinbarung per BetrVG §87 Abs.1 Nr.6 required before production rollout.
 *    This is a non-code documentation item — not enforced here.
 *
 * Clock: ITimeFactory — never time() directly in testable retention logic.
 * Config key: 'retention_years' (IConfig app value, app='learning').
 */
class RetentionService {
    private const APP_ID = 'learning';

    public function __construct(
        private readonly CertificateMapper $certMapper,
        private readonly AuditService $auditService,
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Anonymize all certs that have exceeded the retention window. Returns count of certs erased.
     *
     * DSGVO-03 correctness invariant:
     *   After erasure: cert.credential_json is scrubbed AND cert.anonymized_at is non-null AND
     *   audit chain re-verification still passes (chain_hash unchanged — user_id is not an input
     *   to the hash function; see AuditService::logComplianceEvent).
     *
     * @throws \LogicException until implemented in 164-07
     */
    public function anonymizeExpired(): int {
        throw new \LogicException('RetentionService::anonymizeExpired not implemented — impl in 164-07');
    }
}
