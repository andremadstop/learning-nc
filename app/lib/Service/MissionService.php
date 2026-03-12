<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\IConfig;
use OCP\IDBConnection;

class MissionService {
    private IDBConnection $db;
    private IConfig $config;
    private XpService $xpService;

    private const DAILY_MISSIONS = [
        'daily_sessions_1' => [
            'name' => 'Daily Warmup',
            'description' => 'Complete 1 session today',
            'xp' => 20,
            'target' => 1,
        ],
        'daily_cards_20' => [
            'name' => 'Review Sprint',
            'description' => 'Review 20 Leitner cards today',
            'xp' => 30,
            'target' => 20,
        ],
        'daily_high_score' => [
            'name' => 'Sharp Focus',
            'description' => 'Finish one session today with at least 80% (min 10 questions)',
            'xp' => 40,
            'target' => 1,
        ],
    ];

    public function __construct(IDBConnection $db, IConfig $config, XpService $xpService) {
        $this->db = $db;
        $this->config = $config;
        $this->xpService = $xpService;
    }

    private function isGamificationEnabled(): bool {
        return $this->config->getAppValue('learning', 'gamification_enabled', 'yes') === 'yes';
    }

    public function getMissions(string $userId): array {
        $today = gmdate('Y-m-d');
        $progress = $this->getDailyProgress($userId, $today);
        $claimed = $this->getClaimedMap($userId, $today);
        $missions = [];

        foreach (self::DAILY_MISSIONS as $key => $def) {
            $current = (int)($progress[$key] ?? 0);
            $target = (int)$def['target'];
            $completed = $current >= $target;
            $isClaimed = isset($claimed[$key]);
            $missions[] = [
                'key' => $key,
                'name' => $def['name'],
                'description' => $def['description'],
                'type' => 'daily',
                'date' => $today,
                'xp' => (int)$def['xp'],
                'current' => min($current, $target),
                'target' => $target,
                'completed' => $completed,
                'claimed' => $isClaimed,
                'claimable' => $completed && !$isClaimed && $this->isGamificationEnabled(),
            ];
        }

        return [
            'date' => $today,
            'missions' => $missions,
        ];
    }

    public function claimMission(string $userId, string $missionKey): array {
        if (!isset(self::DAILY_MISSIONS[$missionKey])) {
            throw new \InvalidArgumentException('Unknown mission key');
        }

        if (!$this->isGamificationEnabled()) {
            throw new \InvalidArgumentException('Gamification disabled');
        }

        $today = gmdate('Y-m-d');
        $progress = $this->getDailyProgress($userId, $today);
        $target = (int)self::DAILY_MISSIONS[$missionKey]['target'];
        $current = (int)($progress[$missionKey] ?? 0);
        if ($current < $target) {
            throw new \InvalidArgumentException('Mission not completed yet');
        }

        $xp = (int)self::DAILY_MISSIONS[$missionKey]['xp'];
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_mission_claims')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'mission_key' => $qb->createNamedParameter($missionKey),
                   'mission_date' => $qb->createNamedParameter($today),
                   'xp_earned' => $qb->createNamedParameter($xp),
                   'claimed_at' => $qb->createNamedParameter(time()),
               ]);
            $qb->execute();
            $this->xpService->incrementLeitnerXp($userId, $xp, true);
            $this->db->commit();
        } catch (UniqueConstraintViolationException $e) {
            $this->db->rollBack();
            throw new \InvalidArgumentException('Mission already claimed');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->xpService->syncLevel($userId);

        return [
            'mission_key' => $missionKey,
            'xp_earned' => $xp,
            'date' => $today,
        ];
    }

    private function getClaimedMap(string $userId, string $date): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('mission_key')
           ->from('learning_user_mission_claims')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mission_date', $qb->createNamedParameter($date)));
        $result = $qb->execute();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['mission_key']] = true;
        }
        return $map;
    }

    private function getDailyProgress(string $userId, string $date): array {
        $start = strtotime($date . ' 00:00:00 UTC');

        $progress = [
            'daily_sessions_1' => 0,
            'daily_cards_20' => 0,
            'daily_high_score' => 0,
        ];

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($start)));
        $result = $qb->execute();
        $progress['daily_sessions_1'] = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->gte('last_reviewed', $qb->createNamedParameter($start)));
        $result = $qb->execute();
        $progress['daily_cards_20'] = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($start)))
           ->andWhere($qb->expr()->gte('total_questions', $qb->createNamedParameter(10)))
           ->andWhere(
               $qb->expr()->gte(
                   $qb->createFunction('CASE WHEN total_questions > 0 THEN (correct_answers * 100.0) / total_questions ELSE 0 END'),
                   $qb->createNamedParameter(80)
               )
           )
           ->setMaxResults(1);
        $result = $qb->execute();
        $progress['daily_high_score'] = $result->fetch() ? 1 : 0;
        $result->closeCursor();

        return $progress;
    }
}
