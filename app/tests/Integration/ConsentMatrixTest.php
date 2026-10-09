<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Controller\AdminAiController;
use OCA\Learning\Controller\AiController;
use OCA\Learning\Controller\LernplanController;
use OCA\Learning\Controller\NoteGeneratorController;
use OCA\Learning\Controller\PoolGeneratorController;
use OCA\Learning\Controller\SummaryController;
use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Controller\TelosController;
use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\StoryEngineService;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Spec 2.4 signature matrix (plan Task B5): one test per entry point, through the real controller
 * (or job / service where the row names one) built by the app container, the real gateway, budget
 * and Ollama adapter, against MockLlmServer. Without consent the mock sees 0 requests and the
 * caller gets the row's answer ("Consent-Fehler erreicht Aufrufer als"); with consent exactly 1.
 *
 * 5.7.0 enables AI only through the connection snapshot (ConnectionStore::save()). The 5.5 key
 * ai_enabled stays 'no' here, so an entry point that still reads it fails its "with consent" half.
 */
final class ConsentMatrixTest extends TestCase {
    private const TEACHER = 'b5-matrix-teacher';

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;
    private string $uid;
    private ?IUser $user = null;
    /** @var list<int> */
    private array $courseIds = [];

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->clearConnection();
        MockLlmServer::reset();

        // A real user: note, weekly note and plan write into the user's files.
        $this->uid = 'b5-matrix-' . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(16)) . 'Aa1!');
        $this->assertInstanceOf(IUser::class, $user, 'test user created');
        $this->user = $user;

        $store = $this->container->get(ConnectionStore::class);
        $store->save(LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl(),
            'model' => 'mock-model', 'allowInternalNetwork' => true, 'processingLocal' => false,
        ]), 'admin');
        $this->assertSame('no', $this->appConfig->getValueString('learning', 'ai_enabled', 'no'),
            'AI is on only through the snapshot, as after a 5.7.0 admin save');
    }

    protected function tearDown(): void {
        foreach ($this->courseIds as $id) {
            foreach (['learning_course_members' => 'course_id', 'learning_courses' => 'id'] as $table => $col) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            }
        }
        $poolIds = $this->column('learning_pools', 'id', 'user_id', $this->uid);
        if ($poolIds !== []) {
            $questionIds = $this->column('learning_questions', 'id', 'pool_id', $poolIds);
            if ($questionIds !== []) {
                $this->deleteWhere('learning_answers', 'question_id', $questionIds);
                $this->deleteWhere('learning_questions', 'id', $questionIds);
            }
            $this->deleteWhere('learning_pools', 'id', $poolIds);
        }
        foreach (['learning_support_tickets', 'learning_ai_chat_memory', 'learning_user_telos'] as $table) {
            $this->deleteWhere($table, 'user_id', [$this->uid]);
        }
        $this->user?->delete();
        $this->clearConnection();
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function clearConnection(): void {
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || str_starts_with($key, ConnectionStore::SAVED_PREFIX)) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    /** @param string|list<string|int> $value */
    private function column(string $table, string $select, string $where, string|array $value): array {
        $qb = $this->db->getQueryBuilder();
        $param = is_array($value)
            ? $qb->createNamedParameter($value, is_int($value[0] ?? null) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY)
            : $qb->createNamedParameter($value);
        $qb->select($select)->from($table)->where(is_array($value) ? $qb->expr()->in($where, $param) : $qb->expr()->eq($where, $param));
        $r = $qb->executeQuery();
        $out = array_map('intval', array_column($r->fetchAll(), $select));
        $r->closeCursor();
        return $out;
    }

    /** @param list<string|int> $values */
    private function deleteWhere(string $table, string $col, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $type = is_int($values[0] ?? null) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY;
        $qb->delete($table)->where($qb->expr()->in($col, $qb->createNamedParameter($values, $type)))->executeStatement();
    }

    private function grantConsent(): void {
        $c = $this->container->get(ConnectionStore::class)->load();
        $consent = $this->container->get(ConsentService::class);
        $consent->grant($this->uid, $c, $c->recipientFingerprint(), $consent->currentVersion(), 'dialog');
        MockLlmServer::reset();
    }

    private static function sent(): int {
        return count(MockLlmServer::requests());
    }

    /**
     * The controller as a request would get it: every dependency from the app container, the
     * current user as userId, and a request that answers getParam() from $params.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $params
     * @return T
     */
    private function controller(string $class, array $params = []): object {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
        $args = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $args[] = match (true) {
                $p->getName() === 'appName' => 'learning',
                $p->getName() === 'userId' || $p->getName() === 'UserId' => $this->uid,
                $typeName === IRequest::class => $request,
                default => $this->container->get($typeName),
            };
        }
        return new $class(...$args);
    }

    private static function assertConsentRequired(Response $resp, int $status = 403): void {
        self::assertSame($status, $resp->getStatus());
        $data = $resp->getData();
        self::assertIsArray($data);
        self::assertTrue($data['consent_required'] ?? false, 'the client learns that consent is required');
    }

    private function ownPool(): int {
        return $this->container->get(PoolService::class)->create('Subnetting B5', 'Matrix-Pool', $this->uid)->getId();
    }

    private function lastAiCallPurpose(): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('context_json')->from('learning_audit_events')
            ->where($qb->expr()->eq('event_key', $qb->createNamedParameter('ai_call')))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->uid)))
            ->orderBy('id', 'DESC')->setMaxResults(1);
        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        if ($row === false) {
            return null;
        }
        $ctx = json_decode((string)$row['context_json'], true);
        return is_array($ctx) ? ($ctx['purpose'] ?? null) : null;
    }

    // ---- one test per row of spec 2.4 ---------------------------------------------------------

    public function testVirtuProfChat(): void {
        $resp = $this->controller(VirtuProfController::class)->chat('Was ist Subnetting?');
        self::assertConsentRequired($resp);
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(VirtuProfController::class)->chat('Was ist Subnetting?');
        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(1, self::sent());
        $this->assertSame('chat', $this->lastAiCallPurpose());
    }

    public function testTelosInterviewTurn(): void {
        $history = [['question' => 'Was machst du gerade?', 'answer' => 'Ich lerne für Network+.']];
        self::assertConsentRequired($this->controller(VirtuProfController::class)->interviewTurn($history, 2));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(VirtuProfController::class)->interviewTurn($history, 2);
        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(1, self::sent());
        $this->assertSame('interview', $this->lastAiCallPurpose());
    }

    public function testTelosStructured(): void {
        $conversation = 'Ich arbeite im Support und lerne für Security+, Ziel ist die Prüfung im Frühjahr.';
        self::assertConsentRequired($this->controller(TelosController::class)->processInterview($conversation));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(TelosController::class)->processInterview($conversation);
        $this->assertNotSame(403, $resp->getStatus());
        $this->assertSame(1, self::sent());
        $this->assertSame('telos_structured', $this->lastAiCallPurpose());
    }

    public function testManualNote(): void {
        $poolId = $this->ownPool();
        self::assertConsentRequired($this->controller(NoteGeneratorController::class, ['pool_id' => $poolId])->generate());
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(NoteGeneratorController::class, ['pool_id' => $poolId])->generate();
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertSame(1, self::sent());
        $this->assertSame('note', $this->lastAiCallPurpose());
    }

    public function testWeeklyNote(): void {
        $this->ownPool();   // no answers yet: error rate 50 % counts as weak
        $job = $this->container->get(WeeklyLernplanJob::class);
        $processUser = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');

        $this->assertSame('skipped_consent', $processUser->invoke($job, $this->uid));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $this->assertSame('generated', $processUser->invoke($job, $this->uid));
        $this->assertSame(1, self::sent());
        $this->assertSame('weekly_note', $this->lastAiCallPurpose(), 'the job sends as weekly_note');
    }

    public function testCourseNarrative(): void {
        $now = time();
        $course = new Course();
        $course->setTitle('B5 Matrix-Kurs');
        $course->setInstructorId(self::TEACHER);
        $course->setStatus('active');
        $course->setCreatedAt($now);
        $course->setUpdatedAt($now);
        $course = $this->container->get(CourseMapper::class)->insert($course);
        $this->courseIds[] = $course->getId();
        $member = new CourseMember();
        $member->setCourseId($course->getId());
        $member->setUserId($this->uid);
        $member->setRole('student');
        $member->setEnrolledAt($now);
        $this->container->get(CourseMemberMapper::class)->insert($member);

        $resp = $this->controller(SummaryController::class)->generateNarrative($course->getId());
        self::assertConsentRequired($resp, 200);   // {consent_required: true} as before 5.7.0
        $this->assertNull($resp->getData()['narrative']);
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(SummaryController::class)->generateNarrative($course->getId());
        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(1, self::sent());
        // a user with consent has a Telos row; the narrative used to crash on it (getTelosConsent)
        $this->assertSame(MockLlmServer::REPLY, $resp->getData()['narrative'] ?? null);
        $this->assertSame('course_narrative', $this->lastAiCallPurpose());
    }

    public function testMemoryCompression(): void {
        $this->fillChatMemory(49);
        $memory = $this->container->get(AiChatMemoryService::class);
        $mapper = $this->container->get(AiChatMemoryMapper::class);

        $memory->saveExchange($this->uid, 'Frage 50', 'Antwort 50');   // 51 > 50: compression is due
        $this->assertSame(0, self::sent());
        $this->assertSame(51, $mapper->countByUser($this->uid), 'skipped silently, nothing lost');

        $this->grantConsent();
        $memory->saveExchange($this->uid, 'Frage 51', 'Antwort 51');   // 53: compress the oldest 10
        $this->assertSame(1, self::sent());
        $this->assertSame(53 - 10 + 1, $mapper->countByUser($this->uid));
        $this->assertSame('memory_compress', $this->lastAiCallPurpose());
    }

    public function testLernplan(): void {
        self::assertConsentRequired($this->controller(LernplanController::class)->generatePlan());
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(LernplanController::class)->generatePlan();
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertSame(1, self::sent());
        $this->assertSame('lernplan', $this->lastAiCallPurpose());
    }

    public function testStory(): void {
        $scene = ['id' => 'b5-scene', 'title' => 'Vorfall', 'narrative' => 'Statischer Erzähltext.', 'narrator_mode' => true];
        $campaign = ['campaign_id' => 'b5-campaign', 'focus_areas' => ['security'], 'narrator_style' => 'sachlich'];
        $story = $this->container->get(StoryEngineService::class);

        $this->assertSame('Statischer Erzähltext.', $story->generateNarrative($scene, $campaign, 'helpdesk', [], $this->uid),
            "'' from the AI call: the static fallback");
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $this->assertSame(MockLlmServer::REPLY, $story->generateNarrative($scene, $campaign, 'helpdesk', [], $this->uid));
        $this->assertSame(1, self::sent());
        $this->assertSame('story', $this->lastAiCallPurpose());
    }

    public function testTicketTriage(): void {
        $resp = $this->controller(SupportTicketController::class)->create('Login kaputt', 'Ich komme nicht mehr rein.');
        $this->assertSame(201, $resp->getStatus(), 'the ticket stays');
        $this->assertNull($resp->getData()['ticket']['ai_label'] ?? null, 'triage skipped');
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(SupportTicketController::class)->create('Login kaputt', 'Ich komme nicht mehr rein.');
        $this->assertSame(201, $resp->getStatus());
        $this->assertSame(1, self::sent(), 'triage sent with the author\'s consent');
        $this->assertSame('ticket_triage', $this->lastAiCallPurpose());
        $this->assertCount(2, $this->column('learning_support_tickets', 'id', 'user_id', $this->uid));
    }

    public function testPoolGenerator(): void {
        $text = str_repeat('Ein Subnetz teilt ein Netzwerk in kleinere logische Bereiche auf. ', 2);
        self::assertConsentRequired($this->controller(PoolGeneratorController::class)->fromText($text, 3));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(PoolGeneratorController::class)->fromText($text, 3);
        $this->assertNotSame(403, $resp->getStatus());
        $this->assertSame(1, self::sent());
        $this->assertSame('pool_generate', $this->lastAiCallPurpose());
    }

    public function testAiQuestionGenerate(): void {
        $poolId = $this->ownPool();
        $text = str_repeat('VLANs trennen Broadcast-Domänen auf Layer 2. ', 3);
        self::assertConsentRequired($this->controller(AiController::class)->generate($poolId, $text, 3, 'de'));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(AiController::class)->generate($poolId, $text, 3, 'de');
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertArrayHasKey('taskId', $resp->getData());
        $this->assertSame(1, self::sent());
        $this->assertSame('question_generate', $this->lastAiCallPurpose());
    }

    public function testAiExplain(): void {
        $poolId = $this->ownPool();
        $question = $this->container->get(QuestionService::class)->create($poolId, $this->uid, 'Was trennt ein VLAN?', null, null, [
            ['text' => 'Broadcast-Domänen', 'is_correct' => true],
            ['text' => 'Kollisionsdomänen auf Layer 3', 'is_correct' => false],
        ]);
        $questionId = (int)$question['id'];

        self::assertConsentRequired($this->controller(AiController::class)->explain($questionId));
        $this->assertSame(0, self::sent());

        $this->grantConsent();
        $resp = $this->controller(AiController::class)->explain($questionId);
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertSame(1, self::sent());
        $this->assertSame('explain', $this->lastAiCallPurpose());
    }

    /** No subject, no consent: the admin's fixed test message, and only to what 5.7.0 offers. */
    public function testAdminConnectionTest(): void {
        $controller = $this->controller(AdminAiController::class);

        $resp = $controller->test('openai_compatible', MockLlmServer::baseUrl(), 'mock-model');
        $this->assertSame(400, $resp->getStatus());
        $this->assertSame(0, self::sent(), 'a 5.8.0 provider is never contacted');

        $resp = $controller->test('ollama', MockLlmServer::ollamaBaseUrl(), 'mock-model');
        $this->assertSame(200, $resp->getStatus());
        $this->assertTrue($resp->getData()['ok'], (string)json_encode($resp->getData()));
        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('connection test', $requests[0]['body'], 'only the fixed test message');
    }

    private function fillChatMemory(int $n): void {
        $mapper = $this->container->get(AiChatMemoryMapper::class);
        $t = time() - 10000;
        for ($i = 0; $i < $n; $i++) {
            $e = new AiChatMemory();
            $e->setUserId($this->uid);
            $e->setRole($i % 2 === 0 ? 'user' : 'assistant');
            $e->setMessage('Eintrag ' . $i);
            $e->setCreatedAt($t + $i);
            $mapper->insert($e);
        }
    }
}
