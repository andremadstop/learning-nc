<?php
declare(strict_types=1);

namespace OCA\Learning\Listener;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes user-owned learning data after a Nextcloud account was deleted
 * and anonymizes shared knowledge that should remain in the system.
 *
 * @implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }

        $userId = $event->getUser()->getUID();
        if ($userId === '') {
            return;
        }

        $sessionIds = $this->fetchIntIdsByUserColumns('learning_sessions', ['user_id'], $userId);
        $duelIds = $this->fetchIntIdsByUserColumns('learning_duel_sessions', ['creator_uid', 'opponent_uid'], $userId);
        $hostedGameshowSessionIds = $this->fetchIntIdsByUserColumns('learning_gameshow_sessions', ['creator_uid'], $userId);

        // Delete child rows that do not reliably cascade from their parent tables.
        $this->deleteByIds('learning_user_answers', 'session_id', $sessionIds);
        $this->deleteByIds('learning_audit_events', 'session_id', $sessionIds);
        $this->deleteByIds('learning_duel_answers', 'duel_id', $duelIds);
        $this->deleteByIds('learning_duel_invites', 'duel_session_id', $duelIds);
        $this->deleteByIds('learning_gameshow_answers', 'session_id', $hostedGameshowSessionIds);
        $this->deleteByIds('learning_gameshow_players', 'session_id', $hostedGameshowSessionIds);

        foreach ([
            'learning_user_stats',
            'learning_leitner_items',
            'learning_sessions',
            'learning_user_badges',
            'learning_user_telos',
            'learning_mission_claims',
            'learning_story_progress',
            'learning_course_members',
            'learning_ai_chat_memory',
            'learning_epoch_progress',
            'learning_campaign_state',
            'learning_support_tickets',
            'learning_audit_events',
        ] as $table) {
            $this->deleteByUserColumns($table, ['user_id'], $userId);
        }

        $this->deleteByUserColumns('learning_duel_answers', ['player_uid'], $userId);
        $this->deleteByUserColumns('learning_duel_invites', ['inviter_uid', 'invitee_uid'], $userId);
        $this->deleteByUserColumns('learning_duel_sessions', ['creator_uid', 'opponent_uid'], $userId);
        $this->deleteByUserColumns('learning_league_challenges', ['challenger_uid', 'opponent_uid', 'winner_uid'], $userId);
        $this->deleteByUserColumns('learning_league_results', ['player_a_uid', 'player_b_uid', 'winner_uid'], $userId);
        $this->deleteByUserColumns('learning_gameshow_answers', ['player_uid'], $userId);
        $this->deleteByUserColumns('learning_gameshow_players', ['user_id'], $userId);
        $this->deleteByUserColumns('learning_gameshow_sessions', ['creator_uid'], $userId);
        $this->deleteByUserColumns('learning_coop_votes', ['user_id'], $userId);
        $this->deleteByUserColumns('learning_coop_players', ['user_id'], $userId);
        $this->deleteByUserColumns('learning_coop_sessions', ['host_user_id'], $userId);

        // Keep shared knowledge but remove the personal reference.
        $this->setColumnToNull('learning_support_tickets', 'answered_by', $userId);
        $this->setColumnToNull('learning_rag_chunks', 'user_id', $userId);
    }

    /**
     * @param list<string> $columns
     * @return list<int>
     */
    private function fetchIntIdsByUserColumns(string $table, array $columns, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($table)
            ->where($this->buildUserColumnExpression($qb, $columns, $userId));

        $rows = $qb->executeQuery()->fetchAll();
        $ids = [];

        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $columns
     */
    private function deleteByUserColumns(string $table, array $columns, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($table)
            ->where($this->buildUserColumnExpression($qb, $columns, $userId));
        $qb->executeStatement();
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(string $table, string $column, array $ids): void {
        if ($ids === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($table)
            ->where(
                $qb->expr()->in(
                    $column,
                    $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
                )
            );
        $qb->executeStatement();
    }

    private function setColumnToNull(string $table, string $column, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set($column, $qb->createNamedParameter(null, \PDO::PARAM_NULL))
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($userId, \PDO::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * @param list<string> $columns
     */
    private function buildUserColumnExpression(IQueryBuilder $qb, array $columns, string $userId) {
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = $qb->expr()->eq($column, $qb->createNamedParameter($userId, \PDO::PARAM_STR));
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $qb->expr()->orX(...$parts);
    }
}
