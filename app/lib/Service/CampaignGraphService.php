<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CampaignState;
use OCA\Learning\Db\CampaignStateMapper;
use Psr\Log\LoggerInterface;

class CampaignGraphService {
    private CampaignStateMapper $stateMapper;
    private LoggerInterface $logger;

    public function __construct(
        CampaignStateMapper $stateMapper,
        LoggerInterface $logger
    ) {
        $this->stateMapper = $stateMapper;
        $this->logger = $logger;
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
    public function getAvailableEdges(array $graph, string $fromNodeId, array $stateBag): array {
        $available = [];
        foreach ($graph['edges'] as $edge) {
            if ((string)($edge['from'] ?? '') !== $fromNodeId) {
                continue;
            }
            $conditions = $edge['conditions'] ?? [];
            if ($this->evaluateConditions($conditions, $stateBag)) {
                $available[] = [
                    'id' => (string)($edge['id'] ?? ''),
                    'from' => (string)($edge['from'] ?? ''),
                    'to' => (string)($edge['to'] ?? ''),
                    'label' => (string)($edge['label'] ?? ''),
                    'conditions' => $conditions,
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
     * Traverse an edge: validate, apply effects, update DB, return new state.
     *
     * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     * @param array<string, mixed> $campaignData
     * @return array{state: array<string, mixed>, node: array<string, mixed>, available_edges: list<array<string, mixed>>}
     * @throws \RuntimeException on invalid traversal
     */
    public function traverseEdge(string $userId, string $campaignId, string $edgeId, array $graph, array $campaignData): array {
        $state = $this->stateMapper->findByUserAndCampaign($userId, $campaignId);
        if ($state === null) {
            throw new \RuntimeException("No campaign state found for user {$userId}, campaign {$campaignId}");
        }

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
        $currentBag = $state->getStateBagDecoded();
        $conditions = $targetEdge['conditions'] ?? [];
        if (!$this->evaluateConditions($conditions, $currentBag)) {
            throw new \RuntimeException("Conditions not met for edge {$edgeId}");
        }

        // Find target node
        $targetNodeId = (string)($targetEdge['to'] ?? '');
        $targetNode = $this->findNode($graph, $targetNodeId);

        // Apply node effects if any
        $newBag = $currentBag;
        if (isset($targetNode['effects']) && is_array($targetNode['effects'])) {
            $newBag = $this->applyEffects($targetNode['effects'], $newBag);
        }

        // Update state
        $state->setGraphPosition($targetNodeId);
        $state->setStateBagFromArray($newBag);
        if (isset($targetNode['act_number'])) {
            $state->setActNumber((int)$targetNode['act_number']);
        }
        $state->setUpdatedAt(time());
        $this->stateMapper->update($state);

        $availableEdges = $this->getAvailableEdges($graph, $targetNodeId, $newBag);

        return [
            'state' => $this->serializeState($state),
            'node' => $targetNode,
            'available_edges' => $availableEdges,
        ];
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

        $emptyBag = ['flags' => [], 'items' => [], 'reputation' => []];

        // Apply start node effects if any
        $bag = $emptyBag;
        if (isset($startNode['effects']) && is_array($startNode['effects'])) {
            $bag = $this->applyEffects($startNode['effects'], $emptyBag);
        }

        $now = time();
        $state = new CampaignState();
        $state->setUserId($userId);
        $state->setCampaignId($campaignId);
        $state->setGraphPosition($startNodeId);
        $state->setStateBagFromArray($bag);
        $state->setActNumber(1);
        $state->setStatus('in_progress');
        $state->setCharacterClass($characterClass);
        $state->setScore(0);
        $state->setCreatedAt($now);
        $state->setUpdatedAt($now);

        /** @var CampaignState $state */
        $state = $this->stateMapper->insert($state);

        $availableEdges = $this->getAvailableEdges($graph, $startNodeId, $bag);

        return [
            'state' => $this->serializeState($state),
            'node' => $startNode,
            'available_edges' => $availableEdges,
        ];
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
        $availableEdges = $this->getAvailableEdges($graph, $state->getGraphPosition(), $currentBag);

        return [
            'state' => $this->serializeState($state),
            'node' => $currentNode,
            'available_edges' => $availableEdges,
        ];
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
