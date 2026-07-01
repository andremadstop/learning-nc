<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\AuditExportController;
use OCA\Learning\Db\CertKey;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 161 Plan 04 — AuditExportController (auditor-gated compliance export, AUDIT-07).
 *
 * The controller gates every action on membership of the configurable 'learning-auditors' group
 * (@NoAdminRequired + IGroupManager::isInGroup) — NOT admin. It emits a deterministic JSONL event log
 * (user_ref ONLY, never user_id — DSGVO), a detached Ed25519 signature over the EXACT JSONL bytes
 * (signed inline via KeyService, secret memzeroed), and a printable HTML report carrying sha256(JSONL).
 *
 * @NoAdminRequired / @NoCSRFRequired / the auditor gate are middleware/PHPDoc concerns; instantiating
 * the controller directly bypasses NC middleware, so these tests exercise the in-method group gate
 * (isInGroup → 403) and the export payloads.
 *
 * @group audit-07
 */
class AuditExportControllerTest extends TestCase {
    /** Two raw DB rows — deliberately carrying a `user_id` column to PROVE it never reaches the output. */
    private const RAW_ROWS = [
        [
            'seq_num' => 1,
            'event_key' => 'course.passed',
            'user_ref' => 'aaaaaaaaaaaaaaaa',
            'context_json' => '{"course_id":62,"score":90}',
            'created_at' => 1700000000,
            'chain_hash' => 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00',
            'user_id' => 'alice-secret-uid',
        ],
        [
            'seq_num' => 2,
            'event_key' => 'cert.issued',
            'user_ref' => 'bbbbbbbbbbbbbbbb',
            'context_json' => '{"course_id":63}',
            'created_at' => 1700000100,
            'chain_hash' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef0',
            'user_id' => 'bob-secret-uid',
        ],
    ];

    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $request;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private $groupManager;
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var KeyService&\PHPUnit\Framework\MockObject\MockObject */
    private $keyService;
    /** @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject */
    private $urlGenerator;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturn(null); // no filters by default
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => $default
        );
        $this->keyService = $this->createMock(KeyService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('linkToRoute')->willReturnCallback(
            static fn (string $route) => '/apps/learning/' . $route
        );
    }

    private function makeController(FakeDbConnection $db, string $userId = 'auditor'): AuditExportController {
        return new AuditExportController(
            'learning',
            $this->request,
            $this->keyService,
            $this->groupManager,
            $this->config,
            $db,
            $this->urlGenerator,
            $userId
        );
    }

    /** A FakeDbConnection whose single queued builder yields the given rows from executeQuery()->fetchAll(). */
    private function dbWithRows(array $rows): FakeDbConnection {
        $builder = new FakeQueryBuilder(FakeResult::fromFetchAll($rows));
        return new FakeDbConnection([$builder]);
    }

    /** Independently reconstruct the exact JSONL bytes the controller must emit for RAW_ROWS (no filters). */
    private function expectedJsonl(): string {
        $out = '';
        foreach (self::RAW_ROWS as $row) {
            $ctx = json_decode($row['context_json'], true);
            $line = [
                'seq_num' => (int)$row['seq_num'],
                'event_key' => $row['event_key'],
                'user_ref' => $row['user_ref'],
                'course_id' => isset($ctx['course_id']) ? (int)$ctx['course_id'] : null,
                'created_at' => (int)$row['created_at'],
                'chain_hash' => $row['chain_hash'],
                'context_json' => $row['context_json'],
            ];
            $out .= json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        return $out;
    }

    /**
     * Test 1 (403, no leak): a non-auditor gets HTTP 403 on EVERY action and the DB is NEVER touched
     * (no builder issued) — the deny path leaks no event data, structurally.
     */
    public function testNonAuditorForbiddenOnAllActionsAndNoDbAccess(): void {
        $this->groupManager->method('isInGroup')->willReturn(false);

        foreach (['events', 'sig', 'report', 'page'] as $action) {
            $db = new FakeDbConnection();
            $resp = $this->makeController($db)->{$action}();
            $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus(), "$action must 403 for a non-auditor");
            $this->assertSame([], $db->issuedBuilders, "$action must not touch the DB on the deny path");
        }
    }

    /**
     * Test 2 (JSONL + DSGVO): an auditor gets the deterministic JSONL, and NO line carries a user_id key
     * (only user_ref) — verified at decoded-JSON-key level, not by raw substring.
     */
    public function testEventsReturnsJsonlWithoutUserId(): void {
        $this->groupManager->method('isInGroup')->willReturn(true);

        $resp = $this->makeController($this->dbWithRows(self::RAW_ROWS))->events();

        $this->assertInstanceOf(DataDownloadResponse::class, $resp);
        $jsonl = $resp->render();
        $this->assertSame($this->expectedJsonl(), $jsonl, 'JSONL must be byte-deterministic');

        $lines = array_filter(explode("\n", $jsonl), static fn ($l) => $l !== '');
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertArrayNotHasKey('user_id', $row, 'DSGVO: user_id must never appear in the JSONL');
            $this->assertArrayHasKey('user_ref', $row, 'the pseudonymous user_ref must be present');
        }
    }

    /**
     * Test 3 (exact course_id filter): course_id=62 keeps only the matching row (PHP post-filter, exact —
     * not a fragile LIKE that would also match 620/1062).
     */
    public function testCourseIdFilterIsExact(): void {
        $this->groupManager->method('isInGroup')->willReturn(true);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key) => $key === 'course_id' ? '62' : null
        );

        $controller = new AuditExportController(
            'learning', $request, $this->keyService, $this->groupManager,
            $this->config, $this->dbWithRows(self::RAW_ROWS), $this->urlGenerator, 'auditor'
        );

        $jsonl = $controller->events()->render();
        $lines = array_filter(explode("\n", $jsonl), static fn ($l) => $l !== '');
        $this->assertCount(1, $lines, 'only the course_id=62 row survives');
        $row = json_decode(reset($lines), true);
        $this->assertSame(62, $row['course_id']);
    }

    /**
     * Test 4 (detached sig verifies): the .sig payload is `hex . "\n"`; trimming and hex2bin-ing it,
     * sodium_crypto_sign_verify_detached against the EXACT JSONL bytes + the issuer public key succeeds.
     */
    public function testSigVerifiesAgainstJsonl(): void {
        $this->groupManager->method('isInGroup')->willReturn(true);

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secret = sodium_crypto_sign_secretkey($keypair);
        $this->keyService->method('getActiveSigningMaterial')
            ->willReturn(['key' => new CertKey(), 'secret' => $secret]);

        $resp = $this->makeController($this->dbWithRows(self::RAW_ROWS))->sig();
        $this->assertInstanceOf(DataDownloadResponse::class, $resp);

        $sigFile = $resp->render();
        $this->assertStringEndsWith("\n", $sigFile, '.sig must carry the mandated trailing newline');
        $sigRaw = hex2bin(trim($sigFile));
        $this->assertNotFalse($sigRaw);

        $ok = sodium_crypto_sign_verify_detached($sigRaw, $this->expectedJsonl(), $publicKey);
        $this->assertTrue($ok, 'the detached signature must verify against the exact JSONL bytes');
    }

    /**
     * Test 5 (report footer integrity): the HTML report embeds sha256(JSONL) so it can be cross-checked
     * against the separately downloaded .jsonl / .sig files.
     */
    public function testReportHtmlContainsSha256(): void {
        $this->groupManager->method('isInGroup')->willReturn(true);

        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $this->keyService->method('getActiveSigningMaterial')
            ->willReturn(['key' => new CertKey(), 'secret' => $secret]);

        $resp = $this->makeController($this->dbWithRows(self::RAW_ROWS))->report();
        $this->assertInstanceOf(DataDownloadResponse::class, $resp);

        $html = $resp->render();
        $sha256 = hash('sha256', $this->expectedJsonl());
        $this->assertStringContainsString($sha256, $html, 'report footer must embed sha256(JSONL)');
        $this->assertStringContainsString('course.passed', $html, 'events must render in the table');
        // DSGVO: the raw uid must not leak into the printable report either.
        $this->assertStringNotContainsString('alice-secret-uid', $html);
    }

    /**
     * Test 6 (page gate): an auditor reaches the export page (TemplateResponse for the self-contained UI).
     */
    public function testAuditorReachesPage(): void {
        $this->groupManager->method('isInGroup')->willReturn(true);

        $resp = $this->makeController(new FakeDbConnection())->page();
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame('audit-export-page', $resp->getTemplateName());
    }
}
