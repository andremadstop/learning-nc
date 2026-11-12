<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Controller\PoolImportController;
use OCA\Learning\Db\Pool;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/pools/import — import of whole pools in the Pools tab (GitHub #34): instructors
 * only, the limits of the question import plus a pool and question cap, a dry run that writes
 * nothing, and the same import as occ learning:import-pool-json.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolImportControllerTest extends TestCase {
    use PoolImportHarness;

    /** @var list<array<int, mixed>> arguments of each PoolService::create call */
    private array $createdPools = [];
    /** @var list<array<int, mixed>> arguments of each QuestionService::create call */
    private array $createdQuestions = [];
    private FakeDbConnection $db;

    private function services(): array {
        $this->createdPools = [];
        $this->createdQuestions = [];
        $this->db = new FakeDbConnection();
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(function (...$args): Pool {
            $this->createdPools[] = $args;
            $pool = new Pool();
            $pool->setId(200 + count($this->createdPools));
            return $pool;
        });
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(function (...$args): array {
            $this->createdQuestions[] = $args;
            return [];
        });
        return [$pools, $questions];
    }

    /** @param array<string, mixed> $params request parameters (jsonData, dry_run, file_name) */
    private function call(array $params, bool $instructor = true, string $marker = '', ?array $upload = null): array {
        [$pools, $questions] = $this->services();
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
        $request->method('getUploadedFile')->willReturnCallback(static fn (string $key) => $key === 'file' ? $upload : null);
        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturnCallback(static fn (string $uid): bool => $instructor && $uid === 'teacher');
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, $default = '') => $default);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => $marker . vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
        );

        $controller = new PoolImportController('learning', $request, 'teacher', $roles, $this->poolImporter($pools, $questions, $this->db), $config, $l10n);
        $response = $controller->importPools((string)($params['jsonData'] ?? ''));
        return [$response->getStatus(), $response->getData()];
    }

    private static function q(string $text): array {
        return ['text' => $text, 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]];
    }

    /** @return array<string, mixed> */
    private static function twoPools(): array {
        return [
            '_meta' => ['handbook_key' => 'ccna', 'default_difficulty' => 'hard'],
            'pools' => [
                ['name' => 'Ports', 'chapter_key' => '3.1', 'chapter_order' => 3, 'exam_key_prefix' => 'ccna', 'questions' => [self::q('HTTPS?'), self::q('SSH?'), ['text' => 'No answers']]],
                ['name' => 'Layers', 'description' => 'OSI', 'questions' => [self::q('Layer 3?')]],
            ],
        ];
    }

    private function assertNothingWritten(): void {
        $this->assertSame([], $this->createdPools);
        $this->assertSame([], $this->createdQuestions);
        $this->assertSame(0, $this->db->beginTransactionCalls);
    }

    public function testDryRunAnswersPerPoolAndWritesNothing(): void {
        [$status, $data] = $this->call(['jsonData' => json_encode(self::twoPools()), 'dry_run' => 'true'], true, '»');

        $this->assertSame(Http::STATUS_OK, $status);
        $this->assertNothingWritten();
        $this->assertSame([
            'dry_run' => true,
            'pools' => [
                ['index' => 1, 'name' => 'Ports', 'questions_total' => 3, 'importable' => 2, 'errors' => ['»Q3: missing text or answers — skipped'], 'warnings' => []],
                ['index' => 2, 'name' => 'Layers', 'questions_total' => 1, 'importable' => 1, 'errors' => [], 'warnings' => []],
            ],
            'total_pools' => 2,
            'total_questions' => 4,
            'total_importable' => 3,
        ], $data);
    }

    public function testImportCreatesEveryPoolForTheCaller(): void {
        [$status, $data] = $this->call(['jsonData' => json_encode(self::twoPools())]);

        $this->assertSame(Http::STATUS_CREATED, $status);
        $this->assertSame(['Ports', 'Layers'], array_map(static fn (array $a) => $a[0], $this->createdPools));
        $this->assertSame(['teacher', 'teacher'], array_map(static fn (array $a) => $a[2], $this->createdPools));
        $this->assertSame([
            'dry_run' => false,
            'pools' => [
                ['index' => 1, 'id' => 201, 'name' => 'Ports', 'questions_total' => 3, 'imported' => 2, 'errors' => ['Q3: missing text or answers — skipped'], 'warnings' => []],
                ['index' => 2, 'id' => 202, 'name' => 'Layers', 'questions_total' => 1, 'imported' => 1, 'errors' => [], 'warnings' => []],
            ],
            'created_pools' => 2,
            'imported_questions' => 3,
        ], $data);
        $this->assertSame([2, 2, 0], [$this->db->beginTransactionCalls, $this->db->commitCalls, $this->db->rollBackCalls]);
    }

    public function testDryRunFalseAsTextImports(): void {
        [$status] = $this->call(['jsonData' => json_encode(self::twoPools()), 'dry_run' => 'false']);

        $this->assertSame(Http::STATUS_CREATED, $status);
        $this->assertCount(2, $this->createdPools);
    }

    public function testAnUnreadableDryRunIsRefusedInsteadOfImporting(): void {
        [$status, $data] = $this->call(['jsonData' => json_encode(self::twoPools()), 'dry_run' => 'maybe']);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $status);
        $this->assertSame(['error' => 'dry_run must be true or false'], $data);
        $this->assertNothingWritten();
    }

    public function testNonInstructorIsRefused(): void {
        [$status, $data] = $this->call(['jsonData' => json_encode(self::twoPools())], false);

        $this->assertSame(Http::STATUS_FORBIDDEN, $status);
        $this->assertSame(['error' => 'Only instructors can import pools'], $data);
        $this->assertNothingWritten();
    }

    public function testTooManyPoolsIsRefused(): void {
        $pools = array_map(static fn (int $i): array => ['name' => "P$i", 'questions' => [self::q('Q')]], range(1, PoolImportController::MAX_POOLS + 1));
        [$status, $data] = $this->call(['jsonData' => json_encode(['pools' => $pools])]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $status);
        $this->assertSame(['error' => 'Maximum 50 pools per import'], $data);
        $this->assertNothingWritten();
    }

    public function testFiftyPoolsAreAllowed(): void {
        $pools = array_map(static fn (int $i): array => ['name' => "P$i", 'questions' => [self::q('Q')]], range(1, PoolImportController::MAX_POOLS));
        [$status] = $this->call(['jsonData' => json_encode(['pools' => $pools]), 'dry_run' => true]);

        $this->assertSame(Http::STATUS_OK, $status);
    }

    public function testTooManyQuestionsIsRefused(): void {
        $questions = array_fill(0, 2501, self::q('Q'));
        [$status, $data] = $this->call(['jsonData' => json_encode(['pools' => [['name' => 'A', 'questions' => $questions], ['name' => 'B', 'questions' => $questions]]])]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $status);
        $this->assertSame(['error' => 'Maximum 5000 questions per import'], $data);
        $this->assertNothingWritten();
    }

    public function testTooLargeIsRefused(): void {
        // max_import_size_mb defaults to 2
        [$status, $data] = $this->call(['jsonData' => json_encode(['pools' => [['name' => str_repeat('x', 2 * 1024 * 1024)]]])]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $status);
        $this->assertSame(['error' => 'JSON data too large (max 2 MB)'], $data);
        $this->assertNothingWritten();
    }

    public function testInputErrorsAre400NotServerErrors(): void {
        foreach ([
            '' => 'No data to import',
            '{"pools": [' => 'Invalid JSON — check for missing brackets, commas, or quotes',
            '{"name": "nothing"}' => 'JSON must contain a "pools" array, a "questions" array, or be a list of questions',
            '{"pools": []}' => 'The file contains no pools',
        ] as $json => $error) {
            [$status, $data] = $this->call(['jsonData' => (string)$json]);
            $this->assertSame(Http::STATUS_BAD_REQUEST, $status, (string)$json);
            $this->assertSame(['error' => $error], $data);
            $this->assertNothingWritten();
        }
    }

    public function testNothingImportableIs400WithThePerPoolAnswer(): void {
        [$status, $data] = $this->call(['jsonData' => json_encode(['pools' => [['questions' => [self::q('Q')]]]])]);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $status);
        $this->assertSame(0, $data['created_pools']);
        $this->assertSame(['Pool #1 missing name — skipped'], $data['pools'][0]['errors']);
        $this->assertNothingWritten();
    }

    public function testAnExportFileIsOnePoolNamedAfterTheFile(): void {
        $export = [self::q('Single'), ['text' => 'Open', 'type' => 'open', 'answers' => [['text' => 'model', 'is_correct' => true]]]];
        [$status, $data] = $this->call(['jsonData' => "\xEF\xBB\xBF" . json_encode($export), 'file_name' => 'pool-12.json']);

        $this->assertSame(Http::STATUS_CREATED, $status);
        $this->assertSame(['pool-12'], array_map(static fn (array $a) => $a[0], $this->createdPools));
        $this->assertSame(2, $data['pools'][0]['imported']);
        $this->assertSame(['single', 'open'], array_map(static fn (array $a) => $a[6], $this->createdQuestions));
    }

    public function testAnUploadedFileIsReadAndNamesItsPool(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'learning-upload-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, json_encode([self::q('Single')]));
        [$status, $data] = $this->call(['dry_run' => '1'], true, '', ['name' => 'network.json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($tmp)]);
        [$bigStatus, $bigData] = $this->call([], true, '', ['name' => 'big.json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 2 * 1024 * 1024 + 1]);
        [$errStatus] = $this->call([], true, '', ['name' => 'x.json', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0]);
        unlink($tmp);

        $this->assertSame(Http::STATUS_OK, $status);
        $this->assertSame('network', $data['pools'][0]['name']);
        $this->assertSame(1, $data['pools'][0]['importable']);
        $this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'File too large (max 2 MB)']], [$bigStatus, $bigData]);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $errStatus);
        $this->assertNothingWritten();
    }

    public function testAnExportWithoutFileNameGetsADefaultName(): void {
        $this->call(['jsonData' => json_encode([self::q('Single')])]);

        $this->assertSame(['Imported pool'], array_map(static fn (array $a) => $a[0], $this->createdPools));
    }

    /**
     * The pool import in the interface and occ learning:import-pool-json are one import: the
     * same file ends in the same PoolService/QuestionService calls, argument for argument.
     */
    public function testOccAndTheEndpointGoTheSameWay(): void {
        $json = (string)json_encode(self::twoPools());
        $this->call(['jsonData' => $json]);
        $viaEndpoint = [$this->createdPools, $this->createdQuestions];

        [$pools, $questions] = $this->services();
        $file = tempnam(sys_get_temp_dir(), 'learning-pools-');
        $this->assertNotFalse($file);
        file_put_contents($file, $json);
        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        $input->method('getArgument')->willReturn($file);
        $input->method('getOption')->willReturnCallback(static fn (string $n) => $n === 'user' ? 'teacher' : null);
        $exit = $this->occImportCommand($pools, $questions)->run($input, $this->createMock(\Symfony\Component\Console\Output\OutputInterface::class));
        unlink($file);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $viaEndpoint[0]);
        $this->assertCount(3, $viaEndpoint[1]);
        $this->assertSame($viaEndpoint, [$this->createdPools, $this->createdQuestions]);
    }

    public function testOccHandsTheFileToTheImportService(): void {
        $file = tempnam(sys_get_temp_dir(), 'learning-pools-');
        $this->assertNotFalse($file);
        file_put_contents($file, json_encode(self::twoPools()));
        $importer = $this->createMock(PoolBulkImportService::class);
        $importer->expects($this->once())->method('import')
            ->with($this->callback(static fn (array $doc): bool => array_column($doc['pools'], 'name') === ['Ports', 'Layers']), 'teacher', true)
            ->willReturn([]);
        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        $input->method('getArgument')->willReturn($file);
        $input->method('getOption')->willReturnCallback(static fn (string $n) => ['user' => 'teacher', 'dry-run' => true][$n] ?? null);

        (new ImportPoolJsonCommand($importer, $this->englishL10n()))->run($input, $this->createMock(\Symfony\Component\Console\Output\OutputInterface::class));
        unlink($file);
    }
}
