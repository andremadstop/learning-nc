<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CoopPlayer;
use OCA\Learning\Db\CoopPlayerMapper;
use OCA\Learning\Db\CoopSession;
use OCA\Learning\Db\CoopSessionMapper;
use OCA\Learning\Db\CoopVote;
use OCA\Learning\Db\CoopVoteMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CoopService;
use OCA\Learning\Service\StoryEngineService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CoopServiceTest extends TestCase {
    public function testCreateSessionCreatesLobbyWithSyntheticGraphUser(): void {
        $sessionMapper = $this->createMock(CoopSessionMapper::class);
        $playerMapper = $this->createMock(CoopPlayerMapper::class);
        $voteMapper = $this->createMock(CoopVoteMapper::class);
        $storyEngineService = $this->createMock(StoryEngineService::class);
        $userManager = $this->getMockBuilder(IUserManager::class)
            ->addMethods(['get'])
            ->getMock();
        $logger = $this->createMock(LoggerInterface::class);
        $db = new FakeDbConnection();

        $sessionMapper->method('findByCode')
            ->willThrowException(new DoesNotExistException('missing'));
        $sessionMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (CoopSession $session): CoopSession {
                $this->assertSame('der_grosse_ausfall', $session->getCampaignId());
                $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $session->getSessionCode());
                $this->assertSame('lobby', $session->getStatus());
                $this->assertSame('scene-start', $session->getCurrentNodeId());
                $session->setId(41);
                return $session;
            });

        $playerMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (CoopPlayer $player): CoopPlayer {
                $this->assertSame(41, $player->getSessionId());
                $this->assertSame('alice', $player->getUserId());
                $this->assertSame('Alice Admin', $player->getDisplayName());
                $this->assertSame('security', $player->getCharacterId());
                $this->assertTrue($player->getIsHost());
                return $player;
            });

        $storyEngineService->expects($this->once())
            ->method('startGraphCampaign')
            ->with(
                $this->callback(static fn(string $graphUserId): bool => str_starts_with($graphUserId, 'coop:')),
                'der_grosse_ausfall',
                'security'
            )
            ->willReturn($this->makeGraphState('scene-start'));

        $userManager->method('get')
            ->with('alice')
            ->willReturn(new class {
                public function getDisplayName(): string {
                    return 'Alice Admin';
                }
            });

        $service = $this->createService(
            db: $db,
            sessionMapper: $sessionMapper,
            playerMapper: $playerMapper,
            voteMapper: $voteMapper,
            storyEngineService: $storyEngineService,
            userManager: $userManager,
            logger: $logger
        );

        $payload = $service->createSession('der_grosse_ausfall', 'alice', 'security', 4);

        $this->assertSame(41, $payload['session']['id']);
        $this->assertSame('lobby', $payload['session']['status']);
        $this->assertSame('scene-start', $payload['session']['current_node_id']);
        $this->assertSame('Alice Admin', $payload['players'][0]['display_name']);
        $this->assertTrue($payload['players'][0]['is_host']);
        $this->assertSame(3000, $payload['session']['poll_interval_ms']);
    }

    public function testSetReadyStartsPlayingStateWhenAllPlayersAreReady(): void {
        $session = $this->makeSession(77, 'ABCD1234', 'lobby', 'scene-start', 'alice');
        $alice = $this->makePlayer(77, 'alice', 'Alice', 'security', true, false);
        $bob = $this->makePlayer(77, 'bob', 'Bob', 'helpdesk', false, true);

        $sessionMapper = $this->createMock(CoopSessionMapper::class);
        $playerMapper = $this->createMock(CoopPlayerMapper::class);
        $voteMapper = $this->createMock(CoopVoteMapper::class);
        $storyEngineService = $this->createMock(StoryEngineService::class);

        $sessionMapper->expects($this->once())
            ->method('findById')
            ->with(77)
            ->willReturn($session);
        $sessionMapper->expects($this->atLeastOnce())
            ->method('update')
            ->willReturnCallback(static fn(CoopSession $entity): CoopSession => $entity);

        $playerMapper->expects($this->once())
            ->method('findBySessionAndUser')
            ->with(77, 'alice')
            ->willReturn($alice);
        $playerMapper->expects($this->exactly(2))
            ->method('findBySession')
            ->with(77)
            ->willReturn([$alice, $bob]);
        $playerMapper->expects($this->once())
            ->method('update')
            ->with($alice)
            ->willReturn($alice);

        $storyEngineService->expects($this->once())
            ->method('getGraphScene')
            ->with('coop:ABCD1234', 'der_grosse_ausfall')
            ->willReturn($this->makeGraphState('scene-start'));

        $voteMapper->expects($this->once())
            ->method('findBySessionAndNode')
            ->with(77, 'scene-start')
            ->willReturn([]);

        $service = $this->createService(
            db: new FakeDbConnection(),
            sessionMapper: $sessionMapper,
            playerMapper: $playerMapper,
            voteMapper: $voteMapper,
            storyEngineService: $storyEngineService
        );

        $payload = $service->setReady(77, 'alice', true, '');

        $this->assertSame('playing', $payload['session']['status']);
        $this->assertSame('scene-start', $payload['scene']['id']);
        $this->assertTrue($payload['players'][0]['is_ready']);
    }

    public function testSubmitVoteAllowsProgressOnlyUpdatesForSimulatorState(): void {
        $session = $this->makeSession(55, 'SIMU1234', 'playing', 'scene-lab', 'alice');
        $alice = $this->makePlayer(55, 'alice', 'Alice', 'sysadmin', true, true);

        $sessionMapper = $this->createMock(CoopSessionMapper::class);
        $playerMapper = $this->createMock(CoopPlayerMapper::class);
        $voteMapper = $this->createMock(CoopVoteMapper::class);
        $storyEngineService = $this->createMock(StoryEngineService::class);

        $sessionMapper->expects($this->exactly(2))
            ->method('findById')
            ->with(55)
            ->willReturn($session);
        $sessionMapper->expects($this->atLeastOnce())
            ->method('update')
            ->willReturnCallback(static fn(CoopSession $entity): CoopSession => $entity);

        $playerMapper->expects($this->once())
            ->method('findBySessionAndUser')
            ->with(55, 'alice')
            ->willReturn($alice);
        $playerMapper->expects($this->exactly(2))
            ->method('findBySession')
            ->with(55)
            ->willReturn([$alice]);
        $playerMapper->expects($this->once())
            ->method('update')
            ->with($alice)
            ->willReturn($alice);

        $storyEngineService->expects($this->once())
            ->method('getGraphScene')
            ->with('coop:SIMU1234', 'der_grosse_ausfall')
            ->willReturn($this->makeGraphState('scene-lab', [
                'type' => 'simulator',
                'simulator' => ['type' => 'network', 'scenario' => 'core-switch'],
            ]));

        $voteMapper->expects($this->once())
            ->method('findBySessionAndNode')
            ->with(55, 'scene-lab')
            ->willReturn([]);

        $service = $this->createService(
            db: new FakeDbConnection(),
            sessionMapper: $sessionMapper,
            playerMapper: $playerMapper,
            voteMapper: $voteMapper,
            storyEngineService: $storyEngineService
        );

        $payload = $service->submitVote(55, 'alice', '', [
            'simulator_completed' => true,
            'simulator_passed' => true,
            'simulator_score' => 88,
        ]);

        $this->assertSame('scene-lab', $payload['scene']['id']);
        $this->assertCount(1, $payload['simulator_progress']);
        $this->assertSame('alice', $payload['simulator_progress'][0]['user_id']);
        $this->assertTrue($payload['simulator_progress'][0]['completed']);
        $this->assertTrue($payload['simulator_progress'][0]['passed']);
        $this->assertSame(88, $payload['simulator_progress'][0]['score']);
    }

    public function testSubmitVoteResolvesTieByPickingOneWinningEdgeAndAdvancing(): void {
        $session = $this->makeSession(91, 'TIE12345', 'playing', 'scene-1', 'alice');
        $alice = $this->makePlayer(91, 'alice', 'Alice', 'security', true, true);
        $bob = $this->makePlayer(91, 'bob', 'Bob', 'helpdesk', false, true);
        $votes = [
            $this->makeVote(91, 'alice', 'scene-1', 'edge-a'),
            $this->makeVote(91, 'bob', 'scene-1', 'edge-b'),
        ];

        $sessionMapper = $this->createMock(CoopSessionMapper::class);
        $playerMapper = $this->createMock(CoopPlayerMapper::class);
        $voteMapper = $this->createMock(CoopVoteMapper::class);
        $storyEngineService = $this->createMock(StoryEngineService::class);
        $finalStateBag = [];

        $sessionMapper->expects($this->exactly(2))
            ->method('findById')
            ->with(91)
            ->willReturn($session);
        $sessionMapper->expects($this->atLeastOnce())
            ->method('update')
            ->willReturnCallback(function (CoopSession $entity) use (&$finalStateBag): CoopSession {
                $finalStateBag = $entity->getStateBagDecoded();
                return $entity;
            });

        $playerMapper->expects($this->once())
            ->method('findBySessionAndUser')
            ->with(91, 'alice')
            ->willReturn($alice);
        $playerMapper->expects($this->exactly(3))
            ->method('findBySession')
            ->with(91)
            ->willReturn([$alice, $bob]);
        $playerMapper->expects($this->once())
            ->method('update')
            ->with($alice)
            ->willReturn($alice);

        $storyEngineService->expects($this->once())
            ->method('getGraphScene')
            ->with('coop:TIE12345', 'der_grosse_ausfall')
            ->willReturn($this->makeGraphState('scene-1', [], [
                ['id' => 'edge-a', 'label' => 'A', 'to_act' => 1],
                ['id' => 'edge-b', 'label' => 'B', 'to_act' => 1],
            ]));
        $storyEngineService->expects($this->once())
            ->method('traverseGraphEdge')
            ->with(
                'coop:TIE12345',
                'der_grosse_ausfall',
                $this->callback(static fn(string $edgeId): bool => in_array($edgeId, ['edge-a', 'edge-b'], true)),
                []
            )
            ->willReturn($this->makeGraphState('scene-2', ['is_ending' => true], []));

        $voteMapper->expects($this->once())
            ->method('findBySessionNodeAndUser')
            ->with(91, 'scene-1', 'alice')
            ->willThrowException(new DoesNotExistException('missing'));
        $voteMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(static fn(CoopVote $vote): bool => $vote->getEdgeId() === 'edge-a'))
            ->willReturnCallback(static fn(CoopVote $vote): CoopVote => $vote);
        $voteMapper->expects($this->exactly(2))
            ->method('findBySessionAndNode')
            ->willReturnOnConsecutiveCalls($votes, []);
        $voteMapper->expects($this->once())
            ->method('deleteBySessionAndNode')
            ->with(91, 'scene-1')
            ->willReturn(2);

        $db = new FakeDbConnection([new FakeQueryBuilder(new \OCA\Learning\Tests\Support\FakeResult(), 1)]);

        $service = $this->createService(
            db: $db,
            sessionMapper: $sessionMapper,
            playerMapper: $playerMapper,
            voteMapper: $voteMapper,
            storyEngineService: $storyEngineService
        );

        $payload = $service->submitVote(91, 'alice', 'edge-a', []);

        $this->assertSame('finished', $payload['session']['status']);
        $this->assertSame('scene-2', $payload['scene']['id']);
        $this->assertArrayHasKey('_coop', $finalStateBag);
        $this->assertTrue((bool)($finalStateBag['_coop']['last_resolution']['tie_break_used'] ?? false));
        $this->assertContains(
            $finalStateBag['_coop']['last_resolution']['winning_edge_id'] ?? null,
            ['edge-a', 'edge-b']
        );
    }

    private function createService(
        FakeDbConnection $db,
        ?CoopSessionMapper $sessionMapper = null,
        ?CoopPlayerMapper $playerMapper = null,
        ?CoopVoteMapper $voteMapper = null,
        ?StoryEngineService $storyEngineService = null,
        ?BadgeService $badgeService = null,
        ?IUserManager $userManager = null,
        ?LoggerInterface $logger = null
    ): CoopService {
        $sessionMapper ??= $this->createMock(CoopSessionMapper::class);
        $playerMapper ??= $this->createMock(CoopPlayerMapper::class);
        $voteMapper ??= $this->createMock(CoopVoteMapper::class);
        $storyEngineService ??= $this->createMock(StoryEngineService::class);
        $badgeService ??= $this->createMock(BadgeService::class);
        $userManager ??= $this->getMockBuilder(IUserManager::class)->addMethods(['get'])->getMock();
        $logger ??= $this->createMock(LoggerInterface::class);

        return new CoopService(
            $sessionMapper,
            $playerMapper,
            $voteMapper,
            $storyEngineService,
            $badgeService,
            $userManager,
            $db,
            $logger
        );
    }

    /**
     * @param array<string, mixed> $sceneOverrides
     * @param array<int, array<string, mixed>> $availableEdges
     * @return array<string, mixed>
     */
    private function makeGraphState(string $sceneId, array $sceneOverrides = [], array $availableEdges = []): array {
        $scene = array_merge([
            'id' => $sceneId,
            'title' => 'Scene ' . $sceneId,
            'narrative' => 'Narrative',
            'type' => 'dialog',
            'act' => 1,
            'is_ending' => false,
        ], $sceneOverrides);

        return [
            'progress' => [
                'campaign_id' => 'der_grosse_ausfall',
                'current_scene_id' => $sceneId,
                'state_bag' => [
                    'flags' => [],
                    'items' => [],
                    'reputation' => [],
                ],
                'act_number' => 1,
            ],
            'scene' => $scene,
            'state' => [
                'campaign_id' => 'der_grosse_ausfall',
                'graph_position' => $sceneId,
                'state_bag' => [
                    'flags' => [],
                    'items' => [],
                    'reputation' => [],
                ],
                'act_number' => 1,
            ],
            'available_edges' => $availableEdges,
            'timer' => null,
            'simulator' => $scene['simulator'] ?? null,
            'dau_bot' => null,
            'bot_correction' => null,
        ];
    }

    private function makeSession(int $id, string $code, string $status, string $nodeId, string $hostUserId): CoopSession {
        $session = new CoopSession();
        $session->setId($id);
        $session->setCampaignId('der_grosse_ausfall');
        $session->setHostUserId($hostUserId);
        $session->setSessionCode($code);
        $session->setStatus($status);
        $session->setCurrentNodeId($nodeId);
        $session->setStateBagFromArray([
            'flags' => [],
            'items' => [],
            'reputation' => [],
            '_coop' => [
                'vote' => [
                    'node_id' => $nodeId,
                    'winning_edge_id' => null,
                    'tie_break_used' => false,
                    'resolved' => false,
                    'resolved_at' => null,
                ],
                'last_resolution' => [],
                'simulator_progress' => [],
                'bot_progress' => [],
            ],
        ]);
        $session->setCreatedAt(time());
        $session->setLastHeartbeat(time());
        $session->setMaxPlayers(4);
        return $session;
    }

    private function makePlayer(int $sessionId, string $userId, string $displayName, string $characterId, bool $isHost, bool $isReady): CoopPlayer {
        $player = new CoopPlayer();
        $player->setSessionId($sessionId);
        $player->setUserId($userId);
        $player->setDisplayName($displayName);
        $player->setCharacterId($characterId);
        $player->setIsHost($isHost);
        $player->setIsReady($isReady);
        $player->setJoinedAt(time());
        $player->setLastHeartbeat(time());
        return $player;
    }

    private function makeVote(int $sessionId, string $userId, string $nodeId, string $edgeId): CoopVote {
        $vote = new CoopVote();
        $vote->setSessionId($sessionId);
        $vote->setUserId($userId);
        $vote->setNodeId($nodeId);
        $vote->setEdgeId($edgeId);
        $vote->setVotedAt(time());
        return $vote;
    }
}
