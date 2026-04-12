<?php
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000700Date20260218000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Clean up orphaned records before adding FK constraints
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Delete course_pools referencing non-existent courses
        $this->db->executeStatement(
            'DELETE FROM *PREFIX*learning_course_pools WHERE course_id NOT IN (SELECT id FROM *PREFIX*learning_courses)'
        );
        // Delete course_pools referencing non-existent pools
        $this->db->executeStatement(
            'DELETE FROM *PREFIX*learning_course_pools WHERE pool_id NOT IN (SELECT id FROM *PREFIX*learning_pools)'
        );
        // Delete course_members referencing non-existent courses
        $this->db->executeStatement(
            'DELETE FROM *PREFIX*learning_course_members WHERE course_id NOT IN (SELECT id FROM *PREFIX*learning_courses)'
        );
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_course_pools')) {
            $table = $schema->getTable('learning_course_pools');

            // Fix signed/unsigned mismatch: pool_id must be unsigned to match learning_pools.id
            $poolIdCol = $table->getColumn('pool_id');
            if (!$poolIdCol->getUnsigned()) {
                $poolIdCol->setUnsigned(true);
            }

            if (!$table->hasForeignKey('fk_lcp_course')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_courses'),
                    ['course_id'],
                    ['id'],
                    ['onDelete' => 'CASCADE'],
                    'fk_lcp_course'
                );
            }
            if (!$table->hasForeignKey('fk_lcp_pool')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_pools'),
                    ['pool_id'],
                    ['id'],
                    ['onDelete' => 'CASCADE'],
                    'fk_lcp_pool'
                );
            }
        }

        if ($schema->hasTable('learning_course_members')) {
            $table = $schema->getTable('learning_course_members');
            if (!$table->hasForeignKey('fk_lcm_course')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_courses'),
                    ['course_id'],
                    ['id'],
                    ['onDelete' => 'CASCADE'],
                    'fk_lcm_course'
                );
            }
        }

        return $schema;
    }
}
