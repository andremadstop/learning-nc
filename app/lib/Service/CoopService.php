<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CoopPlayer;
use OCA\Learning\Db\CoopPlayerMapper;
use OCA\Learning\Db\CoopSession;
use OCA\Learning\Db\CoopSessionMapper;
use OCA\Learning\Db\CoopVote;
use OCA\Learning\Db\CoopVoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class CoopService {
    private const MAX_PLAYERS = 4;
    private const MIN_PLAYERS = 2;
    private const POLL_INTERVAL_MS = 3000;
    private const PLAYER_TIMEOUT_SECONDS = 15;
    private const SESSION_STALE_SECONDS = 300;
    private const VALID_CHARACTER_IDS = ['architect', 'security', 'sysadmin', 'helpdesk'];

    private CoopSessionMapper $sessionMapper;
    private CoopPlayerMapper $playerMapper;
    private CoopVoteMapper $voteMapper;
    private StoryEngineService $storyEngineService;
    private IUserManager $userManager;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        CoopSessionMapper $sessionMapper,
        CoopPlayerMapper $playerMapper,
        CoopVoteMapper $voteMapper,
        StoryEngineService $storyEngineService,
        IUserManager $userManager,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->sessionMapper = $sessionMapper;
        $this->playerMapper = $playerMapper;
        $this->voteMapper = $voteMapper;
        $this->storyEngineService = $storyEngineService;
        $this->userManager = $userManager;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $campaignId, string $hostUserId, string $characterId = 'helpdesk', int $maxPlayers = self::MAX_PLAYERS): array {
        if ($hostUserId === '') {
            throw new \RuntimeException('Not authenticated');
        }

        $normalizedCharacterId = $this->normalizeCharacterId($characterId);
        $normalizedMaxPlayers = max(self::MIN_PLAYERS, min(self::MAX_PLAYERS, $maxPlayers));
        $sessionCode = $this->generateSessionCode();
        $graphUserId = $this->buildGraphUserId($sessionCode);
        $graphState = $this->storyEngineService->startGraphCampaign($graphUserId, $campaignId, $normalizedCharacterId);
        $now = time();

        $session = new CoopSession();
        $session->setCampaignId($campaignId);
        $session->setHostUserId($hostUserId);
        $session->setSessionCode($sessionCode);
        $session->setStatus('lobby');
        $session->setCurrentNodeId((string)($graphState['scene']['id'] ?? ''));
        $session->setStateBagFromArray($this->mergeCoopState($graphState['state']['state_bag'] ?? [], []));
        $session->setCreatedAt($now);
        $session->setLastHeartbeat($now);
        $session->setMaxPlayers($normalizedMaxPlayers);
        /** @var CoopSession $session */
        $session = $this->sessionMapper->insert($session);

        $hostPlayer = $this->buildPlayer(
            $session->getId(),
            $hostUserId,
            $this->resolveDisplayName($hostUserId, null),
            $normalizedCharacterId,
            true,
            $now
        );
        $hostPlayer = $this->playerMapper->insert($hostPlayer);

        return $this->buildLobbyPayload($session, [$hostPlayer]);
    }

    /**
     * @return array<string, mixed>
     */
    public function joinSession(string $sessionCode, string $userId, ?string $characterId = null, ?string $displayName = null): array {
        if ($userId === '') {
            throw new \RuntimeException('Not authenticated');
        }

        $normalizedCode = $this->normalizeSessionCode($sessionCode);
        $session = $this->sessionMapper->findByCode($normalizedCode);
        $players = $this->playerMapper->findBySession($session->getId());
        $session = $this->maybeExpireAbandonedSession($session, $players);

        if ($session->getStatus() !== 'lobby') {
            throw new \RuntimeException('Session is no longer open for joining');
        }

        $normalizedCharacterId = $this->normalizeCharacterId($characterId ?? 'helpdesk');
        $resolvedDisplayName = $this->resolveDisplayName($userId, $displayName);
        $now = time();

        try {
            $existingPlayer = $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
            $existingPlayer->setDisplayName($resolvedDisplayName);
            $existingPlayer->setCharacterId($normalizedCharacterId);
            $existingPlayer->setLastHeartbeat($now);
            $this->playerMapper->update($existingPlayer);

            $players = $this->playerMapper->findBySession($session->getId());
            $session->setLastHeartbeat($now);
            $this->sessionMapper->update($session);

            return $this->buildLobbyPayload($session, $players);
        } catch (DoesNotExistException $e) {
            // Expected for a new participant.
        }

        if (count($players) >= $session->getMaxPlayers()) {
            throw new \RuntimeException('Session is full');
        }

        $player = $this->buildPlayer(
            $session->getId(),
            $userId,
            $resolvedDisplayName,
            $normalizedCharacterId,
            false,
            $now
        );
        $this->playerMapper->insert($player);
        $session->setLastHeartbeat($now);
        $this->sessionMapper->update($session);

        return $this->buildLobbyPayload($session, $this->playerMapper->findBySession($session->getId()));
    }

    /**
     * @return array<string, mixed>
     */
    public function getLobby(int $sessionId, string $userId): array {
        [$session, $player, $players] = $this->loadParticipantContext($sessionId, $userId);
        $session = $this->maybeExpireAbandonedSession($session, $players);
        $this->touchParticipant($session, $player);

        return $this->buildLobbyPayload(
            $this->sessionMapper->findById($sessionId),
            $this->playerMapper->findBySession($sessionId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function setReady(int $sessionId, string $userId, ?bool $ready = null, string $action = ''): array {
        [$session, $player, $players] = $this->loadParticipantContext($sessionId, $userId);
        $session = $this->maybeExpireAbandonedSession($session, $players);
        $now = time();

        if ($action === 'end') {
            if (!(bool)$player->getIsHost()) {
                throw new \RuntimeException('Only the host can end this session');
            }

            $stateBag = $this->mergeCoopState($this->extractGraphStateBag($session), $session->getStateBagDecoded());
            $stateBag['_coop']['session_end'] = [
                'ended_by' => $userId,
                'ended_at' => $now,
            ];

            $session->setStatus('finished');
            $session->setLastHeartbeat($now);
            $session->setStateBagFromArray($stateBag);
            $this->sessionMapper->update($session);
            $this->voteMapper->deleteBySessionAndNode($session->getId(), $session->getCurrentNodeId());

            return $this->buildStatePayload(
                $session,
                $this->playerMapper->findBySession($session->getId()),
                $this->fetchGraphState($session)
            );
        }

        if ($session->getStatus() === 'finished') {
            return $this->buildStatePayload($session, $players, $this->fetchGraphState($session));
        }

        if ($session->getStatus() !== 'lobby') {
            throw new \RuntimeException('Ready can only be changed while the session is in the lobby');
        }

        $player->setIsReady($ready ?? !$player->getIsReady());
        $player->setLastHeartbeat($now);
        $this->playerMapper->update($player);

        $players = $this->playerMapper->findBySession($session->getId());
        $session->setLastHeartbeat($now);

        if (count($players) >= self::MIN_PLAYERS && $this->areAllPlayersReady($players)) {
            $session->setStatus('playing');
        }
        $this->sessionMapper->update($session);

        if ($session->getStatus() === 'playing') {
            return $this->buildStatePayload($session, $players, $this->syncGraphState($session));
        }

        return $this->buildLobbyPayload($session, $players);
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(int $sessionId, string $userId): array {
        [$session, $player, $players] = $this->loadParticipantContext($sessionId, $userId);
        $session = $this->maybeExpireAbandonedSession($session, $players);
        $this->touchParticipant($session, $player);

        return $this->buildStatePayload(
            $this->sessionMapper->findById($sessionId),
            $this->playerMapper->findBySession($sessionId),
            $this->syncGraphState($session)
        );
    }

    /**
     * @param array<string, mixed> $interactionPayload
     * @return array<string, mixed>
     */
    public function submitVote(int $sessionId, string $userId, string $edgeId = '', array $interactionPayload = []): array {
        [$session, $player, $players] = $this->loadParticipantContext($sessionId, $userId);
        $session = $this->maybeExpireAbandonedSession($session, $players);

        if ($session->getStatus() === 'finished') {
            return $this->buildStatePayload($session, $players, $this->fetchGraphState($session));
        }

        $this->touchParticipant($session, $player);
        $players = $this->playerMapper->findBySession($sessionId);

        if ($session->getStatus() === 'advancing') {
            return $this->buildStatePayload(
                $this->sessionMapper->findById($sessionId),
                $players,
                $this->fetchGraphState($session)
            );
        }

        if ($session->getStatus() !== 'playing') {
            throw new \RuntimeException('Voting is only available during an active coop session');
        }

        $graphState = $this->syncGraphState($session);
        $session = $this->sessionMapper->findById($sessionId);
        $currentNodeId = (string)($graphState['scene']['id'] ?? $session->getCurrentNodeId());
        $normalizedEdgeId = trim($edgeId);

        $stateBag = $this->mergeCoopState($graphState['state']['state_bag'] ?? [], $session->getStateBagDecoded());
        $stateBag = $this->applyInteractionProgress($stateBag, $currentNodeId, $userId, $interactionPayload);
        $session->setStateBagFromArray($stateBag);
        $session->setLastHeartbeat(time());
        $this->sessionMapper->update($session);

        $hasInteractionUpdate = $this->hasInteractionProgressUpdate($interactionPayload);
        if ($normalizedEdgeId === '') {
            if (!$hasInteractionUpdate) {
                throw new \RuntimeException('edgeId or progress payload is required');
            }

            return $this->buildStatePayload($session, $players, $this->decorateGraphState($graphState, $stateBag));
        }

        $availableEdgeIds = array_map(
            static fn(array $edge): string => (string)($edge['id'] ?? ''),
            $graphState['available_edges'] ?? []
        );
        if (!in_array($normalizedEdgeId, $availableEdgeIds, true)) {
            throw new \RuntimeException('Selected edge is not available for the current scene');
        }

        $this->upsertVote($session->getId(), $currentNodeId, $userId, $normalizedEdgeId);
        $votes = $this->voteMapper->findBySessionAndNode($session->getId(), $currentNodeId);
        $resolution = $this->resolveVoteSummary($votes, $players);
        $stateBag['_coop']['vote'] = [
            'node_id' => $currentNodeId,
            'winning_edge_id' => $resolution['winning_edge_id'],
            'tie_break_used' => $resolution['tie_break_used'],
            'resolved' => $resolution['resolved'],
            'resolved_at' => $resolution['resolved'] ? time() : null,
        ];
        $session->setStateBagFromArray($stateBag);
        $this->sessionMapper->update($session);

        if (!$resolution['resolved']) {
            return $this->buildStatePayload($session, $players, $this->decorateGraphState($graphState, $stateBag));
        }

        if (!$this->claimAdvancingLock($session->getId())) {
            return $this->buildStatePayload(
                $this->sessionMapper->findById($sessionId),
                $players,
                $this->fetchGraphState($session)
            );
        }

        $previousNodeId = $currentNodeId;
        try {
            $traversalContext = $this->buildTraversalContext($stateBag, $currentNodeId);
            $nextGraphState = $this->storyEngineService->traverseGraphEdge(
                $this->buildGraphUserId($session->getSessionCode()),
                $session->getCampaignId(),
                (string)$resolution['winning_edge_id'],
                $traversalContext
            );

            $nextStateBag = $this->mergeCoopState($nextGraphState['state']['state_bag'] ?? [], $stateBag);
            $nextStateBag['_coop']['vote'] = [
                'node_id' => (string)($nextGraphState['scene']['id'] ?? ''),
                'winning_edge_id' => null,
                'tie_break_used' => false,
                'resolved' => false,
                'resolved_at' => null,
            ];
            $nextStateBag['_coop']['last_resolution'] = [
                'node_id' => $previousNodeId,
                'winning_edge_id' => (string)$resolution['winning_edge_id'],
                'tie_break_used' => $resolution['tie_break_used'],
                'resolved_at' => time(),
            ];

            $session->setCurrentNodeId((string)($nextGraphState['scene']['id'] ?? $session->getCurrentNodeId()));
            $session->setStatus($this->isGraphFinished($nextGraphState) ? 'finished' : 'playing');
            $session->setLastHeartbeat(time());
            $session->setStateBagFromArray($nextStateBag);
            $this->sessionMapper->update($session);
            $this->voteMapper->deleteBySessionAndNode($session->getId(), $previousNodeId);

            return $this->buildStatePayload(
                $session,
                $this->playerMapper->findBySession($session->getId()),
                $this->decorateGraphState($nextGraphState, $nextStateBag)
            );
        } catch (\Throwable $e) {
            $this->forceSessionStatus($session->getId(), 'playing');
            $this->logger->warning('Coop vote resolution failed: ' . $e->getMessage(), [
                'app' => 'learning',
                'session_id' => $session->getId(),
            ]);
            throw $e;
        }
    }

    private function buildPlayer(int $sessionId, string $userId, string $displayName, string $characterId, bool $isHost, int $timestamp): CoopPlayer {
        $player = new CoopPlayer();
        $player->setSessionId($sessionId);
        $player->setUserId($userId);
        $player->setDisplayName($displayName);
        $player->setCharacterId($characterId);
        $player->setIsReady(false);
        $player->setIsHost($isHost);
        $player->setJoinedAt($timestamp);
        $player->setLastHeartbeat($timestamp);

        return $player;
    }

    /**
     * @return array{0: CoopSession, 1: CoopPlayer, 2: array<int, CoopPlayer>}
     */
    private function loadParticipantContext(int $sessionId, string $userId): array {
        $session = $this->sessionMapper->findById($sessionId);
        $player = $this->playerMapper->findBySessionAndUser($sessionId, $userId);

        return [$session, $player, $this->playerMapper->findBySession($sessionId)];
    }

    private function touchParticipant(CoopSession $session, CoopPlayer $player): void {
        $now = time();
        $player->setLastHeartbeat($now);
        $session->setLastHeartbeat($now);
        $this->playerMapper->update($player);
        $this->sessionMapper->update($session);
    }

    private function maybeExpireAbandonedSession(CoopSession $session, array $players): CoopSession {
        if ($session->getStatus() === 'finished') {
            return $session;
        }

        $now = time();
        $onlinePlayers = array_filter($players, fn(CoopPlayer $player): bool => $this->isPlayerOnline($player, $now));
        if ($onlinePlayers !== []) {
            return $session;
        }

        if (($now - $session->getLastHeartbeat()) < self::SESSION_STALE_SECONDS) {
            return $session;
        }

        $session->setStatus('finished');
        $session->setLastHeartbeat($now);
        $this->sessionMapper->update($session);

        return $session;
    }

    private function areAllPlayersReady(array $players): bool {
        foreach ($players as $player) {
            if (!(bool)$player->getIsReady()) {
                return false;
            }
        }

        return $players !== [];
    }

    private function normalizeCharacterId(string $characterId): string {
        $normalized = trim($characterId);
        if (!in_array($normalized, self::VALID_CHARACTER_IDS, true)) {
            throw new \RuntimeException('Invalid characterId: must be architect, security, sysadmin, or helpdesk');
        }

        return $normalized;
    }

    private function normalizeSessionCode(string $sessionCode): string {
        $normalized = strtoupper(trim($sessionCode));
        if ($normalized === '') {
            throw new \RuntimeException('Session code is required');
        }

        return $normalized;
    }

    private function buildGraphUserId(string $sessionCode): string {
        return 'coop:' . strtoupper($sessionCode);
    }

    private function generateSessionCode(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            try {
                $this->sessionMapper->findByCode($code);
            } catch (DoesNotExistException $e) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate unique coop session code');
    }

    private function resolveDisplayName(string $userId, ?string $requestedDisplayName): string {
        $custom = trim((string)($requestedDisplayName ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $user = $this->userManager->get($userId);

        return $user !== null ? $user->getDisplayName() : $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchGraphState(CoopSession $session): array {
        return $this->storyEngineService->getGraphScene(
            $this->buildGraphUserId($session->getSessionCode()),
            $session->getCampaignId()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function syncGraphState(CoopSession $session): array {
        $graphState = $this->fetchGraphState($session);
        $mergedStateBag = $this->mergeCoopState($graphState['state']['state_bag'] ?? [], $session->getStateBagDecoded());
        $nextNodeId = (string)($graphState['scene']['id'] ?? $session->getCurrentNodeId());
        $needsUpdate = $session->getCurrentNodeId() !== $nextNodeId
            || $this->extractGraphStateBag($session) !== $mergedStateBag;

        if ($needsUpdate) {
            $session->setCurrentNodeId($nextNodeId);
            $session->setStateBagFromArray($mergedStateBag);
            $session->setLastHeartbeat(time());
            $this->sessionMapper->update($session);
        }

        return $this->decorateGraphState($graphState, $mergedStateBag);
    }

    /**
     * @param array<string, mixed> $graphState
     * @param array<string, mixed> $mergedStateBag
     * @return array<string, mixed>
     */
    private function decorateGraphState(array $graphState, array $mergedStateBag): array {
        $graphState['state']['state_bag'] = $mergedStateBag;
        if (isset($graphState['progress']) && is_array($graphState['progress'])) {
            $graphState['progress']['state_bag'] = $mergedStateBag;
        }

        return $graphState;
    }

    /**
     * @param array<string, mixed> $graphStateBag
     * @param array<string, mixed> $sessionStateBag
     * @return array<string, mixed>
     */
    private function mergeCoopState(array $graphStateBag, array $sessionStateBag): array {
        $graphStateBag['_coop'] = $this->normalizeCoopMeta($sessionStateBag['_coop'] ?? []);

        return $graphStateBag;
    }

    /**
     * @param mixed $coopMeta
     * @return array<string, mixed>
     */
    private function normalizeCoopMeta($coopMeta): array {
        $meta = is_array($coopMeta) ? $coopMeta : [];
        if (!isset($meta['vote']) || !is_array($meta['vote'])) {
            $meta['vote'] = [
                'node_id' => null,
                'winning_edge_id' => null,
                'tie_break_used' => false,
                'resolved' => false,
                'resolved_at' => null,
            ];
        }
        if (!isset($meta['last_resolution']) || !is_array($meta['last_resolution'])) {
            $meta['last_resolution'] = [];
        }
        if (!isset($meta['simulator_progress']) || !is_array($meta['simulator_progress'])) {
            $meta['simulator_progress'] = [];
        }
        if (!isset($meta['bot_progress']) || !is_array($meta['bot_progress'])) {
            $meta['bot_progress'] = [];
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractGraphStateBag(CoopSession $session): array {
        return $session->getStateBagDecoded();
    }

    /**
     * @param array<string, mixed> $interactionPayload
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function applyInteractionProgress(array $stateBag, string $nodeId, string $userId, array $interactionPayload): array {
        $meta = $this->normalizeCoopMeta($stateBag['_coop'] ?? []);
        $now = time();

        $hasSimulatorUpdate = array_key_exists('simulator_completed', $interactionPayload)
            || array_key_exists('simulator_passed', $interactionPayload)
            || array_key_exists('simulator_score', $interactionPayload)
            || array_key_exists('simulator_result', $interactionPayload);

        if ($hasSimulatorUpdate) {
            if (!isset($meta['simulator_progress'][$nodeId]) || !is_array($meta['simulator_progress'][$nodeId])) {
                $meta['simulator_progress'][$nodeId] = [];
            }
            $existing = is_array($meta['simulator_progress'][$nodeId][$userId] ?? null)
                ? $meta['simulator_progress'][$nodeId][$userId]
                : [];
            if (array_key_exists('simulator_completed', $interactionPayload)) {
                $existing['completed'] = (bool)$interactionPayload['simulator_completed'];
            }
            if (array_key_exists('simulator_passed', $interactionPayload)) {
                $existing['passed'] = (bool)$interactionPayload['simulator_passed'];
            }
            if (array_key_exists('simulator_score', $interactionPayload) && $interactionPayload['simulator_score'] !== null) {
                $existing['score'] = (int)$interactionPayload['simulator_score'];
            }
            if (array_key_exists('simulator_result', $interactionPayload)) {
                $existing['result'] = $interactionPayload['simulator_result'];
            }
            $existing['updated_at'] = $now;
            $meta['simulator_progress'][$nodeId][$userId] = $existing;
        }

        $hasBotUpdate = array_key_exists('bot_error_id', $interactionPayload)
            || array_key_exists('bot_fix_id', $interactionPayload)
            || array_key_exists('bot_correction_passed', $interactionPayload);

        if ($hasBotUpdate) {
            if (!isset($meta['bot_progress'][$nodeId]) || !is_array($meta['bot_progress'][$nodeId])) {
                $meta['bot_progress'][$nodeId] = [];
            }
            $existing = is_array($meta['bot_progress'][$nodeId][$userId] ?? null)
                ? $meta['bot_progress'][$nodeId][$userId]
                : [];
            if (array_key_exists('bot_error_id', $interactionPayload)) {
                $existing['submitted_error_id'] = trim((string)$interactionPayload['bot_error_id']);
            }
            if (array_key_exists('bot_fix_id', $interactionPayload)) {
                $existing['submitted_fix_id'] = trim((string)$interactionPayload['bot_fix_id']);
            }
            if (array_key_exists('bot_correction_passed', $interactionPayload)) {
                $existing['completed'] = true;
                $existing['passed'] = (bool)$interactionPayload['bot_correction_passed'];
            }
            $existing['updated_at'] = $now;
            $meta['bot_progress'][$nodeId][$userId] = $existing;
        }

        $stateBag['_coop'] = $meta;

        return $stateBag;
    }

    /**
     * @param array<string, mixed> $interactionPayload
     */
    private function hasInteractionProgressUpdate(array $interactionPayload): bool {
        return array_key_exists('simulator_completed', $interactionPayload)
            || array_key_exists('simulator_passed', $interactionPayload)
            || array_key_exists('simulator_score', $interactionPayload)
            || array_key_exists('simulator_result', $interactionPayload)
            || array_key_exists('bot_error_id', $interactionPayload)
            || array_key_exists('bot_fix_id', $interactionPayload)
            || array_key_exists('bot_correction_passed', $interactionPayload);
    }

    /**
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function buildTraversalContext(array $stateBag, string $nodeId): array {
        $context = [];
        $meta = $this->normalizeCoopMeta($stateBag['_coop'] ?? []);

        $simulatorProgress = is_array($meta['simulator_progress'][$nodeId] ?? null)
            ? $meta['simulator_progress'][$nodeId]
            : [];
        if ($simulatorProgress !== []) {
            $completedCount = 0;
            $anyPassed = false;
            $bestScore = null;
            $latestResult = null;
            $latestUpdatedAt = -1;
            foreach ($simulatorProgress as $progress) {
                if (!is_array($progress) || empty($progress['completed'])) {
                    continue;
                }

                $completedCount++;
                $anyPassed = $anyPassed || !empty($progress['passed']);
                if (isset($progress['score'])) {
                    $score = (int)$progress['score'];
                    $bestScore = $bestScore === null ? $score : max($bestScore, $score);
                }
                $updatedAt = (int)($progress['updated_at'] ?? 0);
                if ($updatedAt >= $latestUpdatedAt && array_key_exists('result', $progress)) {
                    $latestUpdatedAt = $updatedAt;
                    $latestResult = $progress['result'];
                }
            }

            if ($completedCount > 0) {
                $context['simulator_passed'] = $anyPassed;
                if ($bestScore !== null) {
                    $context['simulator_score'] = $bestScore;
                }
                if ($latestResult !== null) {
                    $context['simulator_result'] = $latestResult;
                }
            }
        }

        $botProgress = is_array($meta['bot_progress'][$nodeId] ?? null)
            ? $meta['bot_progress'][$nodeId]
            : [];
        if ($botProgress !== []) {
            $selected = null;
            $latestUpdatedAt = -1;
            foreach ($botProgress as $progress) {
                if (!is_array($progress)) {
                    continue;
                }
                if (!empty($progress['passed'])) {
                    $selected = $progress;
                    break;
                }

                $updatedAt = (int)($progress['updated_at'] ?? 0);
                if ($updatedAt >= $latestUpdatedAt) {
                    $latestUpdatedAt = $updatedAt;
                    $selected = $progress;
                }
            }

            if (is_array($selected)) {
                $submittedErrorId = trim((string)($selected['submitted_error_id'] ?? ''));
                $submittedFixId = trim((string)($selected['submitted_fix_id'] ?? ''));
                if ($submittedErrorId !== '') {
                    $context['bot_error_id'] = $submittedErrorId;
                }
                if ($submittedFixId !== '') {
                    $context['bot_fix_id'] = $submittedFixId;
                }
                if (array_key_exists('passed', $selected)) {
                    $context['bot_correction_passed'] = (bool)$selected['passed'];
                }
            }
        }

        return $context;
    }

    private function upsertVote(int $sessionId, string $nodeId, string $userId, string $edgeId): void {
        $now = time();

        try {
            $vote = $this->voteMapper->findBySessionNodeAndUser($sessionId, $nodeId, $userId);
            $vote->setEdgeId($edgeId);
            $vote->setVotedAt($now);
            $this->voteMapper->update($vote);
            return;
        } catch (DoesNotExistException $e) {
            // Insert below.
        }

        $vote = new CoopVote();
        $vote->setSessionId($sessionId);
        $vote->setUserId($userId);
        $vote->setNodeId($nodeId);
        $vote->setEdgeId($edgeId);
        $vote->setVotedAt($now);

        try {
            $this->voteMapper->insert($vote);
        } catch (DbException $e) {
            $existingVote = $this->voteMapper->findBySessionNodeAndUser($sessionId, $nodeId, $userId);
            $existingVote->setEdgeId($edgeId);
            $existingVote->setVotedAt($now);
            $this->voteMapper->update($existingVote);
        }
    }

    /**
     * @param array<int, CoopVote> $votes
     * @param array<int, CoopPlayer> $players
     * @return array<string, mixed>
     */
    private function resolveVoteSummary(array $votes, array $players): array {
        $now = time();
        $eligiblePlayers = array_values(array_filter(
            $players,
            fn(CoopPlayer $player): bool => $this->isPlayerOnline($player, $now)
        ));
        if ($eligiblePlayers === []) {
            $eligiblePlayers = $players;
        }

        $eligibleIds = array_map(static fn(CoopPlayer $player): string => $player->getUserId(), $eligiblePlayers);
        $counts = [];
        $submittedBy = [];
        foreach ($votes as $vote) {
            if (!in_array($vote->getUserId(), $eligibleIds, true)) {
                continue;
            }
            $edgeId = $vote->getEdgeId();
            $counts[$edgeId] = ($counts[$edgeId] ?? 0) + 1;
            $submittedBy[$vote->getUserId()] = true;
        }

        if ($counts === []) {
            return [
                'resolved' => false,
                'winning_edge_id' => null,
                'tie_break_used' => false,
                'counts' => $counts,
            ];
        }

        arsort($counts);
        $winningEdgeId = (string)array_key_first($counts);
        $topVotes = (int)reset($counts);
        $topEdges = array_keys(array_filter(
            $counts,
            static fn(int $count): bool => $count === $topVotes
        ));
        $eligibleCount = count($eligibleIds);
        $submittedCount = count($submittedBy);

        if ($submittedCount < $eligibleCount && count($topEdges) === 1 && $topVotes > intdiv($eligibleCount, 2)) {
            return [
                'resolved' => true,
                'winning_edge_id' => $winningEdgeId,
                'tie_break_used' => false,
                'counts' => $counts,
            ];
        }

        if ($submittedCount < $eligibleCount) {
            return [
                'resolved' => false,
                'winning_edge_id' => null,
                'tie_break_used' => false,
                'counts' => $counts,
            ];
        }

        if (count($topEdges) === 1) {
            return [
                'resolved' => true,
                'winning_edge_id' => $winningEdgeId,
                'tie_break_used' => false,
                'counts' => $counts,
            ];
        }

        $randomIndex = random_int(0, count($topEdges) - 1);

        return [
            'resolved' => true,
            'winning_edge_id' => (string)$topEdges[$randomIndex],
            'tie_break_used' => true,
            'counts' => $counts,
        ];
    }

    private function claimAdvancingLock(int $sessionId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_coop_sessions')
            ->set('status', $qb->createNamedParameter('advancing'))
            ->set('last_heartbeat', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('playing')));

        return $qb->executeStatement() > 0;
    }

    private function forceSessionStatus(int $sessionId, string $status): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_coop_sessions')
            ->set('status', $qb->createNamedParameter($status))
            ->set('last_heartbeat', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLobbyPayload(CoopSession $session, array $players): array {
        $readyCount = count(array_filter($players, static fn(CoopPlayer $player): bool => (bool)$player->getIsReady()));

        return [
            'session' => [
                'id' => $session->getId(),
                'code' => $session->getSessionCode(),
                'campaign_id' => $session->getCampaignId(),
                'host_user_id' => $session->getHostUserId(),
                'status' => $session->getStatus(),
                'current_node_id' => $session->getCurrentNodeId(),
                'max_players' => $session->getMaxPlayers(),
                'poll_interval_ms' => self::POLL_INTERVAL_MS,
            ],
            'players' => $this->serializePlayers($players),
            'lobby' => [
                'player_count' => count($players),
                'ready_count' => $readyCount,
                'can_start' => count($players) >= self::MIN_PLAYERS && $readyCount === count($players),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $graphState
     * @return array<string, mixed>
     */
    private function buildStatePayload(CoopSession $session, array $players, array $graphState): array {
        $stateBag = $this->mergeCoopState($graphState['state']['state_bag'] ?? [], $session->getStateBagDecoded());
        $currentNodeId = (string)($graphState['scene']['id'] ?? $session->getCurrentNodeId());
        $votes = $this->voteMapper->findBySessionAndNode($session->getId(), $currentNodeId);
        $voteSummary = $this->buildVotePayload($votes, $players);
        $scene = is_array($graphState['scene'] ?? null) ? $graphState['scene'] : [];

        return [
            'session' => [
                'id' => $session->getId(),
                'code' => $session->getSessionCode(),
                'campaign_id' => $session->getCampaignId(),
                'host_user_id' => $session->getHostUserId(),
                'status' => $session->getStatus(),
                'current_node_id' => $currentNodeId,
                'max_players' => $session->getMaxPlayers(),
                'poll_interval_ms' => self::POLL_INTERVAL_MS,
            ],
            'scene' => [
                'id' => $currentNodeId,
                'title' => (string)($scene['title'] ?? ''),
                'narrative' => (string)($scene['narrative'] ?? ''),
                'type' => (string)($scene['type'] ?? 'graph_scene'),
                'act' => (int)($scene['act'] ?? 1),
                'is_ending' => (bool)($scene['is_ending'] ?? false),
                'timer' => $graphState['timer'] ?? ($scene['timer'] ?? null),
                'simulator' => $graphState['simulator'] ?? ($scene['simulator'] ?? null),
                'dau_bot' => $graphState['dau_bot'] ?? ($scene['dau_bot'] ?? null),
                'bot_correction' => $graphState['bot_correction'] ?? ($scene['bot_correction'] ?? null),
            ],
            'available_edges' => array_map(static fn(array $edge): array => [
                'id' => (string)($edge['id'] ?? ''),
                'label' => (string)($edge['label'] ?? ''),
                'to_act' => isset($edge['to_act']) ? (int)$edge['to_act'] : null,
            ], $graphState['available_edges'] ?? []),
            'players' => $this->serializePlayers($players),
            'votes' => $voteSummary,
            'shared_state' => [
                'flags' => $stateBag['flags'] ?? [],
                'items' => $stateBag['items'] ?? [],
                'reputation' => $stateBag['reputation'] ?? [],
                'act_number' => (int)($graphState['progress']['act_number'] ?? $scene['act'] ?? 1),
            ],
            'simulator_progress' => $this->buildSimulatorProgressPayload($stateBag, $currentNodeId, $players),
            'bot_progress' => $this->buildBotProgressPayload($stateBag, $currentNodeId, $players),
        ];
    }

    /**
     * @param array<int, CoopVote> $votes
     * @param array<int, CoopPlayer> $players
     * @return array<string, mixed>
     */
    private function buildVotePayload(array $votes, array $players): array {
        $counts = [];
        $myVotes = [];
        foreach ($votes as $vote) {
            $edgeId = $vote->getEdgeId();
            $counts[$edgeId] = ($counts[$edgeId] ?? 0) + 1;
            $myVotes[$vote->getUserId()] = $edgeId;
        }

        return [
            'total_players' => count($players),
            'votes_cast' => count($votes),
            'counts' => array_map(
                static fn(string $edgeId, int $count): array => ['edge_id' => $edgeId, 'count' => $count],
                array_keys($counts),
                array_values($counts)
            ),
            'player_votes' => $myVotes,
        ];
    }

    /**
     * @param array<string, mixed> $stateBag
     * @param array<int, CoopPlayer> $players
     * @return array<int, array<string, mixed>>
     */
    private function buildSimulatorProgressPayload(array $stateBag, string $nodeId, array $players): array {
        $meta = $this->normalizeCoopMeta($stateBag['_coop'] ?? []);
        $progressByPlayer = is_array($meta['simulator_progress'][$nodeId] ?? null)
            ? $meta['simulator_progress'][$nodeId]
            : [];
        $indexedPlayers = [];
        foreach ($players as $player) {
            $indexedPlayers[$player->getUserId()] = $player;
        }

        $payload = [];
        foreach ($progressByPlayer as $userId => $progress) {
            if (!is_array($progress)) {
                continue;
            }
            $player = $indexedPlayers[$userId] ?? null;
            $payload[] = [
                'user_id' => $userId,
                'display_name' => $player instanceof CoopPlayer ? $player->getDisplayName() : $userId,
                'completed' => (bool)($progress['completed'] ?? false),
                'passed' => (bool)($progress['passed'] ?? false),
                'score' => isset($progress['score']) ? (int)$progress['score'] : null,
                'updated_at' => isset($progress['updated_at']) ? (int)$progress['updated_at'] : null,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $stateBag
     * @param array<int, CoopPlayer> $players
     * @return array<int, array<string, mixed>>
     */
    private function buildBotProgressPayload(array $stateBag, string $nodeId, array $players): array {
        $meta = $this->normalizeCoopMeta($stateBag['_coop'] ?? []);
        $progressByPlayer = is_array($meta['bot_progress'][$nodeId] ?? null)
            ? $meta['bot_progress'][$nodeId]
            : [];
        $indexedPlayers = [];
        foreach ($players as $player) {
            $indexedPlayers[$player->getUserId()] = $player;
        }

        $payload = [];
        foreach ($progressByPlayer as $userId => $progress) {
            if (!is_array($progress)) {
                continue;
            }
            $player = $indexedPlayers[$userId] ?? null;
            $payload[] = [
                'user_id' => $userId,
                'display_name' => $player instanceof CoopPlayer ? $player->getDisplayName() : $userId,
                'completed' => (bool)($progress['completed'] ?? false),
                'passed' => (bool)($progress['passed'] ?? false),
                'submitted_error_id' => $progress['submitted_error_id'] ?? null,
                'submitted_fix_id' => $progress['submitted_fix_id'] ?? null,
                'updated_at' => isset($progress['updated_at']) ? (int)$progress['updated_at'] : null,
            ];
        }

        return $payload;
    }

    /**
     * @param array<int, CoopPlayer> $players
     * @return array<int, array<string, mixed>>
     */
    private function serializePlayers(array $players): array {
        $now = time();

        return array_map(function (CoopPlayer $player) use ($now): array {
            return [
                'user_id' => $player->getUserId(),
                'display_name' => $player->getDisplayName(),
                'character_id' => $player->getCharacterId(),
                'is_host' => (bool)$player->getIsHost(),
                'is_ready' => (bool)$player->getIsReady(),
                'is_online' => $this->isPlayerOnline($player, $now),
                'joined_at' => $player->getJoinedAt(),
                'last_heartbeat' => $player->getLastHeartbeat(),
            ];
        }, $players);
    }

    private function isPlayerOnline(CoopPlayer $player, int $now): bool {
        return ($now - $player->getLastHeartbeat()) <= self::PLAYER_TIMEOUT_SECONDS;
    }

    /**
     * @param array<string, mixed> $graphState
     */
    private function isGraphFinished(array $graphState): bool {
        $scene = is_array($graphState['scene'] ?? null) ? $graphState['scene'] : [];
        $availableEdges = $graphState['available_edges'] ?? [];

        return !empty($scene['is_ending']) || $availableEdges === [];
    }
}
