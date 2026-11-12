<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class GameshowSessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_gameshow_sessions', GameshowSession::class);
    }

    public function findById(int $id): GameshowSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findByCode(string $code): GameshowSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }

    /**
     * Claim the move from question $fromIndex to $fromIndex + 1 (compare-and-swap).
     *
     * Every poll (SSE or GET) and every answer can find a question resolved at the same time.
     * Only the request whose UPDATE still sees $fromIndex on an active session wins and may score
     * the question, record missed answers and finish the game; every other request gets false.
     * The UPDATE always changes current_question_index, so MySQL's "changed rows" count equals
     * the matched rows here.
     */
    public function claimAdvance(int $sessionId, int $fromIndex, int $questionStartedAt): bool {
        $qb = $this->db->getQueryBuilder();
        $affected = $qb->update($this->getTableName())
           ->set('current_question_index', $qb->createNamedParameter($fromIndex + 1, IQueryBuilder::PARAM_INT))
           ->set('question_started_at', $qb->createNamedParameter($questionStartedAt, IQueryBuilder::PARAM_INT))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('current_question_index', $qb->createNamedParameter($fromIndex, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
           ->executeStatement();
        return $affected === 1;
    }

    /**
     * Find finished sessions where a user participated (not removed).
     * @return GameshowSession[]
     */
    public function findFinishedByUser(string $userId, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*')
           ->from($this->getTableName(), 's')
           ->innerJoin('s', 'learning_gameshow_players', 'p', $qb->expr()->eq('p.session_id', 's.id'))
           ->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('p.is_removed', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
           ->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter('finished')))
           ->orderBy('s.created_at', 'DESC')
           ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Find sessions waiting for players in a specific course.
     * @return GameshowSession[]
     */
    public function findOpenByCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('waiting')))
           ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }
}
