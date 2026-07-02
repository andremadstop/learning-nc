<?php
namespace OCA\Learning\Service;

use OCA\Learning\Db\OversightMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;

class RoleService {
    private IGroupManager $groupManager;
    private IConfig $config;
    private IDBConnection $db;
    private string $appName;
    private OversightMapper $oversightMapper;

    public function __construct(
        IGroupManager $groupManager,
        IConfig $config,
        IDBConnection $db,
        string $appName,
        OversightMapper $oversightMapper
    ) {
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->db = $db;
        $this->appName = $appName;
        $this->oversightMapper = $oversightMapper;
    }

    public function getInstructorGroup(): string {
        return $this->config->getAppValue($this->appName, 'instructor_group', 'learning-instructors');
    }

    public function isInstructor(string $userId): bool {
        // Nextcloud admins are always instructors
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

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
            $result = $qb->executeQuery();
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

    /**
     * True when the given lead user holds an oversight row for the exact
     * (courseId, groupId) combination.
     *
     * SKELETON — always false until 163-03 fills the real query.
     */
    public function isTeamLeadForGroup(int $courseId, string $leadUserId, string $groupId): bool {
        // SKELETON — real check in 163-03
        return false;
    }

    /**
     * All (course_id, group_id) scopes this lead is authorised to view.
     *
     * @return list<array{course_id: int, group_id: string}>
     */
    public function getTeamLeadGroups(string $leadUserId): array {
        // SKELETON — real mapping in 163-03
        return [];
    }
}
