<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Audit #51: learning_course_snapshots holds two different things — an instructor's course
 * archive (CourseArchiveService, the full export) and a learner's personal course summary
 * (CourseSummaryService). Nothing told them apart: the archive panel listed learners' summaries
 * as archives, and an instructor who had archived the course got the export back as their
 * "summary".
 *
 * learning_course_snapshots
 *   snapshot_kind  'archive' | 'summary'. Default 'summary'; both writers set it explicitly.
 *
 * Column in changeSchema(), so a fresh install has it. postSchemaChange() classifies the rows
 * that exist on an upgrade (a fresh install has none, so nothing is missing there): an archive is
 * the JSON of DataMobilityService::exportCourse(), whose first key is "course"; a summary starts
 * with "mastery". The UPDATE is a plain LIKE through the query builder (PostgreSQL and MariaDB).
 */
class Version010600Date20261114093000 extends SimpleMigrationStep {
    public const ARCHIVE_PREFIX = '{"course":';

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_course_snapshots')) {
            return null;
        }
        $table = $schema->getTable('learning_course_snapshots');
        if ($table->hasColumn('snapshot_kind')) {
            return null;
        }
        $table->addColumn('snapshot_kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'summary']);
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $count = $this->classifyExistingArchives();
        if ($count > 0) {
            $output->info(sprintf('Marked %d course snapshot(s) as archive', $count));
        }
    }

    /**
     * Existing archive rows get snapshot_kind 'archive'; every other row keeps the default.
     * Idempotent: a second run matches the same rows and changes nothing.
     */
    public function classifyExistingArchives(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_course_snapshots')
            ->set('snapshot_kind', $qb->createNamedParameter('archive'))
            ->where($qb->expr()->like(
                'snapshot_data',
                $qb->createNamedParameter($this->db->escapeLikeParameter(self::ARCHIVE_PREFIX) . '%')
            ))
            ->andWhere($qb->expr()->neq('snapshot_kind', $qb->createNamedParameter('archive')));
        return $qb->executeStatement();
    }
}
