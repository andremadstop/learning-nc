<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CampaignState;
use OCA\Learning\Db\CampaignStateMapper;
use OCA\Learning\Db\StoryProgressMapper;
use OCA\Learning\Service\CampaignGraphService;
use OCA\Learning\Service\DauBotService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\StoryEngineService;
use OCA\Learning\Service\TelosService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit 2026-11, package p03-campaign: the campaign loader, the progress list, the SQL random
 * function and the per-request pool cache of the story engine.
 */
#[AllowMockObjectsWithoutExpectations]
final class StoryCampaignAuditTest extends TestCase {
    private ?string $tmpDir = null;

    protected function tearDown(): void {
        if ($this->tmpDir !== null) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }
    }

    private function makeService(
        ?IDBConnection $db = null,
        ?CampaignGraphService $graph = null,
        ?LoggerInterface $logger = null,
        ?StoryProgressMapper $progressMapper = null
    ): StoryEngineService {
        return new StoryEngineService(
            $progressMapper ?? $this->createMock(StoryProgressMapper::class),
            $db ?? $this->createMock(IDBConnection::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $this->createMock(GeminiService::class),
            $this->createMock(IConfig::class),
            $this->createMock(ICacheFactory::class),
            $graph ?? $this->createMock(CampaignGraphService::class),
            $this->createMock(TelosService::class),
            $this->createMock(QuestionService::class)
        );
    }

    private function callPrivate(object $object, string $method, array $args = []): mixed {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    /**
     * A connection that counts query builders and answers every query with no rows; the
     * builder accepts any call chain.
     */
    private function countingDb(string $provider = IDBConnection::PLATFORM_POSTGRES): IDBConnection {
        return new class($provider) implements IDBConnection {
            public int $builders = 0;

            public function __construct(private string $provider) {
            }

            public function getQueryBuilder() {
                $this->builders++;
                return new class {
                    public function __call(string $name, array $args): mixed {
                        if ($name === 'executeQuery') {
                            return new class {
                                public function fetchOne(): mixed {
                                    return false;
                                }

                                public function closeCursor(): bool {
                                    return true;
                                }
                            };
                        }
                        return $this;
                    }
                };
            }

            public function getDatabaseProvider(): string {
                return $this->provider;
            }

            public function executeQuery(string $sql, array $params = []) {
                return null;
            }

            public function escapeLikeParameter(string $input): string {
                return $input;
            }

            public function beginTransaction(): void {
            }

            public function commit(): void {
            }

            public function rollBack(): void {
            }

            public function inTransaction(): bool {
                return false;
            }
        };
    }

    // ─── #4 / #32: campaign_id must name the file the loader opens ───────────────

    public function testShippedCampaignIdsAreUniqueAndEqualTheFileName(): void {
        $ids = [];
        foreach ($this->makeService()->listCampaigns() as $campaign) {
            $ids[] = $campaign['campaign_id'];
        }
        $this->assertNotEmpty($ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'duplicate campaign_id in data/campaigns');
        foreach (glob(dirname(__DIR__, 3) . '/data/campaigns/*.json') ?: [] as $file) {
            if (basename($file) === 'campaign-schema.json') {
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            $this->assertSame(basename($file, '.json'), $data['campaign_id'] ?? null, basename($file));
        }
        // listCampaigns() drops a file that fails validation without a word: the graph Phishing
        // Friday did, over the simulator type 'portscanner', so the featured campaign never showed.
        // (zero_day_rechenzentrum fails the same way, over the condition 'requires_not_flag' the
        // engine does not know; reported separately, not part of this fix.)
        $this->assertContains('phishing_friday', $ids);
        // The featured graph campaign starts as a graph campaign
        $this->assertTrue((new CampaignGraphService(
            $this->createMock(CampaignStateMapper::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DauBotService::class)
        ))->isGraphCampaign($this->makeService()->loadCampaign('phishing_friday')));
    }

    public function testInternalGraphTestCampaignIsNotShipped(): void {
        $ids = array_column($this->makeService()->listCampaigns(), 'campaign_id');
        $this->assertNotContains('test_graph_campaign', $ids);
    }

    public function testListCampaignsSkipsAFileWhoseIdDiffersFromItsName(): void {
        $source = dirname(__DIR__, 3) . '/data/campaigns';
        $this->tmpDir = sys_get_temp_dir() . '/learning-campaigns-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        copy($source . '/campaign-schema.json', $this->tmpDir . '/campaign-schema.json');
        copy($source . '/ghostline_quest.json', $this->tmpDir . '/ghostline_quest.json');
        // Same content under another name: it would be listed, but every start would open
        // ghostline_quest.json
        copy($source . '/ghostline_quest.json', $this->tmpDir . '/ghostline_quest_copy.json');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('does not match the file name'));
        $service = $this->makeService(null, null, $logger);
        $dir = new \ReflectionProperty($service, 'campaignDir');
        $dir->setAccessible(true);
        $dir->setValue($service, $this->tmpDir);

        $this->assertSame(['ghostline_quest'], array_column($service->listCampaigns(), 'campaign_id'));
    }

    // ─── #82: Oracle knows neither RANDOM() nor RAND() ───────────────────────────

    public function testRandomFunctionPerDatabase(): void {
        $this->assertSame('RAND()', $this->callPrivate($this->makeService($this->countingDb(IDBConnection::PLATFORM_MYSQL)), 'randomOrderFunction'));
        $this->assertSame('DBMS_RANDOM.VALUE', $this->callPrivate($this->makeService($this->countingDb(IDBConnection::PLATFORM_ORACLE)), 'randomOrderFunction'));
        $this->assertSame('RANDOM()', $this->callPrivate($this->makeService($this->countingDb(IDBConnection::PLATFORM_POSTGRES)), 'randomOrderFunction'));
        $this->assertSame('RANDOM()', $this->callPrivate($this->makeService($this->countingDb(IDBConnection::PLATFORM_SQLITE)), 'randomOrderFunction'));
    }

    // ─── #209: the readable pools are looked up once per user and request ────────

    public function testASceneLooksUpTheReadablePoolsOnceForAllSkillChoices(): void {
        $db = $this->countingDb();
        $service = $this->makeService($db);
        $scene = [
            'id' => 's1',
            'title' => 'S',
            'narrative' => 'n',
            'choices' => [
                ['id' => 'c1', 'text' => 'A', 'skill_check' => ['pool_filter' => 'security', 'question_count' => 2]],
                ['id' => 'c2', 'text' => 'B', 'skill_check' => ['pool_filter' => 'network', 'question_count' => 2]],
                ['id' => 'c3', 'text' => 'C', 'skill_check' => ['pool_filter' => 'linux', 'question_count' => 2]],
            ],
        ];
        $campaign = ['campaign_id' => 'x', 'title' => 'X', 'scenes' => [$scene]];

        // Without pools the draw stops after the lookup, so every builder is a lookup query
        $this->callPrivate($service, 'readablePoolIds', ['alice']);
        $perLookup = $db->builders;
        $this->assertGreaterThan(0, $perLookup);

        $db->builders = 0;
        $this->callPrivate($service, 'buildSceneResponse', [$scene, $campaign, 'security', 'alice']);
        $this->assertSame($perLookup, $db->builders, 'three skill choices looked the pools up three times');

        // The remembered list lives only while one scene is built: the next one asks again
        $db->builders = 0;
        $this->callPrivate($service, 'buildSceneResponse', [$scene, $campaign, 'security', 'alice']);
        $this->assertSame($perLookup, $db->builders);
        $db->builders = 0;
        $this->callPrivate($service, 'readablePoolIds', ['alice']);
        $this->assertSame($perLookup, $db->builders, 'outside a scene build nothing is cached');
    }

    // ─── #88: the progress list carries the graph campaigns ──────────────────────

    public function testListUserProgressIncludesGraphStates(): void {
        $graph = $this->createMock(CampaignGraphService::class);
        $graph->method('listUserStates')->with('alice')->willReturn([
            ['campaign_id' => 'ghostline_quest', 'status' => 'completed', 'act_number' => 3, 'updated_at' => 100],
        ]);
        $progress = $this->createMock(StoryProgressMapper::class);
        $progress->method('findAllByUser')->willReturn([]);

        $rows = $this->makeService(null, $graph, null, $progress)->listUserProgress('alice');

        $this->assertCount(1, $rows);
        $this->assertSame('ghostline_quest', $rows[0]['campaign_id']);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertTrue($rows[0]['is_graph']);
        $this->assertSame('Ghostlines Schatten', $rows[0]['campaign_title']);
    }

    public function testReachingAnEndingNodeCompletesTheGraphState(): void {
        $state = new CampaignState();
        $state->setUserId('alice');
        $state->setCampaignId('g');
        $state->setGraphPosition('n1');
        $state->setStateBagFromArray(['flags' => [], 'items' => [], 'reputation' => []]);
        $state->setActNumber(1);
        $state->setStatus('in_progress');
        $state->setCharacterClass('security');
        $state->setScore(0);

        $mapper = $this->createMock(CampaignStateMapper::class);
        $mapper->method('findByUserAndCampaign')->willReturn($state);
        $mapper->method('findAllByUser')->willReturn([$state]);
        $updated = [];
        $mapper->method('update')->willReturnCallback(function (CampaignState $s) use (&$updated) {
            $updated[] = $s->getStatus();
            return $s;
        });

        $service = new CampaignGraphService(
            $mapper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(DauBotService::class)
        );
        $graph = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'dialog', 'act' => 1, 'start' => true],
                ['id' => 'n2', 'type' => 'dialog', 'act' => 1],
                ['id' => 'end', 'type' => 'ending', 'act' => 1, 'is_ending' => true],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n2'],
                ['id' => 'e2', 'from' => 'n2', 'to' => 'end'],
            ],
        ];

        $service->traverseEdge('alice', 'g', 'e1', $graph, []);
        $this->assertSame('in_progress', $state->getStatus(), 'a middle node does not finish the campaign');
        $service->traverseEdge('alice', 'g', 'e2', $graph, []);
        $this->assertSame('completed', $state->getStatus());
        $this->assertSame(['in_progress', 'completed'], $updated);

        $this->assertSame(
            [['campaign_id' => 'g', 'status' => 'completed', 'act_number' => 1, 'updated_at' => (int)$state->getUpdatedAt()]],
            $service->listUserStates('alice')
        );
    }
}
