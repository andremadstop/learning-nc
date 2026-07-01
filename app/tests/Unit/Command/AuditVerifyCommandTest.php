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
            $p['user_id'] = array_key_exists('user_id', $p) ? $p['user_id'] : 'someuser';
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
    private function signedCheckpoint(
        int $id,
        int $toSeq,
        string $headHash,
        string $keyId,
        string $sec,
        bool $corruptSig = false,
        ?string $payloadHeadHash = null,  // F2: signed head_hash diverges from the stored column
        ?int $eventCount = null,          // F2: overrides BOTH payload + column event_count (arithmetic test)
        ?int $payloadEventCount = null    // F2: signed event_count diverges from the stored column
    ): array {
        $colEventCount = $eventCount ?? $toSeq;
        $sigEventCount = $payloadEventCount ?? $colEventCount;
        $sigHeadHash   = $payloadHeadHash ?? $headHash;

        $payload = json_encode([
            'typ'           => 'audit_checkpoint',
            'issuer'        => 'did:web:test.example:apps:learning',
            'key_id'        => $keyId,
            'from_event_id' => 1,
            'to_event_id'   => $toSeq,
            'head_hash'     => $sigHeadHash,
            'event_count'   => $sigEventCount,
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
            'head_hash'      => $headHash,        // COLUMN (may diverge from the signed head_hash)
            'event_count'    => $colEventCount,   // COLUMN
            'signed_at'      => 1_700_000_100,
            'key_id'         => $keyId,
            'signed_payload' => $payload,
            'signature'      => $sigHex,
            'anchor_url'     => null,
        ];
    }

    /** An IClientService whose single GET returns the canned status + raw body (for anchor checks). */
    private function mockClient(int $status, string $body): IClientService {
        $response = $this->createMock(\OCP\Http\Client\IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->method('get')->willReturn($response);
        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($client);
        return $service;
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

    public function testTamperedPrevHashOnlyDetected(): void {
        // F1: corrupt ONLY the stored prev_hash column of row seq_num=2. Its chain_hash stays the
        // (correct) value — so the chain_hash re-derivation from the in-memory prevHash still matches
        // and would NOT catch it. Only the explicit stored-prev_hash compare flags the tamper.
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 10),
            $this->event(2, 'cert.issued', 10),
            $this->event(3, 'cert.revoked', 10),
        ]);
        $events[1]['prev_hash'] = str_repeat('e', 64); // tamper prev_hash only; chain_hash untouched

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])), // no checkpoints
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class));

        $this->assertSame(Command::FAILURE, $code, 'a prev_hash-only tamper must fail verification');
        $this->assertStringContainsString('prev_hash tampered at seq_num=2', $out);
        $this->assertStringContainsString(self::RUNBOOK, $out);
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

    public function testCheckpointSignedHeadHashMismatchFails(): void {
        // F2: signature is valid over the payload, but the payload's head_hash differs from the stored
        // head_hash COLUMN (a DB-column tamper the signature cannot detect on its own).
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(3, 1, $head, 'kid-1', $keys['sec'], payloadHeadHash: str_repeat('1', 64));

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            // no head_hash builder — the F2 field check `continue`s before the head query.
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('signed head_hash does not match', $out);
        $this->assertStringContainsString('Checkpoint 3', $out);
    }

    public function testCheckpointWrongEventCountFails(): void {
        // F2: from=1,to=3 → contiguous window size 3, but event_count claims 99 (payload + column agree,
        // so the field check passes; the arithmetic check catches the impossible count).
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 1),
            $this->event(2, 'cert.issued', 1),
            $this->event(3, 'cert.revoked', 1),
        ]);
        $head = $events[2]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(4, 3, $head, 'kid-1', $keys['sec'], eventCount: 99);

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            // no head_hash builder — the F2 arithmetic check `continue`s before the head query.
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('event_count=99', $out);
        $this->assertStringContainsString('window size 3', $out);
    }

    public function testEventsWithoutCheckpointWarns(): void {
        // F2: an intact chain with zero verified checkpoints → not a FAILURE (in-DB chain is
        // self-consistent) but a visible WARNING that the trail is unanchored.
        $events = $this->buildChain([
            $this->event(1, 'course.passed', 1),
            $this->event(2, 'cert.issued', 1),
        ]);

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])), // zero checkpoints
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->createMock(CertKeyMapper::class));

        $this->assertSame(Command::SUCCESS, $code, 'an unanchored-but-intact chain is a warning, not a failure');
        $this->assertStringContainsString('unanchored', $out);
        $this->assertStringContainsString('Chain intact', $out);
    }

    public function testBadPubkeyLengthFailsNotFatal(): void {
        // F7: the mapper hands back a 16-byte "public key" — it base64-decodes fine but is the wrong
        // Ed25519 length. The length guard must record a FAILURE (not throw a fatal SodiumException).
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(5, 1, $head, 'kid-1', $keys['sec']);
        $shortPub = random_bytes(16); // valid base64url, wrong length

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            // no head builder — the length guard `continue`s before the verify + head query.
        ]);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $shortPub));

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('bad key length', $out);
        $this->assertStringContainsString('Checkpoint 5', $out);
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

    public function testAnchorRawBytesMatchNoWarning(): void {
        // F4: anchor_url is the RAW file endpoint; the fetched bytes equal signed_payload → clean.
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(1, 1, $head, 'kid-1', $keys['sec']);
        $checkpoint['anchor_url'] = 'https://git.andrestiebitz.de/andre/audit-anchors/raw/branch/main/checkpoint-1.json';

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            new FakeQueryBuilder(FakeResult::fromFetchOne($head)), // head_hash consistency
        ]);
        $client = $this->mockClient(200, $checkpoint['signed_payload']); // raw bytes == signed_payload

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']), [], $client);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringNotContainsString('anchor mismatch', $out);
        $this->assertStringContainsString('Chain intact', $out);
    }

    public function testAnchorRawBytesMismatchWarnsButPasses(): void {
        // F4: fetched anchor bytes differ from signed_payload → WARNING only (never flips the exit code).
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(1, 1, $head, 'kid-1', $keys['sec']);
        $checkpoint['anchor_url'] = 'https://git.andrestiebitz.de/andre/audit-anchors/raw/branch/main/checkpoint-1.json';

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            new FakeQueryBuilder(FakeResult::fromFetchOne($head)),
        ]);
        $client = $this->mockClient(200, 'tampered anchor bytes that are not the signed payload');

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']), [], $client);

        $this->assertSame(Command::SUCCESS, $code, 'anchor mismatch is warning-only');
        $this->assertStringContainsString('anchor mismatch', $out);
    }

    public function testNonHttpsAnchorSkipped(): void {
        // F6: a non-https anchor_url must be skipped (never fetched) with a warning — warning-only.
        $events = $this->buildChain([$this->event(1, 'course.passed', 1)]);
        $head = $events[0]['chain_hash'];
        $keys = $this->makeKeypair();
        $checkpoint = $this->signedCheckpoint(1, 1, $head, 'kid-1', $keys['sec']);
        $checkpoint['anchor_url'] = 'http://insecure.example/checkpoint-1.json';

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll($events)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$checkpoint])),
            new FakeQueryBuilder(FakeResult::fromFetchOne($head)),
        ]);
        // Even if the client would return matching bytes, the https guard must skip the fetch.
        $client = $this->mockClient(200, $checkpoint['signed_payload']);

        [$code, $out] = $this->invokeCmd($db, $this->mockMapper('kid-1', $keys['pub']), [], $client);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('non-https', $out);
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
