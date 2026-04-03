<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\Kudos;
use OCA\Learning\Db\KudosMapper;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

class ClassbookService {
    private const DAILY_KUDOS_LIMIT = 3;

    private CourseService $courseService;
    private CourseMemberMapper $courseMemberMapper;
    private CoursePoolMapper $coursePoolMapper;
    private UserTelosMapper $userTelosMapper;
    private KudosMapper $kudosMapper;
    private IDBConnection $db;
    private IUserManager $userManager;

    public function __construct(
        CourseService $courseService,
        CourseMemberMapper $courseMemberMapper,
        CoursePoolMapper $coursePoolMapper,
        UserTelosMapper $userTelosMapper,
        KudosMapper $kudosMapper,
        IDBConnection $db,
        IUserManager $userManager
    ) {
        $this->courseService = $courseService;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->userTelosMapper = $userTelosMapper;
        $this->kudosMapper = $kudosMapper;
        $this->db = $db;
        $this->userManager = $userManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClassbook(int $courseId, string $viewerId): array {
        $course = $this->courseService->findById($courseId, $viewerId);
        $members = $this->courseMemberMapper->findByCourse($courseId);
        $memberIds = array_map(static fn(CourseMember $member): string => $member->getUserId(), $members);
        $memberRoles = [];
        foreach ($members as $member) {
            $memberRoles[$member->getUserId()] = $member->getRole();
        }

        $telosMap = [];
        foreach ($this->userTelosMapper->findByUserIds($memberIds) as $telos) {
            $telosMap[$telos->getUserId()] = $telos;
        }

        $statsMap = $this->getUserStats($memberIds);
        $topPoolProgressMap = $this->getTopPoolProgress($courseId, $memberIds);
        $kudosCounts = $this->kudosMapper->countReceivedByCourseAndUsers($courseId, $memberIds);

        $profiles = [];
        foreach ($memberIds as $uid) {
            $telos = $telosMap[$uid] ?? null;
            $role = (string)($memberRoles[$uid] ?? 'student');
            $visibility = $telos ? $telos->getVisibility() : 'private';
            $isInstructor = $role === 'instructor';

            if (!$isInstructor && $uid !== $viewerId && $visibility === 'private') {
                continue;
            }

            $user = $this->userManager->get($uid);
            $superpower = $this->resolveSuperpower($telos, $topPoolProgressMap[$uid] ?? null);

            $profiles[] = [
                'user_id' => $uid,
                'display_name' => $user ? $user->getDisplayName() : $uid,
                'role' => $role,
                'level' => (int)($statsMap[$uid]['current_level'] ?? 1),
                'xp' => (int)($statsMap[$uid]['total_xp'] ?? 0),
                'bio' => $telos ? $telos->getBio() : null,
                'help_offer' => $telos ? $telos->getHelpOfferList() : [],
                'help_wanted' => $telos ? $telos->getHelpWantedList() : [],
                'superpower' => $superpower['label'],
                'superpower_source' => $superpower['source'],
                'visibility' => $visibility,
                'is_online' => $this->isOnline($user, $statsMap[$uid]['last_activity_date'] ?? null),
                'kudos_received_count' => (int)($kudosCounts[$uid] ?? 0),
                'is_self' => $uid === $viewerId,
            ];
        }

        usort($profiles, static function (array $left, array $right): int {
            if ($left['role'] !== $right['role']) {
                return $left['role'] === 'instructor' ? -1 : 1;
            }
            return strcasecmp((string)$left['display_name'], (string)$right['display_name']);
        });

        return [
            'profiles' => $profiles,
            'current_user_id' => $viewerId,
            'kudos_remaining' => $this->getRemainingKudos($courseId, $viewerId),
            'daily_kudos_limit' => self::DAILY_KUDOS_LIMIT,
            'my_visibility' => isset($telosMap[$viewerId]) ? $telosMap[$viewerId]->getVisibility() : 'private',
            'can_toggle_visibility' => (($memberRoles[$viewerId] ?? 'student') === 'student'),
            'is_instructor' => (bool)($course['is_instructor'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function giveKudos(int $courseId, string $fromUser, string $toUser, string $message = ''): array {
        $this->courseService->findById($courseId, $fromUser);

        if ($fromUser === $toUser) {
            throw new \RuntimeException('You cannot give kudos to yourself');
        }

        $members = $this->courseMemberMapper->findByCourse($courseId);
        $memberIds = array_map(static fn(CourseMember $member): string => $member->getUserId(), $members);
        if (!in_array($fromUser, $memberIds, true) || !in_array($toUser, $memberIds, true)) {
            throw new \RuntimeException('Both users must be enrolled in the course');
        }

        $remaining = $this->getRemainingKudos($courseId, $fromUser);
        if ($remaining <= 0) {
            throw new \RuntimeException('Daily kudos limit reached');
        }

        $kudos = new Kudos();
        $kudos->setCourseId($courseId);
        $kudos->setFromUser($fromUser);
        $kudos->setToUser($toUser);
        $kudos->setMessage($this->normalizeMessage($message));
        $kudos->setCreatedAt(time());
        $this->kudosMapper->insert($kudos);

        return [
            'status' => 'ok',
            'kudos_remaining' => $this->getRemainingKudos($courseId, $fromUser),
            'kudos' => $kudos->jsonSerialize(),
        ];
    }

    private function getRemainingKudos(int $courseId, string $userId): int {
        $todayStart = strtotime('today');
        $used = $this->kudosMapper->countGivenSince($courseId, $userId, $todayStart !== false ? $todayStart : time());
        return max(0, self::DAILY_KUDOS_LIMIT - $used);
    }

    /**
     * @param string[] $userIds
     * @return array<string, array{current_level: int, total_xp: int, last_activity_date: ?string}>
     */
    private function getUserStats(array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'current_level', 'total_xp', 'last_activity_date')
            ->from('learning_user_stats')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));

        $result = $qb->executeQuery();
        $stats = [];
        while ($row = $result->fetch()) {
            $stats[(string)$row['user_id']] = [
                'current_level' => (int)($row['current_level'] ?? 1),
                'total_xp' => (int)($row['total_xp'] ?? 0),
                'last_activity_date' => $row['last_activity_date'] !== null ? (string)$row['last_activity_date'] : null,
            ];
        }
        $result->closeCursor();

        return $stats;
    }

    /**
     * @param string[] $userIds
     * @return array<string, array{pool_name: string, percent: int}>
     */
    private function getTopPoolProgress(int $courseId, array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_values(array_unique(array_map(static fn($coursePool): int => $coursePool->getPoolId(), $coursePools)));
        if ($poolIds === []) {
            return [];
        }

        $questionCounts = $this->getPoolQuestionCounts($poolIds);
        $poolNames = $this->getPoolNames($poolIds);

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'pool_id', $qb->createFunction('COUNT(*) AS mastered'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT)))
            ->groupBy('user_id', 'pool_id');

        $result = $qb->executeQuery();
        $best = [];
        while ($row = $result->fetch()) {
            $userId = (string)$row['user_id'];
            $poolId = (int)$row['pool_id'];
            $totalQuestions = (int)($questionCounts[$poolId] ?? 0);
            if ($totalQuestions <= 0) {
                continue;
            }

            $percent = (int)round(((int)$row['mastered']) / $totalQuestions * 100);
            if (!isset($best[$userId]) || $percent > $best[$userId]['percent']) {
                $best[$userId] = [
                    'pool_name' => (string)($poolNames[$poolId] ?? 'Course pool'),
                    'percent' => $percent,
                ];
            }
        }
        $result->closeCursor();

        return $best;
    }

    /**
     * @param int[] $poolIds
     * @return array<int, int>
     */
    private function getPoolQuestionCounts(array $poolIds): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', $qb->createFunction('COUNT(*) AS cnt'))
            ->from('learning_questions')
            ->where($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('pool_id');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['pool_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $counts;
    }

    /**
     * @param int[] $poolIds
     * @return array<int, string>
     */
    private function getPoolNames(array $poolIds): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('learning_pools')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $names = [];
        while ($row = $result->fetch()) {
            $names[(int)$row['id']] = (string)$row['name'];
        }
        $result->closeCursor();

        return $names;
    }

    /**
     * @param array{pool_name: string, percent: int}|null $topPoolProgress
     * @return array{label: ?string, source: ?string}
     */
    private function resolveSuperpower(?UserTelos $telos, ?array $topPoolProgress): array {
        $helpOffer = $telos ? array_values(array_filter(array_map('trim', $telos->getHelpOfferList()))) : [];
        if ($helpOffer !== []) {
            return [
                'label' => $helpOffer[0],
                'source' => 'help_offer',
            ];
        }

        if ($topPoolProgress !== null && $topPoolProgress['percent'] > 0) {
            return [
                'label' => $topPoolProgress['pool_name'] . ' (' . $topPoolProgress['percent'] . '%)',
                'source' => 'pool_progress',
            ];
        }

        return [
            'label' => null,
            'source' => null,
        ];
    }

    private function isOnline(?IUser $user, ?string $lastActivityDate): bool {
        $cutoff = time() - 86400;

        if ($user !== null && method_exists($user, 'getLastLogin')) {
            $lastLogin = (int)$user->getLastLogin();
            if ($lastLogin > 0) {
                if ($lastLogin > 9999999999) {
                    $lastLogin = (int)floor($lastLogin / 1000);
                }
                return $lastLogin >= $cutoff;
            }
        }

        if ($lastActivityDate === null || $lastActivityDate === '') {
            return false;
        }

        $lastActivityTs = strtotime($lastActivityDate);
        if ($lastActivityTs === false) {
            return false;
        }

        return $lastActivityTs >= $cutoff;
    }

    private function normalizeMessage(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return 'Thanks for supporting the squad';
        }
        return mb_substr($message, 0, 255);
    }
}
