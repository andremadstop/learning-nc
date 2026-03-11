<?php
namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;

class RoleService {
    private IGroupManager $groupManager;
    private IConfig $config;
    private IDBConnection $db;
    private string $appName;

    public function __construct(
        IGroupManager $groupManager,
        IConfig $config,
        IDBConnection $db,
        string $appName
    ) {
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->db = $db;
        $this->appName = $appName;
    }

    public function getInstructorGroup(): string {
        return $this->config->getAppValue($this->appName, 'instructor_group', 'learning-instructors');
    }

    public function isInstructor(string $userId): bool {
        $group = $this->getInstructorGroup();
        if ($this->groupManager->isInGroup($userId, $group)) {
            return true;
        }

        // Security default: global instructor privileges are group-based only.
        // Optional fallback can be enabled explicitly for legacy setups.
        if ($this->config->getAppValue($this->appName, 'allow_course_instructor_fallback', 'no') !== 'yes') {
            return false;
        }

        // Optional fallback: treat users as instructor if they are instructor in any course
        // (owner via courses.instructor_id or member role "instructor").
        try {
            $qb = $this->db->getQueryBuilder();
            $expr = $qb->expr();
            $qb->select('c.id')
                ->from('learning_courses', 'c')
                ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
                    $expr->eq('cm.course_id', 'c.id'),
                    $expr->eq('cm.user_id', $qb->createNamedParameter($userId)),
                    $expr->eq('cm.role', $qb->createNamedParameter('instructor'))
                ))
                ->where($expr->orX(
                    $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
                    $expr->isNotNull('cm.id')
                ))
                ->setMaxResults(1);
            $result = $qb->execute();
            $row = $result->fetch();
            $result->closeCursor();
            return $row !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getRole(string $userId): string {
        return $this->isInstructor($userId) ? 'instructor' : 'student';
    }
}
