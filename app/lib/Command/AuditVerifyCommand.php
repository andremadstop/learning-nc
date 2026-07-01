<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Db\CertKeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: independent verification of the compliance audit trail (AUDIT-06).
 *
 * Usage:
 *   php occ learning:audit:verify                 # verify the full chain + checkpoints
 *   php occ learning:audit:verify --from-seq 500  # partial audit from seq_num 500
 *   php occ learning:audit:verify --json          # machine-readable result
 *   php occ learning:audit:verify --fork-runbook  # print the fork-resolution runbook path
 *
 * An auditor or compliance officer runs this on any server with DB access to prove the hash chain
 * was not tampered with — WITHOUT trusting the app logic that wrote the chain. Three independent
 * checks:
 *
 *   PHASE 1 — CHAIN WALK: re-reconstruct each event's chain_hash from the FROZEN 6-field canonical
 *             (byte-identical to AuditService::logComplianceEvent) and confirm the sha256 links.
 *   PHASE 2 — CHECKPOINT SIGNATURES: verify each Ed25519 checkpoint signature over its verbatim
 *             signed_payload with sodium_crypto_sign_verify_detached, and confirm head_hash matches
 *             the committed tail event.
 *   PHASE 3 — ANCHOR CONSISTENCY (warning-only): if a checkpoint carries a Forgejo anchor_url,
 *             fetch it and compare the anchored bytes to signed_payload. Network failure is a
 *             WARNING, never a verification FAILURE (the anchor may be temporarily unreachable).
 *
 * Exit 0 (SUCCESS) when the chain is intact and every checkpoint signature verifies; exit 1
 * (FAILURE) on any integrity failure. On failure the fork-resolution runbook path is printed.
 */
class AuditVerifyCommand extends Command {
    private const RUNBOOK_PATH = 'docs/audit-fork-runbook.md';
    private const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private IDBConnection $db,
        private CertKeyMapper $certKeyMapper,
        private IClientService $clientService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('learning:audit:verify')
            ->setDescription('Verify audit chain integrity, checkpoint signatures, and Forgejo anchor consistency')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON')
            ->addOption('from-seq', null, InputOption::VALUE_OPTIONAL, 'Start from seq_num', 1)
            ->addOption('fork-runbook', null, InputOption::VALUE_NONE, 'Print fork resolution runbook path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('fork-runbook')) {
            $output->writeln(self::RUNBOOK_PATH);
            return Command::SUCCESS;
        }

        $asJson  = (bool)$input->getOption('json');
        $fromSeq = (int)$input->getOption('from-seq') ?: 1;

        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $warnings */
        $warnings = [];

        // ── PHASE 1 — CHAIN WALK ────────────────────────────────────────────────
        $eventQb = $this->db->getQueryBuilder();
        $eventQb->select('*')
            ->from('learning_audit_events')
            ->where($eventQb->expr()->isNotNull('seq_num'))
            ->andWhere($eventQb->expr()->gte('seq_num', $eventQb->createNamedParameter($fromSeq, IQueryBuilder::PARAM_INT)))
            ->orderBy('seq_num', 'ASC');
        /** @var array<int, array<string, mixed>> $events */
        $events = $eventQb->executeQuery()->fetchAll();

        // Seed the chain boundary. Full audit (fromSeq == 1) MUST start at the genesis seed; a
        // partial audit (fromSeq > 1) cannot know the pre-window head, so it adopts the window's
        // first stored prev_hash as a trusted boundary (the link to the prior row is out of scope).
        $prevHash = null;
        $eventCount = 0;
        $maxSeq = 0; // F2: highest compliance seq_num seen — drives the "events exist, unanchored" warn

        foreach ($events as $row) {
            $eventCount++;
            $maxSeq = max($maxSeq, (int)$row['seq_num']);

            if ($prevHash === null) {
                $prevHash = $fromSeq > 1 ? (string)$row['prev_hash'] : self::GENESIS_PREV_HASH;
            }

            // F1 (Codex review — prev_hash tamper): also assert the STORED prev_hash column equals
            // the expected previous hash we carry in memory ($prevHash). The chain_hash check alone
            // misses a prev_hash-only tamper: chain_hash is recomputed from the in-memory $prevHash,
            // so editing ONLY the stored prev_hash column leaves the recomputed chain_hash matching
            // and slips through. genesis = 64 zeros; a partial audit (fromSeq > 1) seeds $prevHash
            // from this very row, so its first-row compare is trivially satisfied (boundary out of scope).
            if (!hash_equals((string)$row['prev_hash'], $prevHash)) {
                $failures[] = sprintf(
                    'Chain prev_hash tampered at seq_num=%d: stored prev_hash does not match the previous event hash',
                    (int)$row['seq_num']
                );
            }

            // ========================================================================
            // THE 6-FIELD CANONICAL — FROZEN — DO NOT DEVIATE
            // Verbatim copy of AuditService::logComplianceEvent (Phase 160, commit 18973dc).
            // Any deviation produces false "tampered" alerts.
            // ========================================================================
            $contextJson = (string)$row['context_json'];
            $context = json_decode($contextJson, true);
            $courseId = is_array($context) ? ($context['course_id'] ?? null) : null;

            $canonicalFields = [
                'seq'          => (int)$row['seq_num'],           // cast to int — matches write-time $newSeq
                'event_key'    => $row['event_key'],              // read from column directly
                'user_ref'     => $row['user_ref'],               // READ FROM COLUMN — never recompute from user_id
                                                                  // (DSGVO-01: user_id may be NULL after erasure;
                                                                  //  user_ref is immutable, stored at write time)
                'course_id'    => $courseId,                      // extract from context_json ONCE
                'created_at'   => (int)$row['created_at'],        // cast to int — matches write-time $ts
                'payload_hash' => hash('sha256', $contextJson),   // hash the RAW stored string — never decode+reencode
            ];
            ksort($canonicalFields);
            $canonical = json_encode($canonicalFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $expected  = hash('sha256', $canonical . '|' . $prevHash);
            // ========================================================================

            if (!hash_equals($expected, (string)$row['chain_hash'])) {
                $failures[] = sprintf(
                    'Chain tampered at seq_num=%d: expected %s, stored %s',
                    (int)$row['seq_num'],
                    $expected,
                    (string)$row['chain_hash']
                );
            }

            $prevHash = (string)$row['chain_hash']; // advance to next event
        }

        // ── PHASE 2 — CHECKPOINT SIGNATURE VERIFY ───────────────────────────────
        $cpQb = $this->db->getQueryBuilder();
        $cpQb->select('*')
            ->from('learning_audit_checkpoints')
            ->orderBy('id', 'ASC');
        /** @var array<int, array<string, mixed>> $checkpoints */
        $checkpoints = $cpQb->executeQuery()->fetchAll();
        $checkpointsVerified = 0;
        $prevCpTo = null; // F2: previous checkpoint's to_event_id — used for contiguous-window gap detection

        foreach ($checkpoints as $checkpoint) {
            $cpId = (int)$checkpoint['id'];

            // Resolve the public key via the mapper — a missing/unknown key_id is a reportable
            // failure, not a silent decode of an arbitrary string.
            try {
                $certKey = $this->certKeyMapper->findByKeyId((string)$checkpoint['key_id']);
            } catch (DoesNotExistException $e) {
                $failures[] = sprintf('Checkpoint %d: no key found for key_id %s', $cpId, (string)$checkpoint['key_id']);
                continue;
            }

            // Public key column is base64url (NOT hex) → sodium_base642bin, not hex2bin.
            // Signature column IS hex (161-01 stores bin2hex) → hex2bin is correct there.
            try {
                $pubKeyRaw = sodium_base642bin($certKey->getPublicKeyB64u(), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                $sigRaw    = hex2bin((string)$checkpoint['signature']);
            } catch (\SodiumException $e) {
                $failures[] = sprintf('Checkpoint %d: malformed key or signature encoding', $cpId);
                continue;
            }

            if ($sigRaw === false || strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
                $failures[] = sprintf('Checkpoint %d (to_event_id=%s): signature invalid', $cpId, (string)$checkpoint['to_event_id']);
                continue;
            }

            $valid = sodium_crypto_sign_verify_detached($sigRaw, (string)$checkpoint['signed_payload'], $pubKeyRaw);
            if (!$valid) {
                $failures[] = sprintf('Checkpoint %d (to_event_id=%s): signature invalid', $cpId, (string)$checkpoint['to_event_id']);
                continue;
            }

            // F2 (Codex review — bound the signature to the stored columns): the signature only proves
            // that signed_payload is authentic. An adversary who cannot forge the signature can still
            // rewrite the surrounding DB COLUMNS (head_hash/from/to/count/…) that dashboards and this
            // very command read. Re-parse the verified payload and assert every signed field equals the
            // row column it is supposed to attest. Any mismatch IS a verification FAILURE.
            $signed = json_decode((string)$checkpoint['signed_payload'], true);
            if (!is_array($signed)) {
                $failures[] = sprintf('Checkpoint %d: signed_payload is not decodable JSON', $cpId);
                continue;
            }

            $fromId = (int)$checkpoint['from_event_id'];
            $toId   = (int)$checkpoint['to_event_id'];

            $fieldMismatch = null;
            if ((int)($signed['from_event_id'] ?? -1) !== $fromId) {
                $fieldMismatch = 'from_event_id';
            } elseif ((int)($signed['to_event_id'] ?? -1) !== $toId) {
                $fieldMismatch = 'to_event_id';
            } elseif (!hash_equals((string)($signed['head_hash'] ?? ''), (string)$checkpoint['head_hash'])) {
                $fieldMismatch = 'head_hash';
            } elseif ((int)($signed['event_count'] ?? -1) !== (int)$checkpoint['event_count']) {
                $fieldMismatch = 'event_count';
            } elseif ((int)($signed['signed_at'] ?? -1) !== (int)$checkpoint['signed_at']) {
                $fieldMismatch = 'signed_at';
            } elseif ((string)($signed['key_id'] ?? '') !== (string)$checkpoint['key_id']) {
                $fieldMismatch = 'key_id';
            }
            if ($fieldMismatch !== null) {
                $failures[] = sprintf(
                    'Checkpoint %d: signed %s does not match the stored column (row tampered)',
                    $cpId,
                    $fieldMismatch
                );
                continue;
            }

            // F2: the compliance seq is contiguous, so a well-formed window covers exactly
            // (to_event_id - from_event_id + 1) events. A wrong event_count is a FAILURE.
            $expectedCount = $toId - $fromId + 1;
            if ((int)$checkpoint['event_count'] !== $expectedCount) {
                $failures[] = sprintf(
                    'Checkpoint %d: event_count=%d does not match contiguous window size %d (from %d to %d)',
                    $cpId,
                    (int)$checkpoint['event_count'],
                    $expectedCount,
                    $fromId,
                    $toId
                );
                continue;
            }

            // F2: consecutive checkpoints (ordered by id) should tile the chain with no gap —
            // each window's from_event_id == previous window's to_event_id + 1. A gap is a hard-warn
            // (events between windows are unanchored), never an exit-code flip.
            if ($prevCpTo !== null && $fromId !== $prevCpTo + 1) {
                $warnings[] = sprintf(
                    'Checkpoint %d: window gap — from_event_id=%d but previous checkpoint ended at %d (events %d..%d unanchored)',
                    $cpId,
                    $fromId,
                    $prevCpTo,
                    $prevCpTo + 1,
                    $fromId - 1
                );
            }
            $prevCpTo = $toId;

            // head_hash consistency: the checkpoint's committed head_hash must equal the chain_hash
            // of the to_event_id row (to_event_id IS a seq_num).
            $headQb = $this->db->getQueryBuilder();
            $headQb->select('chain_hash')
                ->from('learning_audit_events')
                ->where($headQb->expr()->eq('seq_num', $headQb->createNamedParameter((int)$checkpoint['to_event_id'], IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);
            $storedHead = $headQb->executeQuery()->fetchOne();

            if ($storedHead === false || !hash_equals((string)$checkpoint['head_hash'], (string)$storedHead)) {
                $failures[] = sprintf('Checkpoint %d: head_hash mismatch (committed head does not match to_event_id row)', $cpId);
                continue;
            }

            $checkpointsVerified++;

            // ── PHASE 3 — ANCHOR CONSISTENCY (warning-only) ─────────────────────
            $anchorUrl = $checkpoint['anchor_url'] ?? null;
            if ($anchorUrl !== null && $anchorUrl !== '') {
                $anchorWarning = $this->checkAnchor($cpId, (string)$anchorUrl, (string)$checkpoint['signed_payload']);
                if ($anchorWarning !== null) {
                    $warnings[] = $anchorWarning;
                }
            }
        }

        // F2 (Codex review — unanchored chain): compliance events exist (max seq_num > 0) but NOT a
        // single checkpoint verified. The chain may be intact yet has no external trust anchor — an
        // adversary with full DB control could rewrite it undetectably. Surface a WARNING line (it does
        // not flip the exit code — the in-DB chain can still be self-consistent).
        if ($maxSeq > 0 && $checkpointsVerified === 0) {
            $warnings[] = sprintf(
                'No verified checkpoint anchors the chain, yet %d compliance event(s) exist (up to seq_num=%d) — the trail is unanchored',
                $eventCount,
                $maxSeq
            );
        }

        // ── OUTPUT ──────────────────────────────────────────────────────────────
        $ok = $failures === [];

        if ($asJson) {
            $output->writeln((string)json_encode([
                'ok'                    => $ok,
                'events_verified'       => $eventCount,
                'checkpoints_verified'  => $checkpointsVerified,
                'failures'              => $failures,
                'warnings'              => $warnings,
                'fork_runbook'          => $ok ? null : self::RUNBOOK_PATH,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($warnings as $warning) {
            $output->writeln('<comment>⚠ ' . $warning . '</comment>');
        }

        if ($ok) {
            $output->writeln(sprintf(
                '<info>✓ Chain intact (%d events, %d checkpoints verified)</info>',
                $eventCount,
                $checkpointsVerified
            ));
            return Command::SUCCESS;
        }

        foreach ($failures as $failure) {
            $output->writeln('<error>✗ ' . $failure . '</error>');
        }
        $output->writeln('Fork resolution runbook: ' . self::RUNBOOK_PATH);
        return Command::FAILURE;
    }

    /**
     * Best-effort anchor consistency check. Returns a warning string on mismatch/unreachable,
     * or null when the anchored bytes match the signed_payload. NEVER affects the exit code.
     */
    private function checkAnchor(int $cpId, string $anchorUrl, string $signedPayload): ?string {
        // F6 (Codex review — minimal SSRF guard): only follow https anchors. The full defence
        // (host allowlist + reject private/link-local) is deferred until the Forgejo token is
        // provisioned and the anchor is enabled by an admin.
        // TODO(anchor-enablement): full SSRF allowlist (host allowlist + reject private/link-local)
        // when the Forgejo token is provisioned.
        if (!str_starts_with($anchorUrl, 'https://')) {
            return sprintf('Checkpoint %d: anchor skipped (non-https anchor_url)', $cpId);
        }
        try {
            $client = $this->clientService->newClient();
            $response = $client->get($anchorUrl, ['timeout' => 5]);
            if ($response->getStatusCode() !== 200) {
                return sprintf('Checkpoint %d: anchor unreachable (HTTP %d)', $cpId, $response->getStatusCode());
            }
            $body = $response->getBody();
            if (!is_string($body)) {
                $body = (string)stream_get_contents($body);
            }
            // F4 (Codex review): anchor_url is the RAW file endpoint (content.download_url), so the
            // response body IS the verbatim signed_payload bytes — compare them exactly (hash_equals),
            // no base64/JSON unwrap.
            if (!hash_equals($signedPayload, $body)) {
                return sprintf('Checkpoint %d: anchor mismatch (anchored bytes differ from signed_payload)', $cpId);
            }
            return null;
        } catch (\Throwable $e) {
            return sprintf('Checkpoint %d: anchor unreachable (%s)', $cpId, $e->getMessage());
        }
    }
}
