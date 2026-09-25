<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create learning_qst_translations / learning_ans_translations on installs that never got them.
 *
 * The mappers use these names since v3.5.0; Version007900 renames the original
 * learning_q_translations / learning_a_translations to them, but in postSchemaChange().
 * A fresh install runs only changeSchema() (see Version010100), so every fresh install kept
 * the old names and every translation lookup failed with "table not found".
 *
 * Created here in changeSchema(), which runs on every path. Where Version007900 already did
 * its work the tables exist and this is a no-op.
 *
 * The index names differ from the renamed tables' learn_qt_* / learn_at_*: on the affected
 * installs the legacy tables still exist and hold those names, and Nextcloud rejects an index
 * name that is already used by another table (MigrationService::ensureUniqueNamesConstraints,
 * fatal during install). The legacy tables are left alone; they are empty there, because no
 * code has written to them since v3.5.0, and `occ learning:uninstall` drops them.
 */
class Version010301Date20260925120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_qst_translations')) {
            $table = $schema->createTable('learning_qst_translations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('explanation', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['question_id'], 'learn_qst_question_idx');
            $table->addUniqueIndex(['question_id', 'lang'], 'learn_qst_q_lang_uniq');
            $changed = true;
        }

        if (!$schema->hasTable('learning_ans_translations')) {
            $table = $schema->createTable('learning_ans_translations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('answer_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['answer_id'], 'learn_ans_answer_idx');
            $table->addUniqueIndex(['answer_id', 'lang'], 'learn_ans_a_lang_uniq');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
