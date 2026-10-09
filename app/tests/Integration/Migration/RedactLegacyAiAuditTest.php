<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Repair\RedactLegacyAiAudit;
use OCA\Learning\Tests\Integration\Support\RecordingOutput;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task C3: up to 5.5 the AI facade wrote prompt and answer excerpts into
 * learning_audit_events. The upgrade redacts them, on the real database of both engines:
 *
 * - only the event keys 5.5.1's GeminiService wrote (allowlist), only rows outside the hash chain;
 * - an allowlisted row inside the chain aborts the whole run before anything is changed, because
 *   rewriting it would break `occ learning:audit:verify`;
 * - rows without content (the metadata-only rows 5.7 writes under `ai_chat`) stay as they are;
 * - one run per instance (marker), paged by id so a large table neither loops nor loads at once.
 */
final class RedactLegacyAiAuditTest extends TestCase {
    private const MARKER = 'llm_audit_redacted';
    private const UID = 'c3-redact-probe';

    private IDBConnection $db;
    private IAppConfig $appConfig;
    /** @var list<int> rows this test inserted */
    private array $ids = [];
    /** marker as the instance had it (a fresh install sets it), restored afterwards */
    private ?string $markerBefore = null;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->appConfig->clearCache();
        $this->markerBefore = $this->appConfig->hasKey('learning', self::MARKER)
            ? $this->appConfig->getValueString('learning', self::MARKER, '') : null;
        $this->appConfig->deleteKey('learning', self::MARKER);
        // Rows of other suites must not decide this test's outcome: a chained allowlist row left
        // behind elsewhere would abort every run here.
        $this->assertSame(0, $this->countChainedAllowlistRows(), 'precondition: no chained AI rows in the table');
    }

    protected function tearDown(): void {
        foreach (array_chunk($this->ids, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_audit_events')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
        $this->ids = [];
        $this->appConfig->deleteKey('learning', self::MARKER);
        if ($this->markerBefore !== null) {
            $this->appConfig->setValueString('learning', self::MARKER, $this->markerBefore);
        }
    }

    private function step(): RedactLegacyAiAudit {
        return Server::get(RedactLegacyAiAudit::class);
    }

    public function testEveryAllowlistedKeyLosesItsContentAndKeepsTheModel(): void {
        $keys = ['ai_chat', 'ai_structured', 'ai_telos_extract', 'telos_interview_turn', 'note_generation', 'ticket_triage'];
        $rows = [];
        foreach ($keys as $key) {
            $rows[$key] = $this->insert($key, ['input' => "secret prompt $key", 'output' => "secret answer $key", 'model' => 'gemini']);
        }

        $output = new RecordingOutput();
        $this->assertSame(RedactLegacyAiAudit::RESULT_DONE, $this->step()->run($output));

        foreach ($rows as $key => $id) {
            $this->assertSame(
                ['input' => '[redacted]', 'output' => '[redacted]', 'model' => 'gemini'],
                json_decode((string)$this->context($id), true),
                "$key must be redacted",
            );
        }
        $this->assertSame('yes', $this->marker());
        $this->assertSame([], $output->warnings);
        $this->assertNotSame([], $output->infos, 'the run reports how many rows it redacted');
    }

    public function testOtherEventKeysAndContentFreeRowsStayUntouched(): void {
        $foreign = $this->insert('ai_explain', ['input' => 'kept prompt', 'output' => 'kept answer', 'model' => 'gemini']);
        $metadata = $this->insert('ai_chat', ['purpose' => 'chat', 'outcome' => 'output_blocked', 'provider' => 'gemini']);
        $empty = $this->insert('note_generation', null);
        $before = [$foreign => $this->context($foreign), $metadata => $this->context($metadata), $empty => $this->context($empty)];

        $this->assertSame(RedactLegacyAiAudit::RESULT_DONE, $this->step()->run(new RecordingOutput()));

        foreach ($before as $id => $context) {
            $this->assertSame($context, $this->context($id));
        }
    }

    public function testUndecodableContentIsRedactedWithoutModel(): void {
        $id = $this->insertRaw('ai_chat', '{"input":"truncated secret');

        $this->assertSame(RedactLegacyAiAudit::RESULT_DONE, $this->step()->run(new RecordingOutput()));

        $this->assertSame(['input' => '[redacted]', 'output' => '[redacted]', 'model' => null], json_decode((string)$this->context($id), true));
    }

    public function testAChainedAllowlistRowAbortsBeforeAnythingChanges(): void {
        $plain = $this->insert('ai_chat', ['input' => 'must survive the abort', 'output' => 'x', 'model' => 'gemini']);
        $chained = $this->insert('note_generation', ['input' => 'chained', 'output' => 'y', 'model' => 'gemini'], str_repeat('a', 64), null);
        $before = [$plain => $this->context($plain), $chained => $this->context($chained)];

        $output = new RecordingOutput();
        $this->assertSame(RedactLegacyAiAudit::RESULT_ABORTED_CHAINED, $this->step()->run($output));

        foreach ($before as $id => $context) {
            $this->assertSame($context, $this->context($id), 'an aborted run changes nothing');
        }
        $this->assertSame('', $this->marker(), 'an aborted run must be retried, so it sets no marker');
        $this->assertCount(1, $output->warnings);
        $this->assertStringContainsString('1', $output->warnings[0]);
    }

    public function testAPrevHashAloneCountsAsChained(): void {
        $plain = $this->insert('ai_chat', ['input' => 'must survive', 'output' => 'x', 'model' => 'gemini']);
        $this->insert('ai_chat', ['input' => 'linked', 'output' => 'y', 'model' => 'gemini'], null, str_repeat('b', 64));
        $before = $this->context($plain);

        $this->assertSame(RedactLegacyAiAudit::RESULT_ABORTED_CHAINED, $this->step()->run(new RecordingOutput()));
        $this->assertSame($before, $this->context($plain));
    }

    public function testSecondRunIsANoOp(): void {
        $this->insert('ai_chat', ['input' => 'first', 'output' => 'x', 'model' => 'gemini']);
        $this->assertSame(RedactLegacyAiAudit::RESULT_DONE, $this->step()->run(new RecordingOutput()));

        $later = $this->insert('ai_chat', ['input' => 'written after the upgrade', 'output' => 'x', 'model' => 'gemini']);
        $before = $this->context($later);

        $this->assertSame(RedactLegacyAiAudit::RESULT_ALREADY, $this->step()->run(new RecordingOutput()));
        $this->assertSame($before, $this->context($later));
    }

    public function testMoreRowsThanOneBatchAreAllRedacted(): void {
        $n = RedactLegacyAiAudit::BATCH_SIZE * 2 + 3;
        $this->db->beginTransaction();
        for ($i = 0; $i < $n; $i++) {
            $this->insert('ai_chat', ['input' => "bulk $i", 'output' => 'x', 'model' => 'ollama']);
        }
        $this->db->commit();

        $this->assertSame(RedactLegacyAiAudit::RESULT_DONE, $this->step()->run(new RecordingOutput()));

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('learning_audit_events')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($this->ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('context_json', $qb->createNamedParameter('{"input":"[redacted]","output":"[redacted]","model":"ollama"}')));
        $r = $qb->executeQuery();
        $this->assertSame($n, (int)$r->fetchOne());
        $r->closeCursor();
    }

    public function testMarkDoneSkipsALaterRun(): void {
        $id = $this->insert('ai_chat', ['input' => 'fresh install, never redacted', 'output' => 'x', 'model' => 'gemini']);
        $before = $this->context($id);

        $this->step()->markDone();

        $this->assertSame(RedactLegacyAiAudit::RESULT_ALREADY, $this->step()->run(new RecordingOutput()));
        $this->assertSame($before, $this->context($id));
    }

    /** @param array<string, mixed>|null $context */
    private function insert(string $key, ?array $context, ?string $chainHash = null, ?string $prevHash = null): int {
        return $this->insertRaw($key, $context === null ? null : json_encode($context, JSON_UNESCAPED_SLASHES), $chainHash, $prevHash);
    }

    private function insertRaw(string $key, ?string $contextJson, ?string $chainHash = null, ?string $prevHash = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_audit_events')->values([
            'event_key' => $qb->createNamedParameter($key),
            'user_id' => $qb->createNamedParameter(self::UID),
            'context_json' => $qb->createNamedParameter($contextJson),
            'chain_hash' => $qb->createNamedParameter($chainHash),
            'prev_hash' => $qb->createNamedParameter($prevHash),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $id = $qb->getLastInsertId();
        $this->ids[] = $id;
        return $id;
    }

    private function context(int $id): ?string {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('context_json')->from('learning_audit_events')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $v = $r->fetchOne();
        $r->closeCursor();
        $this->assertNotFalse($v, "row $id must exist");
        return $v === null ? null : (string)$v;
    }

    private function marker(): string {
        $this->appConfig->clearCache();
        return $this->appConfig->getValueString('learning', self::MARKER, '');
    }

    private function countChainedAllowlistRows(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('learning_audit_events')
            ->where($qb->expr()->in('event_key', $qb->createNamedParameter(RedactLegacyAiAudit::EVENT_KEYS, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->orX($qb->expr()->isNotNull('chain_hash'), $qb->expr()->isNotNull('prev_hash')));
        $r = $qb->executeQuery();
        $n = (int)$r->fetchOne();
        $r->closeCursor();
        return $n;
    }
}
