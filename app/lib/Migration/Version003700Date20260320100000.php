<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * N-Player Gameshow Tables — 2026-03-20
 *
 * Creates 3 tables for the multiplayer gameshow system:
 * 1. learning_gameshow_sessions — lobby + game state
 * 2. learning_gameshow_players — 1 row per player per session
 * 3. learning_gameshow_answers — per-question answer tracking
 */
class Version003700Date20260320100000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // Table 1: gameshow_sessions
        if (!$schema->hasTable('learning_gameshow_sessions')) {
            $table = $schema->createTable('learning_gameshow_sessions');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 6]);
            $table->addColumn('pool_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('course_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 20]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'waiting']);
            $table->addColumn('max_players', Types::INTEGER, ['notnull' => true, 'default' => 5]);
            $table->addColumn('min_players', Types::INTEGER, ['notnull' => true, 'default' => 2]);
            $table->addColumn('question_ids', Types::TEXT, ['notnull' => true]);
            $table->addColumn('current_question_index', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('num_questions', Types::INTEGER, ['notnull' => true, 'default' => 15]);
            $table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('started_at', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('creator_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'lgs_code_uidx');
            $table->addIndex(['course_id', 'status'], 'lgs_course_status_idx');
            $changed = true;
        }

        // Table 2: gameshow_players
        if (!$schema->hasTable('learning_gameshow_players')) {
            $table = $schema->createTable('learning_gameshow_players');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('session_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('slot', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('score', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('lives', Types::INTEGER, ['notnull' => true, 'default' => 3]);
            $table->addColumn('is_ready', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('is_removed', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('last_poll', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('joined_at', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['session_id', 'user_id'], 'lgsp_sess_user_uidx');
            $table->addIndex(['session_id'], 'lgsp_session_idx');
            $changed = true;
        }

        // Table 3: gameshow_answers
        if (!$schema->hasTable('learning_gameshow_answers')) {
            $table = $schema->createTable('learning_gameshow_answers');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('session_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('question_index', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('player_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('answer_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('is_correct', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('answered_at', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('points_earned', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['session_id', 'question_index', 'player_uid'], 'lgsa_sess_q_player_uidx');
            $table->addIndex(['session_id'], 'lgsa_session_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
