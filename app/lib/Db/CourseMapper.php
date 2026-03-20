<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CourseMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_courses', Course::class);
    }

    public function findById(int $id): Course {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    public function findByInstructor(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('instructor_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findByNcGroup(string $groupId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nc_group_id', $qb->createNamedParameter($groupId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
        return $this->findEntities($qb);
    }
}
