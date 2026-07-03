<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

    // Deliberately NO AuditService dependency: retention touches the audit table ONLY via the
    // raw user_id-null UPDATE below — it never appends events and never recomputes the chain.
    public function __construct(
        private readonly CertificateMapper $certMapper,
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
     *   to the hash function; see AuditService::logComplianceEvent). Scrubbing breaks the cert
     *   SIGNATURE by design — the public verify route reads the anonymized_at tombstone as the
     *   defined 'anonymized' state BEFORE any signature work (164-06), so no 500 is possible.
     *
     *   Scope guard: only certs whose active_idem_key is NULL age out (superseded / period-closed
     *   / revoked). The subject's CURRENT active cert still serves its purpose and is exempt
     *   until a later period or revoke frees the slot (see CertificateMapper::findAnonymizableBefore).
     */
    public function anonymizeExpired(): int {
        $now = $this->timeFactory->getTime();
        $years = (int)$this->config->getAppValue(
            self::APP_ID, 'retention_years', ConfigDefaults::RETENTION_YEARS_DEFAULT
        );
        if ($years <= 0) {
            return 0; // retention disabled by explicit config — never mass-erase on a bad value
        }
        // Calendar-exact cutoff (mirrors computeExpiry: modify(), never N*31536000).
        $cutoff = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->modify('-' . $years . ' years')
            ->getTimestamp();

        // 1. Certs: null user_id + scrub credential_json + set the tombstone (per row, mapper
        //    update — keeps entity-level typing and lets tests capture the written state).
        $erased = 0;
        foreach ($this->certMapper->findAnonymizableBefore($cutoff) as $cert) {
            try {
                $cert->setUserId(null);
                $cert->setCredentialJson('');
                $cert->setAnonymizedAt($now);
                $this->certMapper->update($cert);
                $erased++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'anonymizeExpired: cert {cert} failed, retrying next run: {msg}',
                    ['cert' => (int)$cert->getId(), 'msg' => $e->getMessage()]
                );
            }
        }

        // 2. Audit rows: null user_id ONLY (DSGVO-01 UserDeletedListener pattern). chain_hash /
        //    context_json are NEVER touched — the canonical hash input excludes user_id, so the
        //    tamper-evident chain stays verifiable by construction.
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_audit_events')
            ->set('user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->andX(
                $qb->expr()->isNotNull('user_id'),
                $qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT))
            ));
        $qb->executeStatement();

        // 3. Assignments: null subject_id on aged CLOSED periods only (active_period_key IS NULL)
        //    — an open obligation still needs its subject; it ages out after its period closes.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->update('learning_assignments')
            ->set('subject_id', $qb2->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb2->expr()->andX(
                $qb2->expr()->isNotNull('subject_id'),
                $qb2->expr()->isNull('active_period_key'),
                $qb2->expr()->lt('assigned_at', $qb2->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT))
            ));
        $qb2->executeStatement();

        if ($erased > 0) {
            $this->logger->info('anonymizeExpired: crypto-erased {count} certificate(s) past the {years}y retention window', [
                'count' => $erased,
                'years' => $years,
            ]);
        }
        return $erased;
    }
}
