<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SupportTicket> */
class SupportTicketMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_support_tickets', SupportTicket::class);
    }

    public function findById(int $id): SupportTicket {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    public function findByUser(string $userId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * GitHub #59: the questions one learner sent to the instructors of one course, newest first.
     */
    public function findByUserAndRoutingCourse(string $userId, int $courseId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('routing_target_type', $qb->createNamedParameter('course_instructor')))
            ->andWhere($qb->expr()->eq('routing_course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function findRecent(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * GitHub #59: $archived = false lists the inbox (archived tickets left out), true only the archive.
     */
    public function findByRoutingTarget(string $targetType, int $limit = 100, bool $archived = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter($targetType)))
            ->andWhere($this->archiveFilter($qb, $archived))
            ->orderBy('needs_review', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * GitHub #59: $archived = false lists the inbox (archived questions left out), true only the archive.
     */
    public function findByInstructorCourse(int $courseId, int $limit = 100, bool $archived = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter('course_instructor')))
            ->andWhere($qb->expr()->eq('routing_course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($this->archiveFilter($qb, $archived))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * GitHub #59: the unanswered, not archived questions of a course, for the badge at its
     * "Questions from learners" tab.
     */
    public function countOpenByInstructorCourse(int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter('course_instructor')))
            ->andWhere($qb->expr()->eq('routing_course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * GitHub #59: the unanswered, not archived tickets of one routing target (the admin inbox).
     */
    public function countOpenByRoutingTarget(string $targetType): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter($targetType)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * GitHub #59: a learner withdraws their own question, only while it has no answer. One
     * conditional DELETE, so an answer saved in the meantime is never deleted with it.
     *
     * @return bool whether the question was deleted
     */
    public function deleteOpenOwnQuestion(int $id, string $userId, int $courseId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('routing_target_type', $qb->createNamedParameter('course_instructor')))
            ->andWhere($qb->expr()->eq('routing_course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            // an archived question the learner still sees as open may be withdrawn as well
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'archived'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNull('answer_text'));
        return $qb->executeStatement() > 0;
    }

    /**
     * GitHub #59: an archived question becomes open or answered again. The status is derived in
     * the same UPDATE from the stored answer, so an answer saved after the instructor's read
     * still counts.
     */
    public function restoreArchived(int $id, int $updatedAt): void {
        $qb = $this->db->getQueryBuilder();
        $col = $qb->getColumnName('answer_text');
        $qb->update($this->getTableName())
            ->set('status', $qb->createFunction(
                "CASE WHEN " . $col . " IS NULL OR " . $col . " = '' THEN 'open' ELSE 'answered' END"
            ))
            ->set('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('archived')));
        $qb->executeStatement();
    }

    private function archiveFilter(IQueryBuilder $qb, bool $archived): string {
        return $archived
            ? $qb->expr()->eq('status', $qb->createNamedParameter('archived'))
            : $qb->expr()->neq('status', $qb->createNamedParameter('archived'));
    }
}
