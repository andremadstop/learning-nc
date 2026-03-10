<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\IDBConnection;
use OCP\IConfig;

class StreakService {
    private IDBConnection $db;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config) {
        $this->db = $db;
        $this->config = $config;
    }

    private function isGamificationEnabled(): bool {
        return $this->config->getAppValue('learning', 'gamification_enabled', 'yes') === 'yes';
    }

    /**
     * Calculate daily learning streak from completed sessions.
     *
     * @return array{current_streak: int, longest_streak: int, last_activity_date: ?string, is_active_today: bool}
     */
    public function getStreak(string $userId): array {
        if (!$this->isGamificationEnabled()) {
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_activity_date' => null,
                'is_active_today' => false,
            ];
        }

        // Get distinct dates with completed sessions (UTC)
        $qb = $this->db->getQueryBuilder();
        // Portable: multiply by 1.0 ensures no int truncation; works on PG, MySQL, SQLite
        $platform = $this->db->getDatabasePlatform()->getName();
        if ($platform === 'postgresql') {
            $dateFn = "TO_CHAR(TO_TIMESTAMP(completed_at), 'YYYY-MM-DD')";
        } else {
            $dateFn = "FROM_UNIXTIME(completed_at, '%Y-%m-%d')";
        }
        $qb->selectDistinct($qb->createFunction($dateFn . ' as activity_date'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->orderBy('activity_date', 'DESC')
           ->setMaxResults(365);

        $result = $qb->execute();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_activity_date' => null,
                'is_active_today' => false,
            ];
        }

        $dates = array_map(fn($r) => $r['activity_date'], $rows);
        $today = gmdate('Y-m-d');

        // Calculate current streak: count consecutive days backwards from today/yesterday
        $currentStreak = 0;
        $checkDate = $today;

        // If the most recent activity is not today or yesterday, streak is 0
        if ($dates[0] !== $today && $dates[0] !== gmdate('Y-m-d', strtotime('-1 day', strtotime($today)))) {
            $currentStreak = 0;
        } else {
            $checkDate = $dates[0];
            foreach ($dates as $date) {
                if ($date === $checkDate) {
                    $currentStreak++;
                    $checkDate = gmdate('Y-m-d', strtotime('-1 day', strtotime($checkDate)));
                } else {
                    break;
                }
            }
        }

        // Calculate longest streak
        $longestStreak = 0;
        $tempStreak = 1;
        for ($i = 1; $i < count($dates); $i++) {
            $expectedPrev = gmdate('Y-m-d', strtotime('-1 day', strtotime($dates[$i - 1])));
            if ($dates[$i] === $expectedPrev) {
                $tempStreak++;
            } else {
                $longestStreak = max($longestStreak, $tempStreak);
                $tempStreak = 1;
            }
        }
        $longestStreak = max($longestStreak, $tempStreak, $currentStreak);

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'last_activity_date' => $dates[0],
            'is_active_today' => $dates[0] === $today,
        ];
    }
}
