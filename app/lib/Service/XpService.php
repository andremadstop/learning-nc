<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\IDBConnection;
use OCP\IConfig;

class XpService {
    private IDBConnection $db;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config) {
        $this->db = $db;
        $this->config = $config;
    }

    public function isGamificationEnabled(): bool {
        return $this->config->getAppValue('learning', 'gamification_enabled', 'yes') === 'yes';
    }

    /**
     * Get XP and level data for a user.
     * Reads from learning_user_stats first (O(1)), falls back to full SQL aggregate.
     */
    public function calculateXp(string $userId): array {
        if (!$this->isGamificationEnabled()) {
            return $this->getLevelFromXp(0);
        }

        // Try stats table first
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_xp', 'current_level')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $statsRow = $result->fetch();
        $result->closeCursor();

        if ($statsRow && (int)$statsRow['total_xp'] > 0) {
            return $this->getLevelFromXp((int)$statsRow['total_xp']);
        }

        // Fallback: full SQL aggregate
        return $this->calculateXpFromDb($userId);
    }

    /**
     * Full XP recalculation from database (expensive, use sparingly).
     */
    private function calculateXpFromDb(string $userId): array {
        // Session XP
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction(
            'COALESCE(SUM(' .
                'CASE WHEN total_questions <= 0 THEN 0 ELSE ' .
                    'ROUND((CASE WHEN mode = \'exam\' THEN 20 + (correct_answers * 10) ELSE 10 + (correct_answers * 5) END)' .
                    ' * CASE' .
                        ' WHEN (correct_answers * 1.0) / total_questions >= 1.0 THEN 1.5' .
                        ' WHEN (correct_answers * 1.0) / total_questions >= 0.8 THEN 1.2' .
                        ' ELSE 1.0 END)' .
                ' END' .
            '), 0) as session_xp'
        ))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        $totalXp = (int)round((float)($row['session_xp'] ?? 0));

        // Leitner XP
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            $qb->createFunction('COALESCE(SUM(correct_count), 0) as total_correct'),
            $qb->createFunction('SUM(CASE WHEN box = 5 THEN 1 ELSE 0 END) as box5_count')
        )
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $leitnerRow = $result->fetch();
        $result->closeCursor();

        $totalXp += (int)($leitnerRow['total_correct'] ?? 0) * 5;
        $totalXp += (int)($leitnerRow['box5_count'] ?? 0) * 25;

        return $this->getLevelFromXp($totalXp);
    }

    /**
     * Get streak-based XP multiplier (tier system).
     */
    public function getStreakMultiplier(int $streakDays): float {
        if ($streakDays >= 30) return 3.0;
        if ($streakDays >= 7) return 2.0;
        if ($streakDays >= 3) return 1.5;
        return 1.0;
    }

    /**
     * Apply streak multiplier to base XP.
     */
    public function applyMultiplier(int $baseXp, int $streakDays): int {
        if (!$this->isGamificationEnabled()) {
            return 0;
        }

        return (int)round($baseXp * $this->getStreakMultiplier($streakDays));
    }

    /**
     * Calculate session XP for a single completed session.
     */
    public function calculateSessionXp(array $sessionData, int $streakDays = 0): int {
        if (!$this->isGamificationEnabled()) {
            return 0;
        }

        $totalQ = (int)($sessionData['total_questions'] ?? 0);
        if ($totalQ <= 0) {
            return 0;
        }

        $mode = $sessionData['mode'] ?? 'training';
        $correct = (int)($sessionData['correct_answers'] ?? 0);
        $accuracy = $correct / $totalQ;

        if ($mode === 'exam') {
            $base = 20 + ($correct * 10);
        } else {
            $base = 10 + ($correct * 5);
        }

        if ($accuracy >= 1.0) {
            $base = (int)round($base * 1.5);
        } elseif ($accuracy >= 0.8) {
            $base = (int)round($base * 1.2);
        }

        $multiplier = $this->getStreakMultiplier($streakDays);
        return (int)round($base * $multiplier);
    }

    /**
     * Increment session XP in user stats table after completing a session.
     * Level is synced AFTER the atomic XP increment to avoid stale-read races.
     */
    public function incrementSessionXp(string $userId, int $sessionXp, int $currentStreak): void {
        if (!$this->isGamificationEnabled()) {
            return;
        }

        $today = gmdate('Y-m-d');

        // Atomic UPDATE first (no pre-read of total_xp)
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('total_xp', $qb->createFunction('total_xp + ' . $qb->createNamedParameter($sessionXp)))
           ->set('total_sessions', $qb->createFunction('total_sessions + 1'))
           ->set('current_streak', $qb->createNamedParameter($currentStreak))
           ->set('longest_streak', $qb->createFunction(
               'CASE WHEN ' . $qb->createNamedParameter($currentStreak) . ' > longest_streak THEN ' . $qb->createNamedParameter($currentStreak) . ' ELSE longest_streak END'
           ))
           ->set('last_activity_date', $qb->createNamedParameter($today))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $affected = $qb->execute();

        // If no row existed, full recalc from source of truth (preserves historical XP)
        if ($affected === 0) {
            $this->updateUserStats($userId);
            // updateUserStats doesn't set streak — apply it separately
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_user_stats')
               ->set('current_streak', $qb->createNamedParameter($currentStreak))
               ->set('longest_streak', $qb->createFunction(
                   'CASE WHEN ' . $qb->createNamedParameter($currentStreak) . ' > longest_streak THEN ' . $qb->createNamedParameter($currentStreak) . ' ELSE longest_streak END'
               ))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->execute();
            return;
        }

        // Re-read total_xp after atomic increment and sync level
        $this->syncLevel($userId);
    }

    /**
     * Pure function: calculate level from total XP.
     * Level threshold formula: round(50 * n^1.5)
     */
    public function getLevelFromXp(int $totalXp): array {
        $level = 1;
        while ($level < 999) {
            $nextLevel = $level + 1;
            $threshold = (int)round(50 * pow($nextLevel, 1.5));
            if ($totalXp < $threshold) {
                $prevThreshold = $level <= 1 ? 0 : (int)round(50 * pow($level, 1.5));
                $xpInLevel = $totalXp - $prevThreshold;
                $xpToNext = $threshold - $prevThreshold;
                $levelPct = $xpToNext > 0 ? min(100, (int)round($xpInLevel / $xpToNext * 100)) : 0;
                return [
                    'total_xp' => $totalXp,
                    'level' => $level,
                    'xp_in_level' => max(0, $xpInLevel),
                    'xp_to_next_level' => $xpToNext,
                    'level_progress_pct' => $levelPct,
                ];
            }
            $level++;
        }

        return [
            'total_xp' => $totalXp,
            'level' => $level,
            'xp_in_level' => 0,
            'xp_to_next_level' => 0,
            'level_progress_pct' => 100,
        ];
    }

    /**
     * Update full user stats (recalculate everything).
     */
    public function updateUserStats(string $userId): void {
        $xpData = $this->calculateXpFromDb($userId);

        // Get session count
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'));
        $result = $qb->execute();
        $totalSessions = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        // Get mastered count
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)));
        $result = $qb->execute();
        $totalMastered = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        $today = gmdate('Y-m-d');

        // UPSERT: try UPDATE first
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('total_xp', $qb->createNamedParameter($xpData['total_xp']))
           ->set('current_level', $qb->createNamedParameter($xpData['level']))
           ->set('total_sessions', $qb->createNamedParameter($totalSessions))
           ->set('total_mastered', $qb->createNamedParameter($totalMastered))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $affected = $qb->execute();

        if ($affected === 0) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_stats')
                   ->values([
                       'user_id' => $qb->createNamedParameter($userId),
                       'total_xp' => $qb->createNamedParameter($xpData['total_xp']),
                       'current_level' => $qb->createNamedParameter($xpData['level']),
                       'current_streak' => $qb->createNamedParameter(0),
                       'longest_streak' => $qb->createNamedParameter(0),
                       'last_activity_date' => $qb->createNamedParameter($today),
                       'total_sessions' => $qb->createNamedParameter($totalSessions),
                       'total_mastered' => $qb->createNamedParameter($totalMastered),
                       'updated_at' => $qb->createNamedParameter(time()),
                   ]);
                $qb->execute();
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    // Race condition: retry as UPDATE
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('learning_user_stats')
                       ->set('total_xp', $qb->createNamedParameter($xpData['total_xp']))
                       ->set('current_level', $qb->createNamedParameter($xpData['level']))
                       ->set('total_sessions', $qb->createNamedParameter($totalSessions))
                       ->set('total_mastered', $qb->createNamedParameter($totalMastered))
                       ->set('updated_at', $qb->createNamedParameter(time()))
                       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
                    $qb->execute();
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Increment Leitner XP in user stats table (correct answer or Box-5 mastery).
     * Level is synced AFTER the atomic XP increment to avoid stale-read races.
     *
     * @param bool $skipSync When true, skip syncLevel() — caller must call syncLevel() after commit
     */
    public function incrementLeitnerXp(string $userId, int $xp, bool $skipSync = false): void {
        if (!$this->isGamificationEnabled()) {
            return;
        }

        if ($xp <= 0) {
            return;
        }

        // Atomic UPDATE first (no pre-read of total_xp)
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('total_xp', $qb->createFunction('total_xp + ' . $qb->createNamedParameter($xp)))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $affected = $qb->execute();

        // If no row existed, full recalc from source of truth (preserves historical XP + mastered)
        if ($affected === 0) {
            $this->updateUserStats($userId);
            return;
        }

        if (!$skipSync) {
            $this->syncLevel($userId);
        }
    }

    /**
     * Re-read total_xp from stats and sync current_level.
     * Called after atomic total_xp increments to avoid stale-level races.
     * Monotonic: only increases level, never decreases — safe under concurrency.
     */
    public function syncLevel(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_xp')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return;
        }

        $levelData = $this->getLevelFromXp((int)$row['total_xp']);
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('current_level', $qb->createNamedParameter($levelData['level']))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->lt('current_level', $qb->createNamedParameter($levelData['level'])));
        $qb->execute();
    }
}
