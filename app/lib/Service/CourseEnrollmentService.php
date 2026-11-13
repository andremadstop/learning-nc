<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\DB\Exception as DBException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Enrolment of course members by instructors (GitHub #35).
 *
 * - Every stored user id is the account's canonical uid. Nextcloud resolves user ids
 *   case-insensitively, but every membership lookup compares them exactly, so a row stored as
 *   typed ("Alice" for "alice") was a membership the learner never saw.
 * - Groups are enrolled as a snapshot: whoever is in the group right now. Nobody is added later.
 * - The picker searches through Nextcloud's collaborator search, so the instance's sharing and
 *   enumeration rules apply exactly as in the share dialog.
 * - Enrolling a group is checked on the server again, and STRICTER than sharing with it: sharing
 *   never reveals who is in a group, enrolling does (the result and the member list name every
 *   member). So whenever the instance restricts sharing or enumeration ("share only with own
 *   groups", enumeration off, enumeration limited to own groups or to the phone book), a
 *   non-admin may only enrol groups they are in themselves. Typed accounts follow "share only
 *   with own groups": an account outside the caller's groups is reported as not found.
 * - Every insert stands on its own and the result names each account, so a partial failure is
 *   reported as such and never as success. There is deliberately no surrounding transaction: on
 *   PostgreSQL a unique violation (a concurrent enrolment) would poison it and make the commit a
 *   silent rollback.
 *
 * @phpstan-type Entry array{user_id: string, display_name: string}
 * @phpstan-type RoleEntry array{user_id: string, display_name: string, role: string}
 * @phpstan-type EnrolResult array{added: list<Entry>, already: list<Entry>, roleUnchanged: list<RoleEntry>, notFound: list<string>, groupsUnavailable: list<string>, failed: list<Entry>}
 */
class CourseEnrollmentService {
    public const ROLES = ['student', 'instructor'];
    public const MAX_USER_IDS = 200;
    public const MAX_GROUP_IDS = 20;
    public const MAX_ENROLMENTS = 1000;
    public const SEARCH_LIMIT = 25;
    private const MAX_SEARCH_LENGTH = 100;
    private const MAX_UID_LENGTH = 64;

    public function __construct(
        private CourseService $courseService,
        private CourseMemberMapper $courseMemberMapper,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IShareManager $shareManager,
        private ISearch $collaboratorSearch,
        private INotificationManager $notificationManager,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enrol one account, or change the role of an existing member (the members tab's role toggle
     * posts here too).
     *
     * @return array{member: CourseMember, created: bool}
     * @throws DoesNotExistException   the course does not exist
     * @throws ForbiddenException      the caller is not an instructor of the course
     * @throws MemberNotFoundException no account with that user id
     * @throws AlreadyMemberException  already a member with that role
     */
    public function addMember(int $courseId, string $typedUid, string $role, string $actorId): array {
        $course = $this->courseService->assertInstructorOfCourse($courseId, $actorId);

        if (!in_array($role, self::ROLES, true)) {
            $role = 'student';
        }

        $uid = $this->canonicalUid($typedUid);
        if ($uid === null) {
            throw new MemberNotFoundException('User does not exist');
        }

        // An existing member's role can always be changed: the instructor sees the member list
        // anyway, so this reveals nothing.
        try {
            $existing = $this->courseMemberMapper->findByCourseAndUser($courseId, $uid);
            if ($existing->getRole() === $role) {
                throw new AlreadyMemberException('Already a member');
            }
            $existing->setRole($role);
            /** @var CourseMember $updated */
            $updated = $this->courseMemberMapper->update($existing);
            return ['member' => $updated, 'created' => false];
        } catch (DoesNotExistException $e) {
            // not a member yet
        }

        // The course owner is a member by definition; a second row would only list them as a student.
        if ($uid === $course->getInstructorId()) {
            throw new AlreadyMemberException('Course owner');
        }

        // A new member must be someone the caller could share with; otherwise the account looks
        // exactly like a missing one.
        if (!$this->mayEnrolUser($uid, $actorId)) {
            throw new MemberNotFoundException('User does not exist');
        }

        try {
            $member = $this->insertMember($courseId, $uid, $role);
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new AlreadyMemberException('Already a member');
            }
            throw $e;
        }

        if ($uid !== $actorId) {
            $this->notifyEnrolled($course, $uid);
        }

        return ['member' => $member, 'created' => true];
    }

    /**
     * Enrol several accounts and the current members of several groups at once.
     *
     * Existing members keep their role: adding a group as students must never demote an
     * instructor who happens to be in it.
     *
     * @param list<mixed> $userIds
     * @param list<mixed> $groupIds
     * @return EnrolResult
     * @throws DoesNotExistException      the course does not exist
     * @throws ForbiddenException         the caller is not an instructor of the course
     * @throws \InvalidArgumentException  bad role, nothing selected, or over a limit
     */
    public function bulkAdd(int $courseId, array $userIds, array $groupIds, string $role, string $actorId): array {
        $course = $this->courseService->assertInstructorOfCourse($courseId, $actorId);

        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('invalid_role');
        }
        $userIds = $this->normalizeIds($userIds);
        $groupIds = $this->normalizeIds($groupIds);
        if ($userIds === [] && $groupIds === []) {
            throw new \InvalidArgumentException('nothing_selected');
        }
        if (count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException('too_many_users');
        }
        if (count($groupIds) > self::MAX_GROUP_IDS) {
            throw new \InvalidArgumentException('too_many_groups');
        }

        $result = $this->emptyResult();
        $members = $this->memberRoles($course);
        // uid => true when the caller picked this account by name, false when only through a group
        $targets = [];
        foreach ($userIds as $typed) {
            $uid = $this->canonicalUid($typed);
            if ($uid === null || (!isset($members[$uid]) && !$this->mayEnrolUser($uid, $actorId))) {
                $result['notFound'][] = $typed;
                continue;
            }
            $targets[$uid] = true;
        }
        foreach ($groupIds as $gid) {
            $group = $this->usableGroup($gid, $actorId);
            if ($group === null) {
                $result['groupsUnavailable'][] = $gid;
                continue;
            }
            // Bounded: never load more of a huge group than could be enrolled anyway. The bound is
            // the fixed limit, never "what is left": a group overlapping earlier picks would fill a
            // smaller slice with people already collected and silently drop the rest of it.
            $groupUids = $this->groupMemberUids($group, self::MAX_ENROLMENTS);
            if (count($groupUids) > self::MAX_ENROLMENTS) {
                throw new \InvalidArgumentException('too_many');
            }
            foreach ($groupUids as $uid) {
                $targets[$uid] = $targets[$uid] ?? false;
            }
            if (count($targets) > self::MAX_ENROLMENTS) {
                throw new \InvalidArgumentException('too_many');
            }
        }

        // strval: PHP turns numeric-string array keys ("1234") into integers.
        $explicit = [];
        foreach ($targets as $uid => $picked) {
            if ($picked) {
                $explicit[(string)$uid] = true;
            }
        }
        return $this->enrolTargets($course, array_map('strval', array_keys($targets)), $role, $actorId, $result, $members, $explicit);
    }

    /**
     * Pre-flight for the course form, run BEFORE the course is saved: throws unless the caller may
     * enrol this group (it exists, the rules let them use it) and it is small enough to be
     * enrolled in one step. Returns the group's canonical id, which is what gets stored: group
     * backends may match ids case-insensitively, the self-enrolment check compares exactly.
     *
     * @throws GroupUnavailableException
     * @throws \InvalidArgumentException 'too_many': more members than one enrolment may add
     */
    public function assertGroupEnrollable(string $groupId, string $actorId): string {
        $group = $this->usableGroup($groupId, $actorId);
        if ($group === null) {
            throw new GroupUnavailableException([$groupId]);
        }
        if (count($this->groupMemberUids($group, self::MAX_ENROLMENTS)) > self::MAX_ENROLMENTS) {
            throw new \InvalidArgumentException('too_many');
        }
        return $group->getGID();
    }

    /**
     * Enrol everyone who is in the group right now as a student. The caller must already have
     * checked that $actorId may manage the course (course create/update).
     *
     * @return EnrolResult
     * @throws GroupUnavailableException
     * @throws \InvalidArgumentException the group is larger than one enrolment may be
     */
    public function enrolGroupSnapshot(Course $course, string $groupId, string $actorId): array {
        $group = $this->usableGroup($groupId, $actorId);
        if ($group === null) {
            throw new GroupUnavailableException([$groupId]);
        }
        $uids = $this->groupMemberUids($group, self::MAX_ENROLMENTS);
        if (count($uids) > self::MAX_ENROLMENTS) {
            throw new \InvalidArgumentException('too_many');
        }
        return $this->enrolTargets($course, $uids, 'student', $actorId, $this->emptyResult(), $this->memberRoles($course), []);
    }

    /**
     * Find accounts and groups for the member picker through Nextcloud's collaborator search, so
     * the instance's enumeration and sharing restrictions apply exactly as in the share dialog.
     *
     * @return array{users: list<array{id: string, label: string, is_member: bool}>, groups: list<array{id: string, label: string}>}
     * @throws DoesNotExistException the course does not exist
     * @throws ForbiddenException    the caller is not an instructor of the course
     */
    public function search(int $courseId, string $term, string $actorId): array {
        $course = $this->courseService->assertInstructorOfCourse($courseId, $actorId);

        $term = trim($term);
        if ($term === '') {
            return ['users' => [], 'groups' => []];
        }
        $term = mb_substr($term, 0, self::MAX_SEARCH_LENGTH);

        [$raw] = $this->collaboratorSearch->search(
            $term,
            [IShare::TYPE_USER, IShare::TYPE_GROUP],
            false,
            self::SEARCH_LIMIT,
            0
        );

        $memberUids = $this->memberRoles($course);

        $users = [];
        foreach ($this->entries($raw, 'users') as $entry) {
            $id = $this->entryId($entry, IShare::TYPE_USER);
            if ($id === null || isset($users[$id])) {
                continue;
            }
            $users[$id] = [
                'id' => $id,
                'label' => is_string($entry['label'] ?? null) && $entry['label'] !== '' ? $entry['label'] : $id,
                'is_member' => isset($memberUids[$id]),
            ];
        }

        $groups = [];
        foreach ($this->entries($raw, 'groups') as $entry) {
            $id = $this->entryId($entry, IShare::TYPE_GROUP);
            if ($id === null || isset($groups[$id])) {
                continue;
            }
            // Offer only groups that enrolment will accept (its rule is stricter than sharing).
            if ($this->usableGroup($id, $actorId) === null) {
                continue;
            }
            $groups[$id] = [
                'id' => $id,
                'label' => is_string($entry['label'] ?? null) && $entry['label'] !== '' ? $entry['label'] : $id,
            ];
        }

        return [
            'users' => array_slice(array_values($users), 0, self::SEARCH_LIMIT),
            'groups' => array_slice(array_values($groups), 0, self::SEARCH_LIMIT),
        ];
    }

    /**
     * The account's canonical uid, or null when there is no such account.
     */
    public function canonicalUid(string $typed): ?string {
        $typed = trim($typed);
        if ($typed === '' || strlen($typed) > self::MAX_UID_LENGTH) {
            return null;
        }
        $user = $this->userManager->get($typed);
        return $user === null ? null : $user->getUID();
    }

    /**
     * Existing members keep their role. Someone the caller picked by name whose role differs from
     * the requested one is reported in roleUnchanged (with the role they keep), so the instructor
     * is told the change did not happen; people who only came in through a group are simply
     * "already" members.
     *
     * @param list<string> $uids
     * @param EnrolResult $result
     * @param array<string, string> $memberUids uid => current role
     * @param array<string, true> $explicit uids picked by name
     * @return EnrolResult
     */
    private function enrolTargets(Course $course, array $uids, string $role, string $actorId, array $result, array $memberUids, array $explicit): array {
        $courseId = (int)$course->getId();

        foreach ($uids as $uid) {
            $entry = ['user_id' => $uid, 'display_name' => $this->displayName($uid)];
            if (isset($memberUids[$uid])) {
                if (isset($explicit[$uid]) && $memberUids[$uid] !== $role) {
                    $result['roleUnchanged'][] = $entry + ['role' => $memberUids[$uid]];
                } else {
                    $result['already'][] = $entry;
                }
                continue;
            }
            try {
                $this->insertMember($courseId, $uid, $role);
            } catch (DBException $e) {
                if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    $result['already'][] = $entry;
                    continue;
                }
                $this->logEnrolFailure($courseId, $e);
                $result['failed'][] = $entry;
                continue;
            } catch (\Throwable $e) {
                $this->logEnrolFailure($courseId, $e);
                $result['failed'][] = $entry;
                continue;
            }
            $memberUids[$uid] = $role;
            $result['added'][] = $entry;
            if ($uid !== $actorId) {
                $this->notifyEnrolled($course, $uid);
            }
        }

        return $result;
    }

    private function insertMember(int $courseId, string $uid, string $role): CourseMember {
        $member = new CourseMember();
        $member->setCourseId($courseId);
        $member->setUserId($uid);
        $member->setRole($role);
        $member->setEnrolledAt($this->timeFactory->getTime());
        /** @var CourseMember $inserted */
        $inserted = $this->courseMemberMapper->insert($member);
        return $inserted;
    }

    /**
     * May the caller enrol this group? A missing group and a forbidden one look the same.
     *
     * Like core's GroupPlugin, group sharing must be allowed and the group must not be hidden from
     * collaboration. Unlike sharing, enrolling reveals the group's members, so whenever the
     * instance restricts sharing or enumeration a non-admin must be in the group themselves (with
     * "share only with own groups", minus the admin's exclusion list, as in core).
     */
    private function usableGroup(string $groupId, string $actorId): ?IGroup {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return null;
        }
        if (!$this->shareManager->allowGroupSharing() || $group->hideFromCollaboration()) {
            return null;
        }
        $groupMembersOnly = $this->shareManager->shareWithGroupMembersOnly();
        $restricted = $groupMembersOnly
            || !$this->shareManager->allowEnumeration()
            || $this->shareManager->limitEnumerationToGroups()
            || $this->shareManager->limitEnumerationToPhone();
        if (!$restricted || $this->isAdmin($actorId)) {
            return $group;
        }
        $actor = $this->userManager->get($actorId);
        if ($actor === null) {
            return null;
        }
        $allowed = $this->groupManager->getUserGroupIds($actor);
        if ($groupMembersOnly) {
            $allowed = array_diff($allowed, $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList());
        }
        return in_array($group->getGID(), $allowed, true) ? $group : null;
    }

    /**
     * With "share only with own groups", a non-admin may only enrol accounts that share one of
     * their (not excluded) groups, as core's UserPlugin allows for sharing.
     */
    private function mayEnrolUser(string $uid, string $actorId): bool {
        if ($uid === $actorId || !$this->shareManager->shareWithGroupMembersOnly() || $this->isAdmin($actorId)) {
            return true;
        }
        $actor = $this->userManager->get($actorId);
        $target = $this->userManager->get($uid);
        if ($actor === null || $target === null) {
            return false;
        }
        $allowed = array_diff(
            $this->groupManager->getUserGroupIds($actor),
            $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList()
        );
        return array_intersect($allowed, $this->groupManager->getUserGroupIds($target)) !== [];
    }

    private function isAdmin(string $actorId): bool {
        return $actorId !== '' && $this->groupManager->isAdmin($actorId) === true;
    }

    /**
     * The group's members, loading at most $cap + 1 of them: one more than the cap is enough to
     * know the group is too large, without pulling a huge LDAP/SAML group into memory.
     *
     * @return list<string>
     */
    private function groupMemberUids(IGroup $group, int $cap): array {
        $uids = [];
        foreach ($group->searchUsers('', max(0, $cap) + 1) as $user) {
            $uids[$user->getUID()] = true;
        }
        return array_map('strval', array_keys($uids));
    }

    /**
     * Current members plus the course owner (as instructor), keyed by exact uid.
     *
     * @return array<string, string> uid => role
     */
    private function memberRoles(Course $course): array {
        $set = [];
        foreach ($this->courseMemberMapper->findByCourse((int)$course->getId()) as $member) {
            $set[(string)$member->getUserId()] = (string)$member->getRole();
        }
        $owner = $course->getInstructorId();
        if (is_string($owner) && $owner !== '') {
            $set[$owner] = 'instructor';
        }
        return $set;
    }

    private function notifyEnrolled(Course $course, string $uid): void {
        // Best-effort: the enrolment is stored either way; a broken notification backend must not
        // turn it into an apparent failure.
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('learning')
                ->setUser($uid)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject('course', (string)$course->getId())
                ->setSubject('course_enrolled', [
                    'course_title' => (string)$course->getTitle(),
                    'course_id' => (int)$course->getId(),
                ]);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Enrolment notification failed for course {course}: {msg}', [
                'app' => 'learning',
                'course' => $course->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function logEnrolFailure(int $courseId, \Throwable $e): void {
        $this->logger->error('Enrolling a member in course {course} failed: {msg}', [
            'app' => 'learning',
            'course' => $courseId,
            'msg' => $e->getMessage(),
        ]);
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        $name = $user === null ? '' : trim($user->getDisplayName());
        return $name !== '' ? $name : $uid;
    }

    /**
     * @param list<mixed> $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id !== '') {
                $out[$id] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Exact matches first, then the wide ones.
     *
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function entries(array $raw, string $type): array {
        $exact = is_array($raw['exact'] ?? null) && is_array($raw['exact'][$type] ?? null) ? $raw['exact'][$type] : [];
        $wide = is_array($raw[$type] ?? null) ? $raw[$type] : [];
        return array_values(array_filter(array_merge($exact, $wide), 'is_array'));
    }

    /** @param array<string, mixed> $entry */
    private function entryId(array $entry, int $shareType): ?string {
        $value = $entry['value'] ?? null;
        if (!is_array($value) || ($value['shareType'] ?? null) !== $shareType) {
            return null;
        }
        $id = $value['shareWith'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return EnrolResult
     */
    private function emptyResult(): array {
        return ['added' => [], 'already' => [], 'roleUnchanged' => [], 'notFound' => [], 'groupsUnavailable' => [], 'failed' => []];
    }
}
