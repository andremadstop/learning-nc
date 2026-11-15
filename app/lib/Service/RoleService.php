<?php
namespace OCA\Learning\Service;

use OCA\Learning\Db\Oversight;
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

    /** The single group of 5.11 and earlier; still the source until the lists are saved once. */
    public const LEGACY_GROUP_KEY = 'instructor_group';
    public const GROUPS_KEY = 'instructor_groups';
    public const USERS_KEY = 'instructor_users';
    public const DEFAULT_GROUP = 'learning-instructors';
    /** Nextcloud's gid and uid columns are 64 characters wide. */
    public const MAX_ID_LENGTH = 64;
    public const MAX_ENTRIES = 200;
    /** Read sentinel: tells an absent app value apart from a stored empty list. */
    private const ABSENT = "\0absent";

    /**
     * The first instructor group, '' when only people are configured. Kept for the role()
     * answer (the pool list names it as the group to ask for) and for 5.11 readers.
     */
    public function getInstructorGroup(): string {
        return $this->getInstructorGroups()[0] ?? '';
    }

    /**
     * GitHub #60: the groups whose members are instructors. Until an admin saves the new list, the
     * single 5.11 group applies unchanged; a damaged stored value falls back to that group too,
     * never to "nobody" (admins only) or to anything broader.
     *
     * @return list<string>
     */
    public function getInstructorGroups(): array {
        $stored = $this->readList(self::GROUPS_KEY);
        if ($stored !== null) {
            return $stored;
        }
        $legacy = trim($this->config->getAppValue($this->appName, self::LEGACY_GROUP_KEY, self::DEFAULT_GROUP));
        return [$legacy !== '' ? $legacy : self::DEFAULT_GROUP];
    }

    /**
     * GitHub #60: individual accounts (canonical uids) that are instructors without a group.
     *
     * @return list<string>
     */
    public function getInstructorUsers(): array {
        return $this->readList(self::USERS_KEY) ?? [];
    }

    /**
     * Stores both lists. Format is checked, existence is not: a group an LDAP backend cannot
     * resolve for a moment must not lose its rights when an admin saves an unrelated change.
     * Nothing at all keeps the documented default group, as the single field always did.
     * The first group (or '') is also written to the 5.11 key, so after a downgrade only that
     * group keeps its rights; the extra groups and people lose them rather than anyone gaining.
     *
     * @param array<mixed> $groupIds
     * @param array<mixed> $userIds
     * @return array{groups: list<string>, users: list<string>}
     */
    public function saveInstructors(array $groupIds, array $userIds): array {
        $groups = self::normalizeIds($groupIds);
        $users = self::normalizeIds($userIds);
        if ($groups === [] && $users === []) {
            $groups = [self::DEFAULT_GROUP];
        }
        $this->config->setAppValue($this->appName, self::GROUPS_KEY, (string)json_encode($groups));
        $this->config->setAppValue($this->appName, self::USERS_KEY, (string)json_encode($users));
        // '' when only people are configured: 5.11 then tests membership of no group (fail closed)
        $this->config->setAppValue($this->appName, self::LEGACY_GROUP_KEY, $groups[0] ?? '');
        return ['groups' => $groups, 'users' => $users];
    }

    /**
     * Trimmed, non-empty strings of at most MAX_ID_LENGTH characters, without duplicates, at most
     * MAX_ENTRIES. Anything else is dropped, never shortened: a cut id would name another group.
     *
     * @param array<mixed> $ids
     * @return list<string>
     */
    public static function normalizeIds(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id === '' || mb_strlen($id) > self::MAX_ID_LENGTH || in_array($id, $out, true)) {
                continue;
            }
            $out[] = $id;
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }
        return $out;
    }

    /**
     * A stored JSON list, or null when the value is absent or not a list of strings.
     *
     * @return list<string>|null
     */
    private function readList(string $key): ?array {
        $raw = $this->config->getAppValue($this->appName, $key, self::ABSENT);
        if ($raw === self::ABSENT) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }
        foreach ($decoded as $entry) {
            if (!is_string($entry)) {
                return null;
            }
        }
        return self::normalizeIds($decoded);
    }

    /**
     * Nextcloud administrator. Unlike isInstructor() this is not satisfied by membership of the
     * instructor group, so it can stand in for "may act on any course".
     */
    public function isAdmin(string $userId): bool {
        return $this->groupManager->isAdmin($userId);
    }

    public function isInstructor(string $userId): bool {
        // Nextcloud admins are always instructors
        if ($this->groupManager->isAdmin($userId)) {
            return true;
        }

        // GitHub #60: any of the configured groups, then the configured people
        foreach ($this->getInstructorGroups() as $group) {
            if ($this->groupManager->isInGroup($userId, $group)) {
                return true;
            }
        }
        if ($userId !== '' && in_array($userId, $this->getInstructorUsers(), true)) {
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
     * (courseId, leadUserId, groupId) triple in learning_oversight.
     * Delegates to OversightMapper — fail closed (false) for any mismatch.
     */
    public function isTeamLeadForGroup(int $courseId, string $leadUserId, string $groupId): bool {
        return $this->oversightMapper->existsForLeadGroupCourse($courseId, $leadUserId, $groupId);
    }

    /**
     * All (course_id, group_id) scopes this lead is authorised to view.
     * Scoped strictly to the given leadUserId — no cross-lead leakage.
     *
     * @return list<array{course_id: int, group_id: string}>
     */
    public function getTeamLeadGroups(string $leadUserId): array {
        return array_values(array_map(
            static fn(Oversight $o): array => [
                'course_id' => $o->getCourseId(),
                'group_id'  => $o->getScopeGroupId(),
            ],
            $this->oversightMapper->findByLead($leadUserId)
        ));
    }
}
