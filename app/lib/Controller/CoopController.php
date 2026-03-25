<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CoopService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CoopController extends Controller {
    private CoopService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, CoopService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $campaignId = trim((string)($this->request->getParam('campaignId', $this->request->getParam('campaign_id', ''))));
        if ($campaignId === '') {
            return new DataResponse(['error' => 'campaignId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new DataResponse(
                $this->service->createSession(
                    $campaignId,
                    $this->userId,
                    (string)($this->request->getParam('characterId', $this->request->getParam('character_id', 'helpdesk'))),
                    (int)$this->request->getParam('maxPlayers', $this->request->getParam('max_players', 4))
                ),
                Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to create coop session'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function join(string $code): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new DataResponse(
                $this->service->joinSession(
                    $code,
                    $this->userId,
                    (string)($this->request->getParam('characterId', $this->request->getParam('character_id', 'helpdesk'))),
                    (string)($this->request->getParam('displayName', $this->request->getParam('display_name', '')))
                )
            );
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to join coop session'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function lobby(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new DataResponse($this->service->getLobby($id, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load coop lobby'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function ready(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new DataResponse(
                $this->service->setReady(
                    $id,
                    $this->userId,
                    $this->readNullableBool(['ready', 'isReady', 'is_ready']),
                    trim((string)$this->request->getParam('action', ''))
                )
            );
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to update coop ready state'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function state(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new DataResponse($this->service->getState($id, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load coop state'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function vote(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $edgeId = trim((string)($this->request->getParam('edgeId', $this->request->getParam('edge_id', ''))));

        try {
            return new DataResponse($this->service->submitVote($id, $this->userId, $edgeId, $this->collectVotePayload()));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit coop vote'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @param list<string> $keys
     */
    private function readNullableBool(array $keys): ?bool {
        foreach ($keys as $key) {
            $value = $this->request->getParam($key);
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectVotePayload(): array {
        $payload = [];

        foreach ([
            ['simulatorCompleted', 'simulator_completed'],
            ['simulatorPassed', 'simulator_passed'],
            ['botCorrectionPassed', 'bot_correction_passed'],
        ] as $keyPair) {
            $value = $this->request->getParam($keyPair[0], $this->request->getParam($keyPair[1]));
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $payload[$keyPair[1]] = $parsed;
            }
        }

        $simulatorScore = $this->request->getParam('simulatorScore', $this->request->getParam('simulator_score'));
        if ($simulatorScore !== null && $simulatorScore !== '') {
            $payload['simulator_score'] = (int)$simulatorScore;
        }

        $simulatorResult = $this->request->getParam('simulatorResult', $this->request->getParam('simulator_result'));
        if ($simulatorResult !== null && $simulatorResult !== '') {
            $payload['simulator_result'] = $simulatorResult;
        }

        $botErrorId = $this->request->getParam('botErrorId', $this->request->getParam('bot_error_id'));
        if ($botErrorId !== null && $botErrorId !== '') {
            $payload['bot_error_id'] = (string)$botErrorId;
        }

        $botFixId = $this->request->getParam('botFixId', $this->request->getParam('bot_fix_id'));
        if ($botFixId !== null && $botFixId !== '') {
            $payload['bot_fix_id'] = (string)$botFixId;
        }

        return $payload;
    }
}
