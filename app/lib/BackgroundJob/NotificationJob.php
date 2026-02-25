<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;

class NotificationJob extends TimedJob {
    private IDBConnection $db;
    private INotificationManager $notificationManager;

    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        INotificationManager $notificationManager
    ) {
        parent::__construct($time);
        // Run every 4 hours
        $this->setInterval(4 * 3600);
        $this->db = $db;
        $this->notificationManager = $notificationManager;
    }

    protected function run($argument): void {
        $today = gmdate('Y-m-d');
        $now = time();

        // Pre-fetch due counts for all users in one query (avoids N+1)
        $dueCounts = $this->getAllDueCounts($now);

        // Stream users one-by-one to avoid memory exhaustion on large instances
        // Only check users active in the last 30 days (skip dormant accounts)
        $thirtyDaysAgo = gmdate('Y-m-d', strtotime('-30 days'));

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'current_streak', 'last_activity_date', 'last_notif_date')
           ->from('learning_user_stats')
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('last_notif_date'),
               $qb->expr()->neq('last_notif_date', $qb->createNamedParameter($today))
           ))
           ->andWhere($qb->expr()->orX(
               $qb->expr()->gte('last_activity_date', $qb->createNamedParameter($thirtyDaysAgo)),
               $qb->expr()->isNull('last_activity_date')
           ));
        $result = $qb->execute();

        while ($user = $result->fetch()) {
            $userId = $user['user_id'];
            $lastActivity = $user['last_activity_date'];
            $isActiveToday = ($lastActivity === $today);
            $streak = (int)$user['current_streak'];
            $sentNotification = false;

            // Streak warning: streak > 3 and not active today
            if ($streak > 3 && !$isActiveToday) {
                $this->sendNotification($userId, 'streak_warning', 'streak', $today, [
                    'streak_days' => $streak,
                ]);
                $sentNotification = true;
            }

            // Due reminder: >10 cards due and not active today
            if (!$isActiveToday) {
                $dueCount = $dueCounts[$userId] ?? 0;
                if ($dueCount > 10) {
                    $this->sendNotification($userId, 'due_reminder', 'due', $today, [
                        'due_count' => $dueCount,
                    ]);
                    $sentNotification = true;
                }
            }

            // Update last_notif_date to prevent duplicate notifications
            if ($sentNotification) {
                $uqb = $this->db->getQueryBuilder();
                $uqb->update('learning_user_stats')
                    ->set('last_notif_date', $uqb->createNamedParameter($today))
                    ->where($uqb->expr()->eq('user_id', $uqb->createNamedParameter($userId)));
                $uqb->execute();
            }
        }
        $result->closeCursor();
    }

    private function getAllDueCounts(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', $qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->lte('next_review', $qb->createNamedParameter($now)))
           ->groupBy('user_id');
        $result = $qb->execute();

        $counts = [];
        while ($row = $result->fetch()) {
            $counts[$row['user_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    private function sendNotification(string $userId, string $subject, string $objectType, string $objectId, array $params): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp('learning')
            ->setUser($userId)
            ->setObject($objectType, $objectId);

        // Only send if not already existing
        if ($this->notificationManager->getCount($notification) === 0) {
            $notification->setDateTime(new \DateTime())
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        }
    }
}
