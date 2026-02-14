<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CourseMemberMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_course_members', CourseMember::class);
    }

    public function findByCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('enrolled_at', 'ASC');
        return $this->findEntities($qb);
    }

    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    public function findByCourseAndUser(int $courseId, string $userId): CourseMember {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    public function findEnrolledCourses(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('cm.*', 'c.title', 'c.description', 'c.instructor_id', 'c.nc_group_id', 'c.status')
            ->from($this->getTableName(), 'cm')
            ->innerJoin('cm', 'learning_courses', 'c', $qb->expr()->eq('cm.course_id', 'c.id'))
            ->where($qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('c.status', $qb->createNamedParameter('active')));
        return $this->findEntities($qb);
    }

    public function countByCourse(int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
