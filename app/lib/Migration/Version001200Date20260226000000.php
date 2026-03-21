<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001200Date20260226000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Create learning_user_stats table
        if (!$schema->hasTable('learning_user_stats')) {
            $table = $schema->createTable('learning_user_stats');
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('total_xp', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('current_level', Types::INTEGER, ['notnull' => true, 'default' => 1, 'unsigned' => true]);
            $table->addColumn('current_streak', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('longest_streak', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('last_activity_date', Types::STRING, ['notnull' => false, 'length' => 10]);
            $table->addColumn('total_sessions', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('total_mastered', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('last_notif_date', Types::STRING, ['notnull' => false, 'length' => 10]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addUniqueIndex(['user_id'], 'learn_ustats_user_uniq');
        }

        // 2. Composite index on learning_sessions(user_id, completed_at)
        if ($schema->hasTable('learning_sessions')) {
            $sessionsTable = $schema->getTable('learning_sessions');
            if (!$sessionsTable->hasIndex('learn_sess_user_completed_idx')) {
                $sessionsTable->addIndex(['user_id', 'completed_at'], 'learn_sess_user_completed_idx');
            }

            // 3. Add time_limit_seconds column for Speed Demon badge
            if (!$sessionsTable->hasColumn('time_limit_seconds')) {
                $sessionsTable->addColumn('time_limit_seconds', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $platform = $this->db->getDatabasePlatform()->getName();

        if ($platform === 'postgresql') {
            $dateFn = "TO_CHAR(TO_TIMESTAMP(completed_at), 'YYYY-MM-DD')";
        } else {
            $dateFn = "FROM_UNIXTIME(completed_at, '%Y-%m-%d')";
        }

        // Step 1: Backfill users who have completed sessions (with Leitner data joined)
        // Idempotent: skips users already in stats table
        $sql = "INSERT INTO *PREFIX*learning_user_stats (user_id, total_xp, current_level, current_streak, longest_streak, last_activity_date, total_sessions, total_mastered, last_notif_date, updated_at)
            SELECT
                s.user_id,
                COALESCE(SUM(
                    CASE WHEN s.total_questions <= 0 THEN 0 ELSE
                        ROUND((CASE WHEN s.mode = 'exam' THEN 20 + (s.correct_answers * 10) ELSE 10 + (s.correct_answers * 5) END)
                        * CASE
                            WHEN (s.correct_answers * 1.0) / s.total_questions >= 1.0 THEN 1.5
                            WHEN (s.correct_answers * 1.0) / s.total_questions >= 0.8 THEN 1.2
                            ELSE 1.0 END)
                    END
                ), 0) as total_xp,
                1 as current_level,
                0 as current_streak,
                0 as longest_streak,
                MAX($dateFn) as last_activity_date,
                COUNT(*) as total_sessions,
                COALESCE(m.mastered, 0) as total_mastered,
                NULL as last_notif_date,
                " . time() . " as updated_at
            FROM *PREFIX*learning_sessions s
            LEFT JOIN (
                SELECT user_id, COUNT(*) as mastered
                FROM *PREFIX*learning_leitner_items
                WHERE box = 5
                GROUP BY user_id
            ) m ON s.user_id = m.user_id
            WHERE s.completed_at IS NOT NULL
            AND s.user_id NOT IN (SELECT us.user_id FROM *PREFIX*learning_user_stats us)
            GROUP BY s.user_id, m.mastered";

        $this->db->executeStatement($sql);

        // Step 2: Backfill Leitner-only users (have leitner items but no completed sessions)
        $leitnerOnlySql = "INSERT INTO *PREFIX*learning_user_stats (user_id, total_xp, current_level, current_streak, longest_streak, last_activity_date, total_sessions, total_mastered, last_notif_date, updated_at)
            SELECT
                l.user_id,
                (COALESCE(SUM(l.correct_count), 0) * 5 + SUM(CASE WHEN l.box = 5 THEN 25 ELSE 0 END)) as total_xp,
                1 as current_level,
                0 as current_streak,
                0 as longest_streak,
                NULL as last_activity_date,
                0 as total_sessions,
                SUM(CASE WHEN l.box = 5 THEN 1 ELSE 0 END) as total_mastered,
                NULL as last_notif_date,
                " . time() . " as updated_at
            FROM *PREFIX*learning_leitner_items l
            WHERE l.user_id NOT IN (
                SELECT DISTINCT us.user_id FROM *PREFIX*learning_user_stats us
            )
            GROUP BY l.user_id";

        $this->db->executeStatement($leitnerOnlySql);

        // Step 3: Update levels based on XP
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'total_xp')
           ->from('learning_user_stats');
        $result = $qb->executeQuery();

        while ($row = $result->fetch()) {
            $totalXp = (int)$row['total_xp'];
            $level = 1;
            while ($level < 999) {
                $nextThreshold = (int)round(50 * pow($level + 1, 1.5));
                if ($totalXp < $nextThreshold) {
                    break;
                }
                $level++;
            }

            if ($level > 1) {
                $uqb = $this->db->getQueryBuilder();
                $uqb->update('learning_user_stats')
                    ->set('current_level', $uqb->createNamedParameter($level))
                    ->where($uqb->expr()->eq('user_id', $uqb->createNamedParameter($row['user_id'])));
                $uqb->executeStatement();
            }
        }
        $result->closeCursor();
    }
}
