<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\AuditVerifyCommand;
use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\StubConsoleInput;

/**
 * Captures writeln/write output so failure/warning lines can be asserted
 * (the shared StubConsoleOutput discards output).
 */
final class CapturingOutput implements OutputInterface {
    public string $buffer = '';

    public function writeln(string|array $messages, int $options = 0): void {
        $this->buffer .= (is_array($messages) ? implode("\n", $messages) : $messages) . "\n";
    }

    public function write(string|array $messages, bool $newline = false, int $options = 0): void {
        $this->buffer .= is_array($messages) ? implode("\n", $messages) : $messages;
    }
}

/**
 * Phase 161 (AUDIT-06) — AuditVerifyCommand.
 *
 * The load-bearing assertions:
 *   - a self-consistent chain (built with the SAME frozen 6-field canonical the command uses)
 *     verifies clean (exit 0),
 *   - a single tampered chain_hash is detected and reported with its seq_num (exit 1),
 *   - a DSGVO-erased row (user_id NULL, user_ref intact) does NOT false-positive,
 *   - a checkpoint whose Ed25519 signature does not verify fails (exit 1),
 *   - every failure prints the fork-resolution runbook path.
 *
 * Checkpoint signature tests use a REAL Ed25519 keypair: the mocked CertKey hands back the
 * public key as base64url so the command's sodium_base642bin decode round-trips exactly.
 */
class AuditVerifyCommandTest extends TestCase {

    private const RUNBOOK = 'docs/audit-fork-runbook.md';

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Reconstruct a row's chain_hash with the frozen 6-field canonical (mirror of the command). */
    private function chainHash(array $row, string $prevHash): string {
        $contextJson = (string)$row['context_json'];
        $ctx = json_decode($contextJson, true);
        $fields = [
            'seq'          => (int)$row['seq_num'],
            'event_key'    => $row['event_key'],
            'user_ref'     => $row['user_ref'],
            'course_id'    => is_array($ctx) ? ($ctx['course_id'] ?? null) : null,
            'created_at'   => (int)$row['created_at'],
            'payload_hash' => hash('sha256', $contextJson),
        ];
        ksort($fields);
        $canonical = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $canonical . '|' . $prevHash);
    }

    /**
     * Build a self-consistent event chain from genesis, filling prev_hash + chain_hash.
     *
     * @param array<int, array<string, mixed>> $partials  rows with seq_num/event_key/user_ref/context_json/created_at
     * @return array<int, array<string, mixed>>
     */
    private function buildChain(array $partials): array {
        $prev = str_repeat('0', 64);
        $rows = [];
        foreach ($partials as $p) {
            $p['prev_hash'] = $prev;
            $p['chain_hash'] = $this->chainHash($p, $prev);
            $p['user_id'] = $p['user_id'] ?? 'someuser';
            $rows[] = $p;
            $prev = $p['chain_hash'];
        }
        return $rows;
    }

    private function event(int $seq, string $eventKey, ?int $courseId): array {
        return [
            'seq_num'      => $seq,
            'event_key'    => $eventKey,
            'user_ref'     => hash('sha256', 'ref-' . $seq),
            'context_json' => json_encode(['course_id' => $courseId, 'score' => 90], JSON_UNESCAPED_UNICODE),
            'created_at'   => 1_700_000_000 + $seq,
        ];
    }

    /** @return array{pub: string, sec: string} */
    private function makeKeypair(): array {
        $kp = sodium_crypto_sign_keypair();
        return [
            'pub' => sodium_crypto_sign_publickey($kp),
            'sec' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    /** A CertKeyMapper mock returning a CertKey whose public_key_b64u round-trips to $pubRaw. */
    private function mockMapper(string $keyId, string $pubRaw): CertKeyMapper {
        $certKey = new CertKey();
        $certKey->setKeyId($keyId);
        $certKey->setPublicKeyB64u(sodium_bin2base64($pubRaw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));

        $mapper = $this->createMock(CertKeyMapper::class);
        $mapper->method('findByKeyId')->willReturn($certKey);
        return $mapper;
    }

    /**
     * A signed checkpoint row over $headHash, using $sec. If $corruptSig, the stored signature is
     * valid-length hex but does not verify.
     *
     * @return array<string, mixed>
     */
    private function signedCheckpoint(int $id, int $toSeq, string $headHash, string $keyId, string $sec, bool $corruptSig = false): array {
        $payload = json_encode([
            'typ'           => 'audit_checkpoint',
            'issuer'        => 'did:web:test.example:apps:learning',
            'key_id'        => $keyId,
            'from_event_id' => 1,
            'to_event_id'   => $toSeq,
            'head_hash'     => $headHash,
            'event_count'   => $toSeq,
            'signed_at'     => 1_700_000_100,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $sigRaw = sodium_crypto_sign_detached($payload, $sec);
        $sigHex = $corruptSig
            ? bin2hex(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)) // valid length, wrong bytes
            : bin2hex($sigRaw);

        return [
            'id'             => $id,
            'from_event_id'  => 1,
            'to_event_id'    => $toSeq,
            'head_hash'      => $headHash,
            'event_count'    => $toSeq,
            'signed_at'      => 1_700_000_100,
            'key_id'         => $keyId,
            'signed_payload' => $payload,
            'signature'      => $sigHex,
            'anchor_url'     => null,
        ];
    }

    private function invokeCmd(FakeDbConnection $db, CertKeyMapper $mapper, array $inputData = [], ?IClientService $client = null): array {
        $cmd = new AuditVerifyCommand($db, $mapper, $client ?? $this->createMock(IClientService::class));
        $out = new CapturingOutput();
        $code = $cmd->run(new StubConsoleInput($inputData), $out);
        return [$code, $out->buffer];
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function testCleanChainWithCheckpointSucceeds(): void {
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 10),
            $this->event(2, 'cert.issued', 10),
            $this->event(3, 'cert.revoked', 10),
        ]);
        $head = $events[2]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(1, 3, $head, 'kid-1', $keys['sec']);

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),        // PHASE 1 events
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),  // PHASE 2 checkpoints
            new FakeQueryBuilder(FakeResult::fromFetchOne($head)),          // head_hash consistency
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']));

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Chain intact', $out);
        $this->assertStringContainsString('3 events', $out);
        $this->assertStringContainsString('1 checkpoints', $out);
    }

    public function testTamperedEventDetectedWithSeqNum(): void {
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 10),
            $this->event(2, 'cert.issued', 10),
            $this->event(3, 'cert.revoked', 10),
        ]);
        $events[1]['chain_hash'] = str_repeat('f', 64); // corrupt row seq_num=2

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])), // no checkpoints
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('seq_num=2', $out);
    }

    public function testDsgvoErasedRowIsNotFalsePositive(): void {
        // Event 1 was DSGVO-erased: user_id NULL, but user_ref intact — chain_hash was computed
        // at write-time from user_ref (never user_id), so the chain remains valid.
        $partials = [
            ['seq_num' => 1, 'event_key' => 'course.passed', 'user_ref' => hash('sha256', 'ref-1'),
             'context_json' => json_encode(['course_id' => 5], JSON_UNESCAPED_UNICODE), 'created_at' => 1_700_000_001, 'user_id' => null],
            ['seq_num' => 2, 'event_key' => 'cert.issued', 'user_ref' => hash('sha256', 'ref-2'),
             'context_json' => json_encode(['course_id' => 5], JSON_UNESCAPED_UNICODE), 'created_at' => 1_700_000_002, 'user_id' => 'bob'],
        ];
        $events = $this->buildChain($partials);
        $this->assertNull($events[0]['user_id'], 'row 1 is DSGVO-erased (user_id NULL)');

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class));

        $this->assertSame(Command::SUCCESS, $code, 'erased row must NOT trigger a tampered alert');
        $this->assertStringContainsString('Chain intact', $out);
    }

    public function testInvalidCheckpointSignatureFails(): void {
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(7, 1, $head, 'kid-1', $keys['sec'], corruptSig: true);

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            // no head_hash query — the command `continue`s after the signature failure
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('signature invalid', $out);
        $this->assertStringContainsString('Checkpoint 7', $out);
    }

    public function testMissingKeyForCheckpointFails(): void {
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(9, 1, $events[0]['chain_hash'], 'ghost-kid', $keys['sec']);

        $mapper = $this->createMock(CertKeyMapper::class);
        $mapper->method('findByKeyId')->willThrowException(new DoesNotExistException('no key'));

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
        ]);

        [$code, $out] = $this->invokeCmd($db, $mapper);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no key found for key_id ghost-kid', $out);
        $this->assertStringContainsString(self::RUNBOOK, $out);
    }

    public function testAnyFailurePrintsRunbookPath(): void {
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 10),
            $this->event(2, 'cert.issued', 10),
        ]);
        $events[1]['chain_hash'] = str_repeat('a', 64); // tamper

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString(self::RUNBOOK, $out);
    }

    public function testForkRunbookFlagPrintsPathAndSucceeds(): void {
        $db = new FakeDbConnection([]); // no queries issued — early return
        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class), ['--fork-runbook' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString(self::RUNBOOK, $out);
    }

    public function testJsonOutputOnCleanChain(): void {
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class), ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $decoded = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(1, $decoded['events_verified']);
        $this->assertSame([], $decoded['failures']);
        $this->assertNull($decoded['fork_runbook']);
    }

    public function testJsonOutputOnTamperedChainCarriesRunbook(): void {
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $events[0]['chain_hash'] = str_repeat('b', 64);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class), ['--json' => true]);

        $this->assertSame(Command::FAILURE, $code);
        $decoded = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['ok']);
        $this->assertSame(self::RUNBOOK, $decoded['fork_runbook']);
        $this->assertNotEmpty($decoded['failures']);
    }
}
