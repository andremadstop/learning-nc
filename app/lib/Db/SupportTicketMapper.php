<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

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

    public function findRecent(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function findByRoutingTarget(string $targetType, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter($targetType)))
            ->orderBy('needs_review', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Find tickets marked as needs_review (Bug/Feature tickets awaiting admin triage).
     * Sorted by creation date descending.
     */
    public function findNeedsReview(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('needs_review', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function findByInstructorCourse(int $courseId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('routing_target_type', $qb->createNamedParameter('course_instructor')))
            ->andWhere($qb->expr()->eq('routing_course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
