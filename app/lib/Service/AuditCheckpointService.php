<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * AuditCheckpointService — Phase 161 (AUDIT-04).
 *
 * Signs tamper-evident Ed25519 checkpoints over the Phase-160 compliance hash chain. Each
 * checkpoint commits to a window [from_event_id, to_event_id] of learning_audit_events and is
 * stored together with the VERBATIM JSON bytes that were signed (signed_payload), so an external
 * verifier can re-check sodium_crypto_sign_verify_detached without reconstructing the canonical
 * form (no reconstruction drift).
 *
 * IMPORTANT: signing goes through sodium DIRECTLY (sodium_crypto_sign_detached), NOT
 * SigningService::sign() — SigningService's header is frozen to typ:vc+jwt by ADR-155-ANCHOR
 * and would corrupt the raw-detached-signature contract this service relies on.
 */
class AuditCheckpointService {
    private const OVERDUE_SECONDS = 8 * 86400; // 8 days — a weekly job that missed >1 cycle

    public function __construct(
        private KeyService $keyService,
        private IDBConnection $db,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Mint a checkpoint over all compliance events written since the previous checkpoint.
     *
     * Skips (debug log, no write) when there are no new compliance events. On success it inserts
     * one signed row and records last_checkpoint_at / last_checkpoint_to_event_id in appconfig.
     *
     * @throws \RuntimeException if the active signing key is missing or malformed.
     */
    public function createCheckpoint(): void {
        // 1. Where did the last checkpoint end? (0 if this is the first checkpoint.)
        $lastQb = $this->db->getQueryBuilder();
        $lastQb->select('to_event_id')
            ->from('learning_audit_checkpoints')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $lastToSeq = (int)($lastQb->executeQuery()->fetchOne() ?: 0);

        // 2. Find the current tail of the compliance chain (MAX seq_num + its chain_hash).
        //    seq_num IS NOT NULL filters out non-compliance logEvent() rows.
        $tailQb = $this->db->getQueryBuilder();
        $tailQb->select('seq_num', 'chain_hash')
            ->from('learning_audit_events')
            ->where($tailQb->expr()->isNotNull('seq_num'))
            ->orderBy('seq_num', 'DESC')
            ->setMaxResults(1);
        $tailRow = $tailQb->executeQuery()->fetch();

        if (!is_array($tailRow) || $tailRow['seq_num'] === null) {
            $this->logger->debug('AuditCheckpointService: no compliance events yet — nothing to checkpoint', ['app' => 'learning']);
            return;
        }

        $toSeq = (int)$tailRow['seq_num'];
        if ($toSeq <= $lastToSeq) {
            $this->logger->debug('AuditCheckpointService: no new compliance events since last checkpoint', ['app' => 'learning']);
            return;
        }

        $fromSeq = $lastToSeq + 1;
        $headHash = (string)$tailRow['chain_hash'];

        // 3. Count events in the window [fromSeq, toSeq].
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->createFunction('COUNT(*)'))
            ->from('learning_audit_events')
            ->where($countQb->expr()->gte('seq_num', $countQb->createNamedParameter($fromSeq, IQueryBuilder::PARAM_INT)))
            ->andWhere($countQb->expr()->lte('seq_num', $countQb->createNamedParameter($toSeq, IQueryBuilder::PARAM_INT)));
        $eventCount = (int)($countQb->executeQuery()->fetchOne() ?: 0);

        // 4. Fetch the active signing key BEFORE building the payload (payload needs key_id).
        //    Done only after the skip-checks above so we never decrypt the key needlessly.
        $material = $this->keyService->getActiveSigningMaterial();
        $keyId = $material['key']->getKeyId();

        // 5. Build the deterministic signing payload (no PII — seq numbers + hash only).
        $now = time();
        $payload = json_encode([
            'typ'           => 'audit_checkpoint',
            'issuer'        => $this->keyService->hostDid(),
            'key_id'        => $keyId,
            'from_event_id' => $fromSeq,
            'to_event_id'   => $toSeq,
            'head_hash'     => $headHash,
            'event_count'   => $eventCount,
            'signed_at'     => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // 6. Sign with sodium DIRECTLY (never SigningService::sign()).
        $secretKeyRaw = $material['secret'];
        if (strlen($secretKeyRaw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Invalid Ed25519 secret key length');
        }
        $sigRaw = sodium_crypto_sign_detached($payload, $secretKeyRaw);
        sodium_memzero($secretKeyRaw);
        $sigHex = bin2hex($sigRaw);

        // 7. Persist the checkpoint row (anchor_url NULL — set later by AUDIT-05).
        $insertQb = $this->db->getQueryBuilder();
        $insertQb->insert('learning_audit_checkpoints')
            ->values([
                'from_event_id'  => $insertQb->createNamedParameter($fromSeq, IQueryBuilder::PARAM_INT),
                'to_event_id'    => $insertQb->createNamedParameter($toSeq, IQueryBuilder::PARAM_INT),
                'head_hash'      => $insertQb->createNamedParameter($headHash),
                'event_count'    => $insertQb->createNamedParameter($eventCount, IQueryBuilder::PARAM_INT),
                'signed_at'      => $insertQb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'key_id'         => $insertQb->createNamedParameter($keyId),
                'signed_payload' => $insertQb->createNamedParameter($payload),
                'signature'      => $insertQb->createNamedParameter($sigHex),
                'anchor_url'     => $insertQb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
            ]);
        $insertQb->executeStatement();

        $this->config->setAppValue('learning', 'last_checkpoint_at', (string)$now);
        $this->config->setAppValue('learning', 'last_checkpoint_to_event_id', (string)$toSeq);

        $this->logger->info(
            'AuditCheckpointService: checkpoint minted seq={from}..{to} events={count}',
            ['from' => $fromSeq, 'to' => $toSeq, 'count' => $eventCount, 'app' => 'learning']
        );
    }

    /**
     * Liveness view for the admin banner / audit dashboard: how fresh is the checkpoint chain and
     * how many compliance events are still unanchored.
     *
     * @return array{last_checkpoint_at: int, events_since_checkpoint: int, anchor_enabled: bool, last_anchor_status: string, is_overdue: bool}
     */
    public function getLivenessStatus(): array {
        $lastAt = (int)$this->config->getAppValue('learning', 'last_checkpoint_at', '0');
        $lastToEventId = (int)$this->config->getAppValue('learning', 'last_checkpoint_to_event_id', '0');

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_audit_events')
            ->where($qb->expr()->isNotNull('seq_num'))
            ->andWhere($qb->expr()->gt('seq_num', $qb->createNamedParameter($lastToEventId, IQueryBuilder::PARAM_INT)));
        $eventsSince = (int)($qb->executeQuery()->fetchOne() ?: 0);

        return [
            'last_checkpoint_at'      => $lastAt,
            'events_since_checkpoint' => $eventsSince,
            'anchor_enabled'          => $this->config->getAppValue('learning', 'forgejo_anchor_enabled', 'false') === 'true',
            'last_anchor_status'      => $this->config->getAppValue('learning', 'last_anchor_status', 'none'),
            'is_overdue'              => (time() - $lastAt) > self::OVERDUE_SECONDS,
        ];
    }
}
