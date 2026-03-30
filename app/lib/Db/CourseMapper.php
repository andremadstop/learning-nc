<?php
declare(strict_types=1);

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

    public function findStudentExamCourses(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.title', 'c.exam_date')
            ->from($this->getTableName(), 'c')
            ->innerJoin('c', 'learning_course_members', 'cm', $qb->expr()->eq('c.id', 'cm.course_id'))
            ->where($qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('cm.role', $qb->createNamedParameter('student')))
            ->andWhere($qb->expr()->eq('c.status', $qb->createNamedParameter('active')))
            ->andWhere($qb->expr()->isNotNull('c.exam_date'))
            ->andWhere($qb->expr()->neq('c.exam_date', $qb->createNamedParameter('')))
            ->orderBy('c.exam_date', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }
}
