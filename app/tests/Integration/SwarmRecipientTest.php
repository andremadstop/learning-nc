<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\RagContextService;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * 5.7.0 Task B5 (review finding, HIGH): an approved swarm contribution of another learner must not
 * reach an external recipient on the asking learner's consent alone. Real chain: chunks in the
 * database, RagContextService, GeminiService::chat, LlmGateway, Ollama adapter, MockLlmServer —
 * the mock's request body is what was sent.
 */
final class SwarmRecipientTest extends TestCase {
    use ContainerObjects;

    private const ALICE = 'b5-swarm-alice';
    private const BOB = 'b5-swarm-bob';
    private const COURSE = 990501;
    private const BOB_MARKER = 'BOB-SWARM-MARKER-41c2';
    private const ALICE_MARKER = 'ALICE-OWN-MARKER-77d0';
    private const MATERIAL_MARKER = 'COURSE-MATERIAL-MARKER-0b9e';
    private const LEGACY_MARKER = 'LEGACY-ANSWER-MARKER-5e13';

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->clean();
        MockLlmServer::reset();

        $mapper = $this->container->get(RagChunkMapper::class);
        foreach ([
            ['Subnetting im Kursmaterial ' . self::MATERIAL_MARKER, 'web', null],
            ['Subnetting erklärt von Bob ' . self::BOB_MARKER, 'student', self::BOB],
            ['Subnetting, meine eigene Notiz ' . self::ALICE_MARKER, 'student', self::ALICE],
        ] as $i => [$text, $type, $uid]) {
            $c = new RagChunk();
            $c->setDocumentId(-1);
            $c->setCourseId(self::COURSE);
            $c->setChapter(null);
            $c->setText($text);
            $c->setSourceFile('quelle-' . $i . '.md');
            $c->setChunkIndex($i);
            $c->setTokenCount(10);
            $c->setCreatedAt(time());
            $c->setUserId($uid);
            $c->setSourceType($type);
            $c->setStatus('approved');
            $mapper->insert($c);
        }
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_ai_chat_memory')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::ALICE)))->executeStatement();
        $alice = Server::get(IUserManager::class)->get(self::ALICE);
        if ($alice !== null) {
            $alice->delete();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_rag_chunks')->where($qb->expr()->eq('course_id', $qb->createNamedParameter(self::COURSE)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_telos')->where($qb->expr()->in('user_id', $qb->createNamedParameter([self::ALICE, self::BOB], IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || str_starts_with($key, ConnectionStore::SAVED_PREFIX)) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    /** Ollama on the mock; processingLocal decides whether the recipient counts as local. */
    private function connectAndConsent(bool $processingLocal): void {
        $store = $this->container->get(ConnectionStore::class);
        $store->save(LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl(),
            'model' => 'mock-model', 'allowInternalNetwork' => true, 'processingLocal' => $processingLocal,
        ]), 'admin');
        $c = $store->load();
        $consent = $this->container->get(ConsentService::class);
        $consent->grant(self::ALICE, $c, $c->recipientFingerprint(), $consent->currentVersion(), 'dialog');
    }

    /** What VirtuProfController::chat does: begin, build the context with the session, send with it. */
    private function chatAsAlice(): string {
        $session = $this->container->get(LlmGateway::class)->begin(self::ALICE, LlmPurpose::Chat);
        $context = $this->container->get(RagContextService::class)
            ->buildContext(self::ALICE, null, self::COURSE, null, 'Was ist Subnetting?', false, $session);
        $result = $this->container->get(GeminiService::class)
            ->chat('Was ist Subnetting?', self::ALICE, $context, [], null, null, null, false, $session);
        $this->assertSame(MockLlmServer::REPLY, $result['answer'] ?? null, 'the chat went through');

        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/api/generate', $requests[0]['path']);
        return $requests[0]['body'];
    }

    public function testAnExternalRecipientNeverSeesAnotherLearnersContribution(): void {
        $this->connectAndConsent(false);

        $body = $this->chatAsAlice();

        $this->assertStringNotContainsString(self::BOB_MARKER, $body);
        $this->assertStringContainsString(self::MATERIAL_MARKER, $body, 'course material still goes');
        $this->assertStringContainsString(self::ALICE_MARKER, $body, 'her own contribution still goes');
        $this->assertStringNotContainsString(self::BOB, $body, 'no author metadata');
    }

    public function testALocalRecipientGetsTheSwarm(): void {
        $this->connectAndConsent(true);

        $body = $this->chatAsAlice();

        $this->assertStringContainsString(self::BOB_MARKER, $body);
        $this->assertStringNotContainsString(self::BOB, $body, 'no author metadata');
    }

    /** Alice's chat through the real VirtuProfController (course access granted). */
    private function chatThroughTheController(): string {
        if (Server::get(IUserManager::class)->get(self::ALICE) === null) {
            $user = Server::get(IUserManager::class)->createUser(self::ALICE, bin2hex(random_bytes(16)) . 'Aa1!');
            $this->assertInstanceOf(IUser::class, $user);
        }
        $before = count(MockLlmServer::requests());
        $resp = $this->controllerFor($this->container, VirtuProfController::class, self::ALICE,
            [CourseService::class => $this->createMock(CourseService::class)])->chat('Was ist Subnetting?', null, self::COURSE);
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $requests = MockLlmServer::requests();
        $this->assertCount($before + 1, $requests, 'one chat request');
        return $requests[$before]['body'];
    }

    /**
     * Review finding (HIGH): with a local recipient VirtuProf answers from Bob's contribution, and
     * the answer is stored in Alice's chat memory. The admin then switches to an external
     * recipient and Alice consents to it. Her next chat must not carry Bob's contribution to the
     * external recipient through chat memory.
     */
    public function testAnAnswerFromAnotherLearnersContributionDoesNotReachAnExternalRecipientThroughChatMemory(): void {
        $this->connectAndConsent(true);
        MockLlmServer::setReply('Ein Mitlernender schreibt: ' . self::BOB_MARKER);
        $this->assertStringContainsString(self::BOB_MARKER, $this->chatThroughTheController(), 'local: the swarm goes');
        $stored = array_map(static fn (AiChatMemory $e): string => $e->getMessage(),
            $this->container->get(AiChatMemoryMapper::class)->findRecentByUser(self::ALICE, 10));
        $this->assertStringContainsString(self::BOB_MARKER, implode("\n", $stored), 'the answer quoting Bob is in her memory');

        $this->connectAndConsent(false);
        MockLlmServer::reset();

        $body = $this->chatThroughTheController();

        $this->assertStringNotContainsString(self::BOB_MARKER, $body);
        $this->assertStringContainsString('Was ist Subnetting?', $body, 'her own earlier message may go');
    }

    /**
     * Review finding (HIGH), compression: once the 50-entry cap is passed, the oldest entries are
     * summarised. A swarm-sourced answer and an answer of unknown origin (stored before 5.7.0)
     * are not part of what is sent for the summary.
     */
    public function testMemoryCompressionNeverSendsASwarmSourcedOrUnknownAnswer(): void {
        $this->connectAndConsent(false);
        $mapper = $this->container->get(AiChatMemoryMapper::class);
        $t = time() - 10000;
        for ($i = 0; $i < 49; $i++) {
            $e = new AiChatMemory();
            $e->setUserId(self::ALICE);
            $e->setRole($i % 2 === 0 ? 'user' : 'assistant');
            $e->setMessage(match ($i) {
                1 => 'Antwort mit ' . self::BOB_MARKER,
                3 => 'Antwort von früher ' . self::LEGACY_MARKER,
                default => 'Eintrag ' . $i,
            });
            $e->setSwarmSourced(match (true) {
                $i === 1 => 1,
                $i === 3 => null,
                default => 0,
            });
            $e->setCreatedAt($t + $i);
            $mapper->insert($e);
        }

        $this->container->get(AiChatMemoryService::class)->saveExchange(self::ALICE, 'Frage 50', 'Antwort 50', false);

        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests, 'the compression request');
        $this->assertStringContainsString('Eintrag 0', $requests[0]['body'], 'own messages are summarised');
        $this->assertStringNotContainsString(self::BOB_MARKER, $requests[0]['body']);
        $this->assertStringNotContainsString(self::LEGACY_MARKER, $requests[0]['body']);
    }
}
