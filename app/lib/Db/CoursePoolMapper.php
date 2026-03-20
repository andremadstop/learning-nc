<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CoursePoolMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_course_pools', CoursePool::class);
    }

    public function findByCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('sort_order', 'ASC');
        return $this->findEntities($qb);
    }

    public function findByCourseAndPool(int $courseId, int $poolId): CoursePool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
        return $this->findEntity($qb);
    }
}
