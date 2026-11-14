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

    private function currentWeekKey(): string {
        return gmdate('o-\WW');
    }

    private function getFreezeState(string $userId): array {
        $fallback = ['tokens' => 1, 'week' => $this->currentWeekKey(), 'row_exists' => false];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('streak_freeze_tokens', 'last_freeze_reset_week')
               ->from('learning_user_stats')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
        } catch (\Throwable $e) {
            return $fallback;
        }

        if (!$row) {
            return $fallback;
        }

        $week = $this->currentWeekKey();
        $storedWeek = (string)($row['last_freeze_reset_week'] ?? '');
        $tokens = max(0, (int)($row['streak_freeze_tokens'] ?? 1));
        if ($storedWeek !== $week) {
            $tokens = 1;
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_user_stats')
               ->set('streak_freeze_tokens', $qb->createNamedParameter($tokens))
               ->set('last_freeze_reset_week', $qb->createNamedParameter($week))
               ->set('updated_at', $qb->createNamedParameter(time()))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->orX(
                   $qb->expr()->isNull('last_freeze_reset_week'),
                   $qb->expr()->neq('last_freeze_reset_week', $qb->createNamedParameter($week))
               ));
            $qb->executeStatement();
        }

        return ['tokens' => $tokens, 'week' => $week, 'row_exists' => true];
    }

    /**
     * Calculate daily learning streak from completed sessions.
     *
     * @return array{current_streak: int, longest_streak: int, last_activity_date: ?string, is_active_today: bool}
     */
    public function getStreak(string $userId, bool $consumeFreeze = false): array {
        if (!$this->isGamificationEnabled()) {
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_activity_date' => null,
                'is_active_today' => false,
                'freeze_tokens' => 0,
                'freeze_used' => false,
            ];
        }

        // Get distinct dates with completed sessions (UTC)
        $qb = $this->db->getQueryBuilder();
        // Portable: multiply by 1.0 ensures no int truncation; works on PG, MySQL, SQLite
        $platform = $this->db->getDatabasePlatform()->getName();
        if ($platform === 'postgresql') {
            $dateFn = "TO_CHAR(TO_TIMESTAMP(completed_at), 'YYYY-MM-DD')";
        } elseif ($platform === 'sqlite') {
            // SQLite has no FROM_UNIXTIME: /api/v1/user/state answered 500 on every SQLite install
            $dateFn = "strftime('%Y-%m-%d', completed_at, 'unixepoch')";
        } else {
            $dateFn = "FROM_UNIXTIME(completed_at, '%Y-%m-%d')";
        }
        $qb->selectDistinct($qb->createFunction($dateFn . ' as activity_date'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->orderBy('activity_date', 'DESC')
           ->setMaxResults(365);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            $freezeState = $this->getFreezeState($userId);
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_activity_date' => null,
                'is_active_today' => false,
                'freeze_tokens' => (int)$freezeState['tokens'],
                'freeze_used' => false,
            ];
        }

        $dates = array_values(array_map(fn($r) => (string)$r['activity_date'], $rows));
        $today = gmdate('Y-m-d');
        $freezeState = $this->getFreezeState($userId);
        $freezeTokens = (int)$freezeState['tokens'];
        $canUseFreeze = $freezeTokens > 0;

        [$currentStreak, $freezeUsed] = self::currentStreakFromDates($dates, $today, $canUseFreeze);

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

        if ($consumeFreeze && $freezeUsed && $freezeTokens > 0 && $freezeState['row_exists']) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_user_stats')
               ->set('streak_freeze_tokens', $qb->createFunction('streak_freeze_tokens - 1'))
               ->set('last_freeze_reset_week', $qb->createNamedParameter($freezeState['week']))
               ->set('updated_at', $qb->createNamedParameter(time()))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->gt('streak_freeze_tokens', $qb->createNamedParameter(0)));
            $updated = $qb->executeStatement();
            if ($updated > 0) {
                $freezeTokens--;
            } else {
                $freezeUsed = false;
            }
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'last_activity_date' => $dates[0],
            'is_active_today' => $dates[0] === $today,
            'freeze_tokens' => max(0, $freezeTokens),
            'freeze_used' => $freezeUsed,
        ];
    }

    /**
     * Current streak over activity dates (Y-m-d, newest first). One freeze token bridges one
     * missed day; the bridged day itself is not counted.
     *
     * A streak whose newest day is two days ago survives only because yesterday is bridged, so
     * the token is spoken for and cannot bridge a second, older gap (audit #83: one token used to
     * cover two gaps). It is NOT reported as used here: getStreak(consume: true) also runs per
     * answered question, before today's session is completed, and charging then would leave no
     * token for the bridge once the session completes (dates [today, today-2, …] bridge yesterday
     * in the loop and charge exactly once).
     *
     * @param list<string> $dates
     * @return array{0: int, 1: bool} [current streak, freeze used]
     */
    private static function currentStreakFromDates(array $dates, string $today, bool $canUseFreeze): array {
        if ($dates === []) {
            return [0, false];
        }
        $yesterday = gmdate('Y-m-d', strtotime('-1 day', strtotime($today)));
        $twoDaysAgo = gmdate('Y-m-d', strtotime('-2 day', strtotime($today)));

        $bridgeReserved = $dates[0] === $twoDaysAgo && $canUseFreeze;
        if (!$bridgeReserved && $dates[0] !== $today && $dates[0] !== $yesterday) {
            return [0, false];
        }

        $currentStreak = 0;
        $freezeUsed = false;
        $expected = $dates[0];
        foreach ($dates as $date) {
            if ($date === $expected) {
                $currentStreak++;
                $expected = gmdate('Y-m-d', strtotime('-1 day', strtotime($expected)));
                continue;
            }

            if ($canUseFreeze && !$freezeUsed && !$bridgeReserved) {
                $skipDay = gmdate('Y-m-d', strtotime('-1 day', strtotime($expected)));
                if ($date === $skipDay) {
                    $freezeUsed = true;
                    $expected = gmdate('Y-m-d', strtotime('-1 day', strtotime($skipDay)));
                    $currentStreak++;
                    continue;
                }
            }
            break;
        }

        return [$currentStreak, $freezeUsed];
    }
}
