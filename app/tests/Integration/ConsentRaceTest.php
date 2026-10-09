<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Controller\TelosController;
use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\PoolService;
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
use Psr\Log\LoggerInterface;

/**
 * Plan Task B6, Review Focus 1 and 3, against real persistence on both engines (the unit tests in
 * LlmGatewayTest mock ConnectionStore and ConsentService): the real epoch UPDATE of a revocation,
 * the real app config (including its per-process cache), the real gateway, budget and Ollama
 * adapter, and MockLlmServer counting what actually left.
 */
final class ConsentRaceTest extends TestCase {
    use ContainerObjects;

    private const ALICE = 'b6-race-alice';
    private const BOB = 'b6-race-bob';

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;
    private ConnectionStore $store;
    private ConsentService $consent;
    private LlmGateway $gateway;
    /** @var list<IUser> real users of the tests that go through the controller or the job */
    private array $users = [];

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->store = $this->container->get(ConnectionStore::class);
        $this->consent = $this->container->get(ConsentService::class);
        $this->gateway = $this->container->get(LlmGateway::class);
        $this->clean();
        MockLlmServer::reset();
    }

    protected function tearDown(): void {
        foreach ($this->users as $user) {
            $uid = $user->getUID();
            $poolIds = $this->ids('learning_pools', 'user_id', $uid);
            if ($poolIds !== []) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('learning_sessions')
                    ->where($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeStatement();
                $qb = $this->db->getQueryBuilder();
                $qb->delete('learning_pools')
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }
            foreach (['learning_ai_chat_memory', 'learning_user_telos'] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
            }
            $user->delete();
        }
        $this->users = [];
        $this->clean();
    }

    /** @return list<int> */
    private function ids(string $table, string $column, string $value): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
        $r = $qb->executeQuery();
        $ids = array_map('intval', array_column($r->fetchAll(), 'id'));
        $r->closeCursor();
        return $ids;
    }

    private function realUser(string $uid): string {
        $user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
        $this->assertInstanceOf(IUser::class, $user, 'test user created');
        $this->users[] = $user;
        return $uid;
    }

    /**
     * A learner the weekly job picks up: a pool without answers (error rate 50 %, weak enough)
     * and a session completed today.
     */
    private function activeLearner(string $uid, string $poolName): void {
        $this->realUser($uid);
        $poolId = $this->container->get(PoolService::class)->create($poolName, 'B6 race pool', $uid)->getId();
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter($uid),
            'started_at' => $qb->createNamedParameter($now - 600, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($now - 60, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    private function clean(): void {
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConsentService::RECIPIENTS_KEY) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_telos')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter([self::ALICE, self::BOB], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || str_starts_with($key, ConnectionStore::SAVED_PREFIX)) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    /** Ollama on the mock, as the admin saves it. */
    private function connectMock(): LlmConnection {
        $this->store->save(LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl(),
            'model' => 'snapshot-model', 'allowInternalNetwork' => true, 'processingLocal' => false,
        ]), 'admin');
        return $this->store->load();
    }

    private function grant(string $uid, LlmConnection $c): void {
        $this->consent->grant($uid, $c, $c->recipientFingerprint(), $this->consent->currentVersion(), 'dialog');
    }

    private static function request(string $uid): LlmRequest {
        return new LlmRequest('Weekly note system prompt', 'Weak topic of ' . $uid, 0.7, 512);
    }

    private static function sentCount(): int {
        return count(MockLlmServer::requests());
    }

    /**
     * Review Focus 1: the admin switches the backend while a chat is running. The running request
     * uses its snapshot (and nothing else), the next one needs a new consent; never mixed.
     */
    public function testAnAdminSwitchDuringAChatKeepsTheSnapshotAndTheNextRequestNeedsNewConsent(): void {
        $c = $this->connectMock();
        $this->grant(self::ALICE, $c);
        $session = $this->gateway->begin(self::ALICE, LlmPurpose::Chat);

        // the admin switches to Gemini while the context is being built
        $this->store->save(ConnectionStore::legacyGemini(true), 'admin');

        $result = $this->gateway->send($session, self::request(self::ALICE));

        $this->assertSame(MockLlmServer::REPLY, $result->text);
        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests, 'exactly one request, to the snapshot recipient');
        $this->assertSame('/api/generate', $requests[0]['path']);
        $this->assertSame('snapshot-model', json_decode($requests[0]['body'], true)['model'] ?? null);

        $this->assertSame('recipient_changed', $this->consent->state(self::ALICE, $this->store->load())['reason']);
        try {
            $this->gateway->begin(self::ALICE, LlmPurpose::Chat);
            $this->fail('the next request must ask for consent to the new recipient');
        } catch (LlmConsentException) {
        }
        $this->assertSame(1, self::sentCount());
    }

    /**
     * Review Focus 3, plan B6 shape: the weekly job walks two users; between user 1 and user 2,
     * user 2 revokes. Only user 1's note is sent.
     */
    public function testARevocationBetweenTwoUsersOfTheWeeklyJobStopsTheSecondNote(): void {
        $c = $this->connectMock();
        $this->grant(self::ALICE, $c);
        $this->grant(self::BOB, $c);

        $outcomes = $this->runWeeklyLoop([self::ALICE, self::BOB], afterUser: function (string $uid): void {
            if ($uid === self::ALICE) {
                $this->consent->revoke(self::BOB);
            }
        });

        $this->assertSame([self::ALICE => 'sent', self::BOB => 'skipped_consent'], $outcomes);
        $this->assertSame(1, self::sentCount());
        $this->assertStringContainsString(self::ALICE, MockLlmServer::requests()[0]['body']);
    }

    /**
     * Review Focus 3, the narrow window: the job already began user 2's session and is building
     * the note when user 2 revokes. The real epoch UPDATE must stop the send.
     */
    public function testARevocationAfterBeginStopsTheNoteThatIsBeingBuilt(): void {
        $c = $this->connectMock();
        $this->grant(self::ALICE, $c);
        $this->grant(self::BOB, $c);

        $outcomes = $this->runWeeklyLoop([self::ALICE, self::BOB], beforeSend: function (string $uid): void {
            if ($uid === self::BOB) {
                $this->consent->revoke(self::BOB);
            }
        });

        $this->assertSame([self::ALICE => 'sent', self::BOB => 'skipped_consent'], $outcomes);
        $this->assertSame(1, self::sentCount());
    }

    /**
     * Revoked and granted again while the note is built: the consent looks valid again, but it is
     * not the one the session was begun under. Only the epoch tells them apart.
     */
    public function testARevokeAndRegrantAfterBeginStillStopsThatSession(): void {
        $c = $this->connectMock();
        $this->grant(self::BOB, $c);

        $outcomes = $this->runWeeklyLoop([self::BOB], beforeSend: function (string $uid) use ($c): void {
            $this->consent->revoke($uid);
            $this->grant($uid, $c);
            $this->assertTrue($this->consent->isValidFor($uid, $c), 'the new consent is valid');
        });

        $this->assertSame([self::BOB => 'skipped_consent'], $outcomes);
        $this->assertSame(0, self::sentCount());
    }

    /**
     * Switching AI off is seen by a running job even when the write comes from another process:
     * this process's app config cache still holds the enabled snapshot, so send() must re-read.
     */
    public function testASwitchOffByAnotherProcessStopsTheNextSend(): void {
        $c = $this->connectMock();
        $this->grant(self::ALICE, $c);
        $session = $this->gateway->begin(self::ALICE, LlmPurpose::WeeklyNote);
        $this->assertTrue($this->store->load()->enabled, 'the enabled snapshot is in this process\'s cache');

        $off = json_encode(array_merge($c->toArray(), ['enabled' => false, 'rev' => $c->rev + 1]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update('appconfig')
            ->set('configvalue', $qb->createNamedParameter($off))
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('learning')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(ConnectionStore::KEY)))
            ->executeStatement();
        $this->assertSame(1, $changed, 'the other process switched AI off');

        try {
            $this->gateway->send($session, self::request(self::ALICE));
            $this->fail('a switched-off connection must not send');
        } catch (LlmProviderException $e) {
            $this->assertNotInstanceOf(LlmConsentException::class, $e);
            $this->assertSame('disabled', $e->category);
        }
        $this->assertSame(0, self::sentCount());
    }

    /**
     * Review finding (HIGH): a long-running process (the weekly job) has recipient A in its app
     * config cache. In another process the admin replaces A by B. A session begun after that must
     * be checked against B: the learner consented to A only, so nothing is sent — neither to A
     * (removed by the admin) nor to B (no consent).
     */
    public function testASessionBegunAfterARecipientSwitchByAnotherProcessNeedsConsentForTheNewRecipient(): void {
        $a = $this->connectMock();
        $this->grant(self::ALICE, $a);
        $this->assertSame($a->recipientFingerprint(), $this->store->load()->recipientFingerprint(), 'A is in this process\'s cache');
        $this->assertTrue($this->store->isEnabledNow(), 'the kill-switch check of the job has just refreshed the cache with A');

        $b = LlmConnection::fromArray(array_merge($a->toArray(), [
            'baseUrl' => MockLlmServer::ollamaBaseUrl() . '/switched', 'model' => 'switched-model', 'rev' => $a->rev + 1,
        ]));
        $this->assertNotSame($a->recipientFingerprint(), $b->recipientFingerprint(), 'another recipient');
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update('appconfig')
            ->set('configvalue', $qb->createNamedParameter(json_encode($b->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter('learning')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(ConnectionStore::KEY)))
            ->executeStatement();
        $this->assertSame(1, $changed, 'the other process switched the recipient');

        try {
            $session = $this->gateway->begin(self::ALICE, LlmPurpose::WeeklyNote);
            $this->gateway->send($session, self::request(self::ALICE));
            $this->fail('a session begun after the switch must need consent for the new recipient');
        } catch (LlmConsentException) {
        }
        $this->assertSame(0, self::sentCount(), 'nothing went to the removed recipient');
    }

    /**
     * Review Focus 1 through the real chat endpoint: VirtuProfController::chat() begins the
     * session, builds the context (chat memory is loaded here), then sends. The admin switches the
     * recipient while the context is built. The running answer goes once to the snapshot; the next
     * chat asks for consent again, sends nothing, and the status names the old recipient.
     */
    public function testAnAdminSwitchDuringARealChatKeepsTheSnapshotAndTheNextChatNeedsNewConsent(): void {
        $uid = $this->realUser('b6-race-chat-' . bin2hex(random_bytes(4)));
        $c = $this->connectMock();
        $this->grant($uid, $c);
        $switched = LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl() . '/switched',
            'model' => 'switched-model', 'allowInternalNetwork' => true, 'processingLocal' => false,
        ]);
        $this->assertNotSame($c->recipientFingerprint(), $switched->recipientFingerprint(), 'another recipient');

        $memory = new class(...self::constructorArgs($this->container, AiChatMemoryService::class, $uid)) extends AiChatMemoryService {
            /** @var (\Closure(): void)|null */
            public ?\Closure $whileBuilding = null;

            public function loadMemory(string $userId): array {
                $hook = $this->whileBuilding;
                $this->whileBuilding = null;
                if ($hook !== null) {
                    $hook();
                }
                return parent::loadMemory($userId);
            }
        };
        $memory->whileBuilding = fn () => $this->store->save($switched, 'admin');
        $chat = fn (): \OCP\AppFramework\Http\DataResponse => $this->controllerFor($this->container, VirtuProfController::class, $uid,
            [AiChatMemoryService::class => $memory])->chat('Was ist Subnetting?');

        $resp = $chat();
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests, 'exactly one request, to the snapshot recipient');
        $this->assertSame('/api/generate', $requests[0]['path'], 'not the switched endpoint');
        $this->assertSame('snapshot-model', json_decode($requests[0]['body'], true)['model'] ?? null);
        $this->assertSame('switched-model', $this->store->load()->model, 'the switch was saved during the chat');

        $resp = $chat();
        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
        $this->assertSame(1, self::sentCount(), 'the next chat sent nothing');

        $status = $this->controllerFor($this->container, TelosController::class, $uid)->getConsentStatus()->getData();
        $this->assertSame('recipient_changed', $status['reason']);
        $this->assertSame('Ollama (127.0.0.1)', $status['previous_recipient_name']);
        $this->assertSame($switched->recipientFingerprint(), $status['recipient']['fingerprint']);
    }

    /**
     * Review Focus 3 through the real WeeklyLernplanJob::run(): two active learners with consent;
     * right after the first learner's note, the second revokes. One request, for the first only;
     * the second counts as skipped_consent, not as an error.
     */
    public function testARevocationDuringARealWeeklyJobRunStopsTheSecondNote(): void {
        $suffix = bin2hex(random_bytes(4));
        $first = 'b6-race-job-a-' . $suffix;    // the job walks users in user_id order
        $second = 'b6-race-job-b-' . $suffix;
        $this->activeLearner($first, 'Pool-First-' . $suffix);
        $this->activeLearner($second, 'Pool-Second-' . $suffix);
        $c = $this->connectMock();
        $this->grant($first, $c);
        $this->grant($second, $c);

        $notes = new class(...self::constructorArgs($this->container, NoteGeneratorService::class, null)) extends NoteGeneratorService {
            /** @var (\Closure(string): void)|null */
            public ?\Closure $afterUser = null;

            public function generateSummary(string $userId, int $poolId, ?int $courseId = null,
                \OCA\Learning\Service\Llm\LlmPurpose $purpose = \OCA\Learning\Service\Llm\LlmPurpose::Note,
                ?LlmSession $session = null): array {
                try {
                    return parent::generateSummary($userId, $poolId, $courseId, $purpose, $session);
                } finally {
                    if ($this->afterUser !== null) {
                        ($this->afterUser)($userId);
                    }
                }
            }
        };
        $notes->afterUser = function (string $uid) use ($first, $second): void {
            if ($uid === $first) {
                $this->consent->revoke($second);
            }
        };
        $counts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string|\Stringable $message, array $context = []) use (&$counts): void {
            if (str_starts_with((string)$message, 'WeeklyLernplanJob:')) {
                $counts = $context;
            }
        });
        $job = new WeeklyLernplanJob(...self::constructorArgs($this->container, WeeklyLernplanJob::class, null,
            [NoteGeneratorService::class => $notes, LoggerInterface::class => $logger]));

        (new \ReflectionMethod(WeeklyLernplanJob::class, 'run'))->invoke($job, null);

        $requests = MockLlmServer::requests();
        $this->assertCount(1, $requests, 'one note, for the first learner only');
        $this->assertStringContainsString('Pool-First-' . $suffix, $requests[0]['body']);
        $this->assertStringNotContainsString('Pool-Second-' . $suffix, $requests[0]['body']);
        $this->assertSame(1, $counts['processed'] ?? null, (string)json_encode($counts));
        $this->assertSame(0, $counts['errors'] ?? null, 'a revocation is no error');
        $this->assertGreaterThanOrEqual(1, $counts['skippedConsent'] ?? 0, 'the second learner is a consent skip');
    }

    /**
     * What WeeklyLernplanJob does per user, reduced to the consent-relevant part: begin with the
     * weekly_note purpose, build the note, send; a consent failure is skipped_consent.
     *
     * @param list<string> $users
     * @param (\Closure(string): void)|null $beforeSend runs after begin(), while the note is built
     * @param (\Closure(string): void)|null $afterUser runs after a user is done
     * @return array<string, string>
     */
    private function runWeeklyLoop(array $users, ?\Closure $beforeSend = null, ?\Closure $afterUser = null): array {
        $outcomes = [];
        foreach ($users as $uid) {
            try {
                $session = $this->gateway->begin($uid, LlmPurpose::WeeklyNote);
                if ($beforeSend !== null) {
                    $beforeSend($uid);
                }
                $this->gateway->send($session, self::request($uid));
                $outcomes[$uid] = 'sent';
            } catch (LlmConsentException) {
                $outcomes[$uid] = 'skipped_consent';
            }
            if ($afterUser !== null) {
                $afterUser($uid);
            }
        }
        return $outcomes;
    }
}
