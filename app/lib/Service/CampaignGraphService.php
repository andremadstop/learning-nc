<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CampaignState;
use OCA\Learning\Db\CampaignStateMapper;
use Psr\Log\LoggerInterface;

class CampaignGraphService {
    private CampaignStateMapper $stateMapper;
    private LoggerInterface $logger;
    private DauBotService $dauBotService;

    public function __construct(
        CampaignStateMapper $stateMapper,
        LoggerInterface $logger,
        DauBotService $dauBotService
    ) {
        $this->stateMapper = $stateMapper;
        $this->logger = $logger;
        $this->dauBotService = $dauBotService;
    }

    /**
     * Detect whether a campaign uses graph format (nodes + edges).
     *
     * @param array<string, mixed> $campaignData
     */
    public function isGraphCampaign(array $campaignData): bool {
        return isset($campaignData['graph']['nodes'])
            && isset($campaignData['graph']['edges']);
    }

    /**
     * Get the start node ID from a graph.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @throws \RuntimeException if graph has no nodes
     */
    public function getStartNodeId(array $graph): string {
        if (empty($graph['nodes'])) {
            throw new \RuntimeException('Graph has no nodes');
        }
        foreach ($graph['nodes'] as $node) {
            if (!empty($node['start'])) {
                return (string)$node['id'];
            }
        }
        return (string)$graph['nodes'][0]['id'];
    }

    /**
     * Resolve the act number for a node, preferring the graph-level acts[] structure.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, acts?: list<array<string, mixed>>} $graph
     */
    private function getNodeActNumber(array $graph, string $nodeId): int {
        if (!empty($graph['acts']) && is_array($graph['acts'])) {
            foreach ($graph['acts'] as $index => $act) {
                $nodeIds = $act['node_ids'] ?? [];
                if (!is_array($nodeIds)) {
                    continue;
                }
                foreach ($nodeIds as $candidateNodeId) {
                    if ((string)$candidateNodeId === $nodeId) {
                        return $index + 1;
                    }
                }
            }
        }

        $node = $this->findNode($graph, $nodeId);
        $actValue = $node['act'] ?? $node['act_number'] ?? 1;

        return max(1, (int)$actValue);
    }

    private function isAllowedActTransition(int $currentActNumber, int $targetActNumber): bool {
        return $targetActNumber >= $currentActNumber
            && $targetActNumber <= ($currentActNumber + 1);
    }

    /**
     * @param mixed $roleFilter
     * @return list<string>
     */
    private function normalizeRoleFilter($roleFilter): array {
        if (!is_array($roleFilter)) {
            return [];
        }

        $normalized = [];
        foreach ($roleFilter as $role) {
            $value = trim((string)$role);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isRoleAllowed(array $payload, ?string $characterClass): bool {
        $roleFilter = $this->normalizeRoleFilter($payload['role_filter'] ?? []);
        if ($roleFilter === [] || $characterClass === null || $characterClass === '') {
            return true;
        }

        return in_array($characterClass, $roleFilter, true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function assertNodeAccessible(array $node, string $characterClass): void {
        if ($this->isRoleAllowed($node, $characterClass)) {
            return;
        }

        $nodeId = (string)($node['id'] ?? 'unknown');
        throw new \RuntimeException("Character class '{$characterClass}' cannot access node '{$nodeId}'");
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function getNodeSimulator(array $node): ?array {
        $simulator = $node['simulator'] ?? $node['simulation'] ?? null;
        if (!is_array($simulator)) {
            return null;
        }

        return $simulator;
    }

    /**
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function normalizeStateBag(array $stateBag): array {
        if (!isset($stateBag['flags']) || !is_array($stateBag['flags'])) {
            $stateBag['flags'] = [];
        }
        if (!isset($stateBag['items']) || !is_array($stateBag['items'])) {
            $stateBag['items'] = [];
        }
        if (!isset($stateBag['reputation']) || !is_array($stateBag['reputation'])) {
            $stateBag['reputation'] = [];
        }

        return $stateBag;
    }

    /**
     * Seed runtime-only node metadata in the state-bag on first visit.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function initializeNodeRuntimeState(array $node, array $stateBag): array {
        $bag = $this->normalizeStateBag($stateBag);
        $nodeId = (string)($node['id'] ?? '');
        $now = time();

        $timerSeconds = max(0, (int)($node['timer_seconds'] ?? 0));
        if ($timerSeconds > 0) {
            if (!isset($bag['timers']) || !is_array($bag['timers'])) {
                $bag['timers'] = [];
            }
            if (!isset($bag['timers'][$nodeId]) || !is_array($bag['timers'][$nodeId])) {
                $bag['timers'][$nodeId] = [
                    'started_at' => $now,
                    'duration_seconds' => $timerSeconds,
                    'deadline_at' => $now + $timerSeconds,
                ];
            }
        }

        $simulator = $this->getNodeSimulator($node);
        if ($simulator !== null) {
            if (!isset($bag['simulators']) || !is_array($bag['simulators'])) {
                $bag['simulators'] = [];
            }
            if (!isset($bag['simulators'][$nodeId]) || !is_array($bag['simulators'][$nodeId])) {
                $bag['simulators'][$nodeId] = [
                    'type' => (string)($simulator['type'] ?? ''),
                    'scenario' => (string)($simulator['scenario'] ?? ''),
                    'pass_flag' => (string)($simulator['pass_flag'] ?? ''),
                    'completed' => false,
                    'passed' => false,
                ];
            }
        }

        if ((string)($node['type'] ?? '') === 'bot_correction') {
            if (!isset($bag['bot_corrections']) || !is_array($bag['bot_corrections'])) {
                $bag['bot_corrections'] = [];
            }
            if (!isset($bag['bot_corrections'][$nodeId]) || !is_array($bag['bot_corrections'][$nodeId])) {
                $bag['bot_corrections'][$nodeId] = [
                    'scenario' => $this->dauBotService->buildBotCorrectionScenario($node, $bag),
                    'completed' => false,
                    'passed' => false,
                    'created_at' => $now,
                ];
            }
        }

        return $bag;
    }

    /**
     * Apply simulator / bot-correction results for the current node before edge validation.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @param array<string, mixed> $interactionContext
     * @return array<string, mixed>
     */
    private function applyNodeInteractionContext(array $node, array $stateBag, array $interactionContext): array {
        $bag = $this->initializeNodeRuntimeState($node, $stateBag);
        $nodeId = (string)($node['id'] ?? '');
        $now = time();

        $simulator = $this->getNodeSimulator($node);
        $hasSimulatorInput = array_key_exists('simulator_passed', $interactionContext)
            || array_key_exists('simulator_score', $interactionContext)
            || array_key_exists('simulator_result', $interactionContext);

        if ($simulator !== null && $hasSimulatorInput) {
            $simulatorState = is_array($bag['simulators'][$nodeId] ?? null)
                ? $bag['simulators'][$nodeId]
                : [];
            $passed = array_key_exists('simulator_passed', $interactionContext)
                ? (bool)$interactionContext['simulator_passed']
                : (bool)($simulatorState['passed'] ?? false);

            $simulatorState['type'] = (string)($simulator['type'] ?? ($simulatorState['type'] ?? ''));
            $simulatorState['scenario'] = (string)($simulator['scenario'] ?? ($simulatorState['scenario'] ?? ''));
            $simulatorState['pass_flag'] = (string)($simulator['pass_flag'] ?? ($simulatorState['pass_flag'] ?? ''));
            $simulatorState['completed'] = true;
            $simulatorState['passed'] = $passed;
            $simulatorState['updated_at'] = $now;

            if (array_key_exists('simulator_score', $interactionContext)) {
                $simulatorState['score'] = (int)$interactionContext['simulator_score'];
            }
            if (array_key_exists('simulator_result', $interactionContext)) {
                $simulatorState['result'] = $interactionContext['simulator_result'];
            }

            $bag['simulators'][$nodeId] = $simulatorState;

            $passFlag = (string)($simulator['pass_flag'] ?? '');
            if ($passFlag !== '' && $passed) {
                $bag['flags'][$passFlag] = true;
            }
        }

        $isBotCorrectionNode = (string)($node['type'] ?? '') === 'bot_correction';
        $hasBotInput = array_key_exists('bot_error_id', $interactionContext)
            || array_key_exists('bot_fix_id', $interactionContext)
            || array_key_exists('bot_correction_passed', $interactionContext);

        if ($isBotCorrectionNode && $hasBotInput) {
            $correctionState = is_array($bag['bot_corrections'][$nodeId] ?? null)
                ? $bag['bot_corrections'][$nodeId]
                : [];
            $scenario = is_array($correctionState['scenario'] ?? null)
                ? $correctionState['scenario']
                : $this->dauBotService->buildBotCorrectionScenario($node, $bag);

            $submittedErrorId = trim((string)($interactionContext['bot_error_id'] ?? ''));
            $submittedFixId = trim((string)($interactionContext['bot_fix_id'] ?? ''));
            $passed = null;

            if (array_key_exists('bot_correction_passed', $interactionContext)) {
                $passed = (bool)$interactionContext['bot_correction_passed'];
            } elseif ($submittedErrorId !== '' && $submittedFixId !== '') {
                $passed = $submittedErrorId === (string)($scenario['expected_error_id'] ?? '')
                    && $submittedFixId === (string)($scenario['expected_fix_id'] ?? '');
            }

            if ($submittedErrorId !== '') {
                $correctionState['submitted_error_id'] = $submittedErrorId;
            }
            if ($submittedFixId !== '') {
                $correctionState['submitted_fix_id'] = $submittedFixId;
            }
            if ($passed !== null) {
                $correctionState['completed'] = true;
                $correctionState['passed'] = $passed;
                $correctionState['updated_at'] = $now;

                $passFlag = trim((string)($scenario['pass_flag'] ?? ''));
                if ($passFlag !== '' && $passed) {
                    $bag['flags'][$passFlag] = true;
                }
            }

            $correctionState['scenario'] = $scenario;
            $bag['bot_corrections'][$nodeId] = $correctionState;
        }

        return $bag;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>|null
     */
    private function buildTimerPayload(array $node, array $stateBag): ?array {
        $timerSeconds = max(0, (int)($node['timer_seconds'] ?? 0));
        if ($timerSeconds === 0) {
            return null;
        }

        $nodeId = (string)($node['id'] ?? '');
        $timerState = is_array($stateBag['timers'][$nodeId] ?? null)
            ? $stateBag['timers'][$nodeId]
            : [];
        $startedAt = max(0, (int)($timerState['started_at'] ?? 0));
        if ($startedAt === 0) {
            $startedAt = time();
        }
        $deadlineAt = max($startedAt, (int)($timerState['deadline_at'] ?? ($startedAt + $timerSeconds)));
        $remainingSeconds = max(0, $deadlineAt - time());

        return [
            'seconds' => $timerSeconds,
            'started_at' => $startedAt,
            'deadline_at' => $deadlineAt,
            'remaining_seconds' => $remainingSeconds,
            'expired' => $remainingSeconds === 0,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>|null
     */
    private function buildSimulatorPayload(array $node, array $stateBag): ?array {
        $simulator = $this->getNodeSimulator($node);
        if ($simulator === null) {
            return null;
        }

        $nodeId = (string)($node['id'] ?? '');
        $simulatorState = is_array($stateBag['simulators'][$nodeId] ?? null)
            ? $stateBag['simulators'][$nodeId]
            : [];

        return [
            'type' => (string)($simulator['type'] ?? ''),
            'scenario' => (string)($simulator['scenario'] ?? ''),
            'pass_flag' => (string)($simulator['pass_flag'] ?? ''),
            'completed' => (bool)($simulatorState['completed'] ?? false),
            'passed' => (bool)($simulatorState['passed'] ?? false),
            'score' => isset($simulatorState['score']) ? (int)$simulatorState['score'] : null,
            'result' => $simulatorState['result'] ?? null,
            'updated_at' => isset($simulatorState['updated_at']) ? (int)$simulatorState['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>|null
     */
    private function buildBotCorrectionPayload(array $node, array $stateBag): ?array {
        if ((string)($node['type'] ?? '') !== 'bot_correction') {
            return null;
        }

        $nodeId = (string)($node['id'] ?? '');
        $correctionState = is_array($stateBag['bot_corrections'][$nodeId] ?? null)
            ? $stateBag['bot_corrections'][$nodeId]
            : [];
        $scenario = is_array($correctionState['scenario'] ?? null)
            ? $correctionState['scenario']
            : $this->dauBotService->buildBotCorrectionScenario($node, $stateBag);

        $publicScenario = $this->dauBotService->toPublicBotCorrectionScenario($scenario);
        $publicScenario['completed'] = (bool)($correctionState['completed'] ?? false);
        $publicScenario['passed'] = (bool)($correctionState['passed'] ?? false);
        $publicScenario['submitted_error_id'] = $correctionState['submitted_error_id'] ?? null;
        $publicScenario['submitted_fix_id'] = $correctionState['submitted_fix_id'] ?? null;

        return $publicScenario;
    }

    /**
     * Find a node by ID in the graph.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @return array<string, mixed>
     * @throws \RuntimeException if node not found
     */
    public function findNode(array $graph, string $nodeId): array {
        foreach ($graph['nodes'] as $node) {
            if ((string)$node['id'] === $nodeId) {
                return $node;
            }
        }
        throw new \RuntimeException("Node not found: {$nodeId}");
    }

    /**
     * Get edges from a node that satisfy their conditions against the state-bag.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @param array<string, mixed> $stateBag
     * @return list<array<string, mixed>>
     */
    public function getAvailableEdges(
        array $graph,
        string $fromNodeId,
        array $stateBag,
        ?int $currentActNumber = null,
        ?string $characterClass = null
    ): array {
        $available = [];
        foreach ($graph['edges'] as $edge) {
            if ((string)($edge['from'] ?? '') !== $fromNodeId) {
                continue;
            }
            if (!$this->isRoleAllowed($edge, $characterClass)) {
                continue;
            }

            $conditions = $edge['conditions'] ?? [];
            if ($this->evaluateConditions($conditions, $stateBag)) {
                $targetNodeId = (string)($edge['to'] ?? '');
                $targetNode = $this->findNode($graph, $targetNodeId);
                if (!$this->isRoleAllowed($targetNode, $characterClass)) {
                    continue;
                }
                $targetActNumber = $this->getNodeActNumber($graph, $targetNodeId);
                if ($currentActNumber !== null
                    && !$this->isAllowedActTransition($currentActNumber, $targetActNumber)) {
                    continue;
                }
                $available[] = [
                    'id' => (string)($edge['id'] ?? ''),
                    'from' => (string)($edge['from'] ?? ''),
                    'to' => $targetNodeId,
                    'label' => (string)($edge['label'] ?? ''),
                    'conditions' => $conditions,
                    'to_act' => $targetActNumber,
                    'role_filter' => $this->normalizeRoleFilter($edge['role_filter'] ?? []),
                ];
            }
        }
        return $available;
    }

    /**
     * Evaluate all conditions against a state-bag (AND logic).
     *
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $stateBag
     */
    public function evaluateConditions(array $conditions, array $stateBag): bool {
        if (empty($conditions)) {
            return true;
        }

        if (isset($conditions['requires_flag'])) {
            $flag = (string)$conditions['requires_flag'];
            if (empty($stateBag['flags'][$flag])) {
                return false;
            }
        }

        if (isset($conditions['requires_item'])) {
            $item = (string)$conditions['requires_item'];
            if (!in_array($item, $stateBag['items'] ?? [], true)) {
                return false;
            }
        }

        if (isset($conditions['min_reputation']) && is_array($conditions['min_reputation'])) {
            foreach ($conditions['min_reputation'] as $domain => $minValue) {
                $current = $stateBag['reputation'][$domain] ?? 0;
                if ($current < $minValue) {
                    return false;
                }
            }
        }

        if (isset($conditions['max_reputation']) && is_array($conditions['max_reputation'])) {
            foreach ($conditions['max_reputation'] as $domain => $maxValue) {
                $current = $stateBag['reputation'][$domain] ?? 0;
                if ($current > $maxValue) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Apply effects to a state-bag, returning a NEW bag (immutable).
     *
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    public function applyEffects(array $effects, array $stateBag): array {
        // Deep-copy to ensure immutability
        /** @var array<string, mixed> $bag */
        $bag = json_decode(json_encode($stateBag), true);
        if (!is_array($bag)) {
            $bag = ['flags' => [], 'items' => [], 'reputation' => []];
        }
        if (!isset($bag['flags']) || !is_array($bag['flags'])) {
            $bag['flags'] = [];
        }
        if (!isset($bag['items']) || !is_array($bag['items'])) {
            $bag['items'] = [];
        }
        if (!isset($bag['reputation']) || !is_array($bag['reputation'])) {
            $bag['reputation'] = [];
        }

        if (isset($effects['set_flag'])) {
            $bag['flags'][(string)$effects['set_flag']] = true;
        }

        if (isset($effects['remove_flag'])) {
            unset($bag['flags'][(string)$effects['remove_flag']]);
        }

        if (isset($effects['add_item'])) {
            $item = (string)$effects['add_item'];
            if (!in_array($item, $bag['items'], true)) {
                $bag['items'][] = $item;
            }
        }

        if (isset($effects['remove_item'])) {
            $item = (string)$effects['remove_item'];
            $bag['items'] = array_values(array_filter(
                $bag['items'],
                static fn($i) => $i !== $item
            ));
        }

        if (isset($effects['add_reputation']) && is_array($effects['add_reputation'])) {
            foreach ($effects['add_reputation'] as $domain => $delta) {
                $bag['reputation'][$domain] = ($bag['reputation'][$domain] ?? 0) + (int)$delta;
            }
        }

        // Log unknown effect keys for debugging
        $knownEffects = ['set_flag', 'remove_flag', 'add_item', 'remove_item', 'add_reputation'];
        $unknownKeys = array_diff(array_keys($effects), $knownEffects);
        if (!empty($unknownKeys)) {
            $this->logger->warning('CampaignGraphService: unknown effect keys: ' . implode(', ', $unknownKeys));
        }

        return $bag;
    }

    /**
     * Build the enriched graph response payload for the current node.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function buildGraphResponse(CampaignState $state, array $graph, array $node, array $stateBag): array {
        $currentActNumber = max(
            1,
            $state->getActNumber() > 0
                ? $state->getActNumber()
                : $this->getNodeActNumber($graph, (string)($node['id'] ?? ''))
        );
        $characterClass = $state->getCharacterClass();

        return [
            'state' => $this->serializeState($state),
            'node' => $node,
            'available_edges' => $this->getAvailableEdges(
                $graph,
                (string)($node['id'] ?? ''),
                $stateBag,
                $currentActNumber,
                $characterClass
            ),
            'timer' => $this->buildTimerPayload($node, $stateBag),
            'simulator' => $this->buildSimulatorPayload($node, $stateBag),
            'dau_bot' => $this->dauBotService->buildSceneSuggestion($node, $stateBag),
            'bot_correction' => $this->buildBotCorrectionPayload($node, $stateBag),
        ];
    }

    /**
     * Traverse an edge: validate, apply effects, update DB, return new state.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @param array<string, mixed> $campaignData
     * @return array{state: array<string, mixed>, node: array<string, mixed>, available_edges: list<array<string, mixed>>}
     * @throws \RuntimeException on invalid traversal
     */
    public function traverseEdge(
        string $userId,
        string $campaignId,
        string $edgeId,
        array $graph,
        array $campaignData,
        array $interactionContext = []
    ): array {
        $state = $this->stateMapper->findByUserAndCampaign($userId, $campaignId);
        if ($state === null) {
            throw new \RuntimeException("No campaign state found for user {$userId}, campaign {$campaignId}");
        }
        $characterClass = $state->getCharacterClass();
        $currentNode = $this->findNode($graph, $state->getGraphPosition());
        $this->assertNodeAccessible($currentNode, $characterClass);

        // Find the edge
        $targetEdge = null;
        foreach ($graph['edges'] as $edge) {
            if ((string)($edge['id'] ?? '') === $edgeId) {
                $targetEdge = $edge;
                break;
            }
        }
        if ($targetEdge === null) {
            throw new \RuntimeException("Edge not found: {$edgeId}");
        }

        // Validate edge starts at current position
        if ((string)($targetEdge['from'] ?? '') !== $state->getGraphPosition()) {
            throw new \RuntimeException("Edge {$edgeId} does not start at current position {$state->getGraphPosition()}");
        }

        // Validate conditions
        $currentBag = $this->applyNodeInteractionContext(
            $currentNode,
            $state->getStateBagDecoded(),
            $interactionContext
        );
        $conditions = $targetEdge['conditions'] ?? [];
        if (!$this->isRoleAllowed($targetEdge, $characterClass)) {
            throw new \RuntimeException("Character class '{$characterClass}' cannot use edge '{$edgeId}'");
        }
        if (!$this->evaluateConditions($conditions, $currentBag)) {
            throw new \RuntimeException("Conditions not met for edge {$edgeId}");
        }

        // Find target node
        $targetNodeId = (string)($targetEdge['to'] ?? '');
        $targetNode = $this->findNode($graph, $targetNodeId);
        $this->assertNodeAccessible($targetNode, $characterClass);
        $currentActNumber = max(
            1,
            $state->getActNumber() > 0
                ? $state->getActNumber()
                : $this->getNodeActNumber($graph, $state->getGraphPosition())
        );
        $targetActNumber = $this->getNodeActNumber($graph, $targetNodeId);

        if (!$this->isAllowedActTransition($currentActNumber, $targetActNumber)) {
            throw new \RuntimeException(
                "Edge {$edgeId} would skip act order ({$currentActNumber} -> {$targetActNumber})"
            );
        }

        // Apply node effects if any (effects is a list of individual effect objects)
        $newBag = $currentBag;
        if (isset($targetNode['effects']) && is_array($targetNode['effects'])) {
            foreach ($targetNode['effects'] as $effect) {
                if (is_array($effect)) {
                    $newBag = $this->applyEffects($effect, $newBag);
                }
            }
        }
        $newBag = $this->initializeNodeRuntimeState($targetNode, $newBag);

        // Update state
        $state->setGraphPosition($targetNodeId);
        $state->setStateBagFromArray($newBag);
        $state->setActNumber($targetActNumber);
        $state->setUpdatedAt(time());
        $this->stateMapper->update($state);

        return $this->buildGraphResponse($state, $graph, $targetNode, $newBag);
    }

    /**
     * Initialize a new graph session for a user+campaign.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @return array{state: array<string, mixed>, node: array<string, mixed>, available_edges: list<array<string, mixed>>}
     */
    public function initGraphSession(string $userId, string $campaignId, string $characterClass, array $graph): array {
        // Delete existing state if any
        $existing = $this->stateMapper->findByUserAndCampaign($userId, $campaignId);
        if ($existing !== null) {
            $this->stateMapper->delete($existing);
        }

        $startNodeId = $this->getStartNodeId($graph);
        $startNode = $this->findNode($graph, $startNodeId);
        $this->assertNodeAccessible($startNode, $characterClass);

        $emptyBag = ['flags' => [], 'items' => [], 'reputation' => []];

        // Apply start node effects if any (effects is a list of individual effect objects)
        $bag = $emptyBag;
        if (isset($startNode['effects']) && is_array($startNode['effects'])) {
            foreach ($startNode['effects'] as $effect) {
                if (is_array($effect)) {
                    $bag = $this->applyEffects($effect, $bag);
                }
            }
        }
        $bag = $this->initializeNodeRuntimeState($startNode, $bag);

        $now = time();
        $state = new CampaignState();
        $state->setUserId($userId);
        $state->setCampaignId($campaignId);
        $state->setGraphPosition($startNodeId);
        $state->setStateBagFromArray($bag);
        $state->setActNumber($this->getNodeActNumber($graph, $startNodeId));
        $state->setStatus('in_progress');
        $state->setCharacterClass($characterClass);
        $state->setScore(0);
        $state->setCreatedAt($now);
        $state->setUpdatedAt($now);

        /** @var CampaignState $state */
        $state = $this->stateMapper->insert($state);

        return $this->buildGraphResponse($state, $graph, $startNode, $bag);
    }

    /**
     * Get the current graph scene for a user+campaign.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @return array{state: array<string, mixed>, node: array<string, mixed>, available_edges: list<array<string, mixed>>}
     * @throws \RuntimeException if no state found
     */
    public function getGraphScene(string $userId, string $campaignId, array $graph): array {
        $state = $this->stateMapper->findByUserAndCampaign($userId, $campaignId);
        if ($state === null) {
            throw new \RuntimeException("No campaign state found for user {$userId}, campaign {$campaignId}");
        }

        $currentBag = $state->getStateBagDecoded();
        $currentNode = $this->findNode($graph, $state->getGraphPosition());
        $this->assertNodeAccessible($currentNode, $state->getCharacterClass());
        $preparedBag = $this->initializeNodeRuntimeState($currentNode, $currentBag);

        if ($preparedBag !== $currentBag) {
            $state->setStateBagFromArray($preparedBag);
            $state->setUpdatedAt(time());
            $this->stateMapper->update($state);
            $currentBag = $preparedBag;
        }

        return $this->buildGraphResponse($state, $graph, $currentNode, $currentBag);
    }

    /**
     * Return only the serialized graph state for save/resume API calls.
     *
     * @return array<string, mixed>
     */
    public function getGraphState(string $userId, string $campaignId): array {
        $state = $this->stateMapper->findByUserAndCampaign($userId, $campaignId);
        if ($state === null) {
            throw new \RuntimeException("No campaign state found for user {$userId}, campaign {$campaignId}");
        }

        return $this->serializeState($state);
    }

    /**
     * Serialize a CampaignState entity to an associative array.
     *
     * @return array<string, mixed>
     */
    private function serializeState(CampaignState $state): array {
        return [
            'id' => $state->getId(),
            'user_id' => $state->getUserId(),
            'campaign_id' => $state->getCampaignId(),
            'graph_position' => $state->getGraphPosition(),
            'state_bag' => $state->getStateBagDecoded(),
            'act_number' => $state->getActNumber(),
            'status' => $state->getStatus(),
            'character_class' => $state->getCharacterClass(),
            'choices' => $state->getChoicesDecoded(),
            'score' => $state->getScore(),
            'created_at' => $state->getCreatedAt(),
            'updated_at' => $state->getUpdatedAt(),
        ];
    }
}
