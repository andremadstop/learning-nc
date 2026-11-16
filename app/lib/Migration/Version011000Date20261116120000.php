<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #66, part B: media per question (occ learning:import-question-media).
 *
 * learning_qmedia_tracks — one audio track per question, kind (intro | dialog) and language.
 *   The mp3 itself lives in the app's own app data (QuestionMediaStorage: folder qmedia-<pool>,
 *   file q<question>-<kind>-<lang>.mp3), never in a user's files. file_name, size and sha256
 *   describe that file (sha256 makes a repeated import a no-op); timeline is the subtitle list
 *   [{role, text, start, end}] in seconds as JSON; duration_ms is the end of its last line.
 * learning_qmedia_cues — the cues JSON of a question (keywords per dialog line, contrast
 *   wrong/right, key takeaway) for the player. Its own table, not a column on
 *   learning_questions: the cues name the right answer, and every read path of a question
 *   serializes that row.
 *
 * Both reference learning_questions with ON DELETE CASCADE, so the rows go with their question
 * and with their pool (questions cascade from learning_pools). The files are removed by
 * QuestionMediaStorage (on delete, by the repair step and by the daily consistency job).
 *
 * Tables only, no seed data: changeSchema() alone covers fresh installs and upgrades.
 * Index and key names are unique across all tables of the app.
 */
class Version011000Date20261116120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_qmedia_tracks')) {
            $table = $schema->createTable('learning_qmedia_tracks');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('timeline', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null, 'unsigned' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['question_id', 'kind', 'lang'], 'learn_qmtrk_q_kind_lang');
            $table->addIndex(['pool_id'], 'learn_qmtrk_pool_idx');
            if ($schema->hasTable('learning_questions')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_qmtrk_question_fk'
                );
            }
            $changed = true;
        }

        if (!$schema->hasTable('learning_qmedia_cues')) {
            $table = $schema->createTable('learning_qmedia_cues');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('cues', Types::TEXT, ['notnull' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['question_id'], 'learn_qmcue_question_uniq');
            $table->addIndex(['pool_id'], 'learn_qmcue_pool_idx');
            if ($schema->hasTable('learning_questions')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_qmcue_question_fk'
                );
            }
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
