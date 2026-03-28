<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003400Date20260319233000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_curriculum_scopes')) {
            $table = $schema->createTable('learning_curriculum_scopes');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id', 'integer', ['notnull' => true]);
            $table->addColumn('enabled', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('handbook_key', 'string', ['notnull' => false, 'length' => 128]);
            $table->addColumn('handbook_title', 'string', ['notnull' => false, 'length' => 256]);
            $table->addColumn('chapter_keys_json', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->addColumn('updated_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id'], 'curriculum_scope_course_unique');
        }

        return $schema;
    }
}
