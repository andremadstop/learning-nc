<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class ReminderService {
    private const APP_ID = 'learning';
    private const USER_NOTIFICATIONS_ENABLED_KEY = 'notifications_enabled';
    private const LAST_DUE_REMINDER_KEY = 'last_due_reminder';
    private const LAST_STREAK_WARNING_KEY = 'last_streak_warning';
    private const EXAM_REMINDER_KEY_PREFIX = 'last_exam_reminder_';
    private const EXAM_REMINDER_DAYS = [7, 3, 1];

    private IDBConnection $db;
    private INotificationManager $notificationManager;
    private IConfig $config;
    private StreakService $streakService;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        INotificationManager $notificationManager,
        IConfig $config,
        StreakService $streakService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->notificationManager = $notificationManager;
        $this->config = $config;
        $this->streakService = $streakService;
        $this->logger = $logger;
    }

    public function sendDueCardReminders(): int {
        $today = $this->today();
        $sent = 0;

        foreach ($this->getDueCountsByUser() as $userId => $dueCount) {
            try {
                if ($dueCount <= 0 || !$this->notificationsEnabled($userId)) {
                    continue;
                }

                if ($this->config->getUserValue($userId, self::APP_ID, self::LAST_DUE_REMINDER_KEY, '') === $today) {
                    continue;
                }

                $created = $this->sendNotification($userId, 'due_reminder', 'due', $today, [
                    'due_count' => $dueCount,
                ]);
                $this->config->setUserValue($userId, self::APP_ID, self::LAST_DUE_REMINDER_KEY, $today);
                if ($created) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ReminderService: failed to send due reminder for {user}: {error}', [
                    'user' => $userId,
                    'error' => $e->getMessage(),
                    'app' => self::APP_ID,
                ]);
            }
        }

        return $sent;
    }

    public function sendStreakWarnings(): int {
        $today = $this->today();
        $sent = 0;

        foreach ($this->getStreakCandidateUserIds() as $userId) {
            try {
                if (!$this->notificationsEnabled($userId)) {
                    continue;
                }

                if ($this->config->getUserValue($userId, self::APP_ID, self::LAST_STREAK_WARNING_KEY, '') === $today) {
                    continue;
                }

                $streak = $this->streakService->getStreak($userId);
                if (($streak['current_streak'] ?? 0) <= 0 || ($streak['is_active_today'] ?? false)) {
                    continue;
                }

                if (($streak['last_activity_date'] ?? null) === null) {
                    continue;
                }

                $created = $this->sendNotification($userId, 'streak_warning', 'streak', $today, [
                    'streak_days' => (int)$streak['current_streak'],
                ]);
                $this->config->setUserValue($userId, self::APP_ID, self::LAST_STREAK_WARNING_KEY, $today);
                if ($created) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ReminderService: failed to send streak warning for {user}: {error}', [
                    'user' => $userId,
                    'error' => $e->getMessage(),
                    'app' => self::APP_ID,
                ]);
            }
        }

        return $sent;
    }

    /**
     * Mandatory compliance reminder. Locked decision 2: does NOT gate on notificationsEnabled().
     * Skeleton Wave 0 — returns false (no dispatch). Implemented in 163-06.
     */
    public function sendComplianceReminder(string $targetUserId, int $courseId, array $params): bool {
        return false;
    }

    public function sendExamReminders(): int {
        $sent = 0;
        $today = new \DateTimeImmutable('today');
        $targets = $this->getExamReminderTargets($today);

        foreach ($targets as $target) {
            $userId = (string)$target['user_id'];
            $courseId = (int)$target['course_id'];
            $courseTitle = (string)$target['course_title'];
            $examDate = (string)$target['exam_date'];
            $daysUntilExam = (int)$target['days_until_exam'];

            try {
                if (!$this->notificationsEnabled($userId)) {
                    continue;
                }

                $configKey = self::EXAM_REMINDER_KEY_PREFIX . $courseId;
                $marker = $examDate . ':' . $daysUntilExam;
                if ($this->config->getUserValue($userId, self::APP_ID, $configKey, '') === $marker) {
                    continue;
                }

                $objectId = $courseId . '-' . str_replace('-', '', $examDate) . '-' . $daysUntilExam;
                $created = $this->sendNotification($userId, 'exam_reminder', 'exam', $objectId, [
                    'days' => $daysUntilExam,
                    'course_title' => $courseTitle,
                ]);
                $this->config->setUserValue($userId, self::APP_ID, $configKey, $marker);
                if ($created) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ReminderService: failed to send exam reminder for {user} course {course}: {error}', [
                    'user' => $userId,
                    'course' => $courseId,
                    'error' => $e->getMessage(),
                    'app' => self::APP_ID,
                ]);
            }
        }

        return $sent;
    }

    private function notificationsEnabled(string $userId): bool {
        return $this->config->getUserValue(
            $userId,
            self::APP_ID,
            self::USER_NOTIFICATIONS_ENABLED_KEY,
            'yes'
        ) === 'yes';
    }

    private function getDueCountsByUser(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', $qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->lte('next_review', $qb->createNamedParameter(time())))
            ->groupBy('user_id');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(string)$row['user_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $counts;
    }

    private function getStreakCandidateUserIds(): array {
        $today = $this->today();
        $twoDaysAgo = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('learning_user_stats')
            ->where($qb->expr()->gt('current_streak', $qb->createNamedParameter(0)))
            ->andWhere($qb->expr()->isNotNull('last_activity_date'))
            ->andWhere($qb->expr()->neq('last_activity_date', $qb->createNamedParameter($today)))
            ->andWhere($qb->expr()->gte('last_activity_date', $qb->createNamedParameter($twoDaysAgo)))
            ->orderBy('user_id', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn(array $row): string => (string)$row['user_id'], $rows);
    }

    private function getExamReminderTargets(\DateTimeImmutable $today): array {
        $examDates = [];
        foreach (self::EXAM_REMINDER_DAYS as $days) {
            $examDates[] = $today->modify('+' . $days . ' days')->format('Y-m-d');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['cm.user_id', 'c.id AS course_id', 'c.title AS course_title', 'c.exam_date'])
            ->from('learning_courses', 'c')
            ->innerJoin('c', 'learning_course_members', 'cm', $qb->expr()->eq('c.id', 'cm.course_id'))
            ->where($qb->expr()->eq('c.status', $qb->createNamedParameter('active')))
            ->andWhere($qb->expr()->eq('cm.role', $qb->createNamedParameter('student')))
            ->andWhere($qb->expr()->in('c.exam_date', $qb->createNamedParameter($examDates, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('c.exam_date', 'ASC')
            ->addOrderBy('cm.user_id', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $targets = [];
        foreach ($rows as $row) {
            $examDate = (string)($row['exam_date'] ?? '');
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
            $errors = \DateTimeImmutable::getLastErrors();
            $hasWarnings = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsedDate === false || $hasWarnings || $parsedDate < $today) {
                continue;
            }

            $daysUntilExam = (int)$today->diff($parsedDate)->days;
            if (!in_array($daysUntilExam, self::EXAM_REMINDER_DAYS, true)) {
                continue;
            }

            $targets[] = [
                'user_id' => (string)$row['user_id'],
                'course_id' => (int)$row['course_id'],
                'course_title' => (string)$row['course_title'],
                'exam_date' => $examDate,
                'days_until_exam' => $daysUntilExam,
            ];
        }

        return $targets;
    }

    private function sendNotification(string $userId, string $subject, string $objectType, string $objectId, array $params): bool {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(self::APP_ID)
            ->setUser($userId)
            ->setObject($objectType, $objectId)
            ->setSubject($subject, $params);

        if ($this->notificationManager->getCount($notification) === 0) {
            $notification->setDateTime(new \DateTime());
            $this->notificationManager->notify($notification);
            return true;
        }

        return false;
    }

    /**
     * Send a compliance reminder notification to a single user for a mandatory course.
     *
     * SKELETON — no-op until 163-06 implements the real dispatch logic.
     * The method signature is frozen here so CertificateReportServiceTest can mock it in Wave 0.
     *
     * @param int    $courseId     the compliance course
     * @param string $targetUserId the user to remind
     * @param string $leadUserId   the team lead dispatching the reminder (for audit)
     */
    public function sendComplianceReminder(int $courseId, string $targetUserId, string $leadUserId): void {
        // SKELETON — no-op until 163-06
    }

    private function today(): string {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
