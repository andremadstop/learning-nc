<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create missing tables: pool_shares, question_translations, answer_translations, analytics.
 * Must run before Version000400 which adds foreign keys to these tables.
 */
class Version000350Date20260213000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Pool shares
        if (!$schema->hasTable('learning_pool_shares')) {
            $table = $schema->createTable('learning_pool_shares');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('shared_with', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('share_type', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'user']);
            $table->addColumn('permission', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'read']);
            $table->addColumn('created_at', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['pool_id'], 'learn_ps_pool_idx');
            $table->addIndex(['shared_with'], 'learn_ps_shared_idx');
            $table->addUniqueIndex(['pool_id', 'shared_with', 'share_type'], 'learn_ps_pool_user_uniq');
        }

        // Question translations
        if (!$schema->hasTable('learning_q_translations')) {
            $table = $schema->createTable('learning_q_translations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('explanation', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['question_id'], 'learn_qt_question_idx');
            $table->addUniqueIndex(['question_id', 'lang'], 'learn_qt_q_lang_uniq');
        }

        // Answer translations
        if (!$schema->hasTable('learning_a_translations')) {
            $table = $schema->createTable('learning_a_translations');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('answer_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['answer_id'], 'learn_at_answer_idx');
            $table->addUniqueIndex(['answer_id', 'lang'], 'learn_at_a_lang_uniq');
        }

        // Analytics
        if (!$schema->hasTable('learning_analytics')) {
            $table = $schema->createTable('learning_analytics');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('metric_type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('metric_value', Types::TEXT, ['notnull' => false]);
            $table->addColumn('recorded_at', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'learn_an_user_idx');
            $table->addIndex(['metric_type'], 'learn_an_type_idx');
        }

        return $schema;
    }
}
