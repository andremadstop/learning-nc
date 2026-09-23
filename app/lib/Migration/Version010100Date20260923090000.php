<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the three learning_course_* tables for installs that never got them.
 *
 * Version009900 does all of its work in postSchemaChange(). That is fine on an upgrade,
 * but a FRESH install runs migrate('latest', true), which executes only changeSchema()
 * and then records every migration as executed. So on a fresh install 009900 never ran,
 * yet counts as done — leaving the legacy tables in place and the three target tables
 * absent, with no way for `occ upgrade` to ever revisit it.
 *
 * That is what Codeberg #7 hit: a course view died on a missing
 * learning_course_curriculum_scopes, and reinstalling the app reproduced it rather than
 * fixing it.
 *
 * This migration therefore creates the tables in changeSchema(), which runs on every
 * path, and only copies data in postSchemaChange(). Creating is the part that must not
 * be skipped; copying only matters where a legacy table actually holds rows.
 */
class Version010100Date20260923090000 extends SimpleMigrationStep {
    /**
     * Legacy name => target name, for the tables Version009900 was meant to produce.
     */
    private const TABLES = [
        'learning_curriculum_scopes' => 'learning_course_curriculum_scopes',
        'learning_q_overrides'       => 'learning_course_question_overrides',
        'learning_announcements'     => 'learning_course_announcements',
    ];

    /**
     * Columns copied per target table, with their parameter types.
     *
     * Without `id`, so sequences stay sane — and never untyped: an untyped false binds as
     * '' and PostgreSQL rejects it for a boolean column (SQLSTATE 22P02). That failure is
     * caught below and would have downgraded the whole recovery to a log line.
     */
    private const COPY_COLUMNS = [
        'learning_course_curriculum_scopes' => [
            'course_id' => IQueryBuilder::PARAM_INT,
            'enabled' => IQueryBuilder::PARAM_BOOL,
            'handbook_key' => IQueryBuilder::PARAM_STR,
            'handbook_title' => IQueryBuilder::PARAM_STR,
            'chapter_keys_json' => IQueryBuilder::PARAM_STR,
            'created_at' => IQueryBuilder::PARAM_INT,
            'updated_at' => IQueryBuilder::PARAM_INT,
        ],
        'learning_course_question_overrides' => [
            'course_id' => IQueryBuilder::PARAM_INT,
            'question_id' => IQueryBuilder::PARAM_INT,
            'paused' => IQueryBuilder::PARAM_BOOL,
            'highlight' => IQueryBuilder::PARAM_BOOL,
            'created_at' => IQueryBuilder::PARAM_INT,
            'updated_at' => IQueryBuilder::PARAM_INT,
        ],
        'learning_course_announcements' => [
            'course_id' => IQueryBuilder::PARAM_INT,
            'instructor_id' => IQueryBuilder::PARAM_STR,
            'title' => IQueryBuilder::PARAM_STR,
            'body' => IQueryBuilder::PARAM_STR,
            'created_at' => IQueryBuilder::PARAM_INT,
            'expires_at' => IQueryBuilder::PARAM_INT,
        ],
    ];

    public function __construct(
        private readonly IDBConnection $db,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Index names are unique per schema on PostgreSQL, and the legacy tables still carry
        // the original names. New names here, so this cannot collide on an install where the
        // rename never happened and both tables coexist.
        if (!$schema->hasTable('learning_course_curriculum_scopes')) {
            $t = $schema->createTable('learning_course_curriculum_scopes');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('course_id', 'integer', ['notnull' => true]);
            $t->addColumn('enabled', 'boolean', ['notnull' => false, 'default' => false]);
            $t->addColumn('handbook_key', 'string', ['notnull' => false, 'length' => 128]);
            $t->addColumn('handbook_title', 'string', ['notnull' => false, 'length' => 256]);
            $t->addColumn('chapter_keys_json', 'text', ['notnull' => false]);
            $t->addColumn('created_at', 'integer', ['notnull' => true]);
            $t->addColumn('updated_at', 'integer', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['course_id'], 'lrn_cscope_course_uniq');
        }

        if (!$schema->hasTable('learning_course_question_overrides')) {
            $t = $schema->createTable('learning_course_question_overrides');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('course_id', 'integer', ['notnull' => true]);
            $t->addColumn('question_id', 'integer', ['notnull' => true]);
            $t->addColumn('paused', 'boolean', ['notnull' => false, 'default' => false]);
            $t->addColumn('highlight', 'boolean', ['notnull' => false, 'default' => false]);
            $t->addColumn('created_at', 'integer', ['notnull' => false]);
            $t->addColumn('updated_at', 'integer', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['course_id', 'question_id'], 'lrn_cqo_course_quest_uniq');
        }

        if (!$schema->hasTable('learning_course_announcements')) {
            $t = $schema->createTable('learning_course_announcements');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('course_id', 'integer', ['notnull' => true]);
            $t->addColumn('instructor_id', 'string', ['notnull' => false, 'length' => 64]);
            $t->addColumn('title', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
            $t->addColumn('body', 'text', ['notnull' => false]);
            $t->addColumn('created_at', 'integer', ['notnull' => false]);
            $t->addColumn('expires_at', 'integer', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['course_id'], 'lrn_cann_course_idx');
        }

        return $schema;
    }

    /**
     * Move rows across where a legacy table holds data the target does not.
     *
     * Only ever copies into an EMPTY target, so re-running cannot duplicate rows and an
     * install where 009900 renamed successfully is left alone. The legacy table is kept:
     * dropping it is not needed to fix the defect, and leaving it lets an admin verify
     * the copy before anything is thrown away.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        foreach (self::TABLES as $legacy => $target) {
            try {
                if (!$this->tableExists($legacy) || $this->countRows($target) > 0) {
                    continue;
                }
                $rows = $this->countRows($legacy);
                if ($rows === 0) {
                    continue;
                }
                $copied = $this->copyRows($legacy, $target);
                $output->info("Recovered {$copied} row(s) from {$legacy} into {$target}");
            } catch (\Throwable $e) {
                // A failed copy must not abort the upgrade: the tables now exist, which is
                // what unbreaks the app. Report loudly so the rows can be recovered by hand.
                $output->warning("Could not copy {$legacy} -> {$target}: " . $e->getMessage());
            }
        }
    }

    private function tableExists(string $table): bool {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function countRows(string $table): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))->from($table);
            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function copyRows(string $legacy, string $target): int {
        $types = self::COPY_COLUMNS[$target];
        $columns = array_keys($types);

        $qb = $this->db->getQueryBuilder();
        $qb->select(...$columns)->from($legacy);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $copied = 0;
        foreach ($rows as $row) {
            $insert = $this->db->getQueryBuilder();
            $values = [];
            foreach ($types as $column => $type) {
                $values[$column] = $insert->createNamedParameter($row[$column], $type);
            }
            $insert->insert($target)->values($values);
            $insert->executeStatement();
            $copied++;
        }
        return $copied;
    }
}
