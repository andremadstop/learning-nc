<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Plan Task B6, Review Focus 1 and 3, against real persistence on both engines (the unit tests in
 * LlmGatewayTest mock ConnectionStore and ConsentService): the real epoch UPDATE of a revocation,
 * the real app config (including its per-process cache), the real gateway, budget and Ollama
 * adapter, and MockLlmServer counting what actually left.
 */
final class ConsentRaceTest extends TestCase {
    private const ALICE = 'b6-race-alice';
    private const BOB = 'b6-race-bob';

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;
    private ConnectionStore $store;
    private ConsentService $consent;
    private LlmGateway $gateway;

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
        $this->clean();
    }

    private function clean(): void {
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
