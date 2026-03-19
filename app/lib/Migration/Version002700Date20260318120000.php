<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002700Date20260318120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_league_seasons')) {
            $table = $schema->createTable('learning_league_seasons');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id', 'integer', ['notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('pool_id', 'integer', ['notnull' => true]);
            $table->addColumn('cl_pool_id', 'integer', ['notnull' => true]);
            $table->addColumn('num_questions', 'integer', ['notnull' => true, 'default' => 10]);
            $table->addColumn('cl_num_questions', 'integer', ['notnull' => true, 'default' => 15]);
            $table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
            $table->addColumn('started_at', 'integer', ['notnull' => false]);
            $table->addColumn('ends_at', 'integer', ['notnull' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['course_id', 'status'], 'lls_course_status_idx');
            $table->addIndex(['course_id', 'created_at'], 'lls_course_created_idx');
            $output->info('Created learning_league_seasons table');
        }

        if (!$schema->hasTable('learning_league_challenges')) {
            $table = $schema->createTable('learning_league_challenges');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('season_id', 'integer', ['notnull' => true]);
            $table->addColumn('stage', 'string', ['length' => 16, 'notnull' => true, 'default' => 'league']);
            $table->addColumn('round_number', 'integer', ['notnull' => false]);
            $table->addColumn('challenger_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('opponent_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('duel_session_id', 'integer', ['notnull' => false]);
            $table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('accepted_at', 'integer', ['notnull' => false]);
            $table->addColumn('deadline_at', 'integer', ['notnull' => true]);
            $table->addColumn('winner_uid', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('played_at', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['season_id', 'stage', 'status'], 'llc_season_stage_status_idx');
            $table->addIndex(['season_id', 'challenger_uid'], 'llc_season_challenger_idx');
            $table->addIndex(['season_id', 'opponent_uid'], 'llc_season_opponent_idx');
            $table->addIndex(['duel_session_id'], 'llc_duel_idx');
            $output->info('Created learning_league_challenges table');
        }

        if (!$schema->hasTable('learning_league_results')) {
            $table = $schema->createTable('learning_league_results');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('season_id', 'integer', ['notnull' => true]);
            $table->addColumn('challenge_id', 'integer', ['notnull' => false]);
            $table->addColumn('duel_session_id', 'integer', ['notnull' => false]);
            $table->addColumn('stage', 'string', ['length' => 16, 'notnull' => true, 'default' => 'league']);
            $table->addColumn('round_number', 'integer', ['notnull' => false]);
            $table->addColumn('player_a_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('player_b_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('score_a', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('score_b', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('league_points_a', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('league_points_b', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('winner_uid', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('played_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['season_id', 'stage'], 'llr_season_stage_idx');
            $table->addIndex(['challenge_id'], 'llr_challenge_idx');
            $table->addIndex(['duel_session_id'], 'llr_duel_idx');
            $output->info('Created learning_league_results table');
        }

        if ($schema->hasTable('learning_duel_sessions')) {
            $table = $schema->getTable('learning_duel_sessions');
            if (!$table->hasColumn('league_season_id')) {
                $table->addColumn('league_season_id', 'integer', ['notnull' => false]);
                $table->addIndex(['league_season_id'], 'lds_league_season_idx');
            }
            if (!$table->hasColumn('league_challenge_id')) {
                $table->addColumn('league_challenge_id', 'integer', ['notnull' => false]);
                $table->addIndex(['league_challenge_id'], 'lds_league_challenge_idx');
            }
        }

        return $schema;
    }
}
