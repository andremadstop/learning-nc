<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #75: required documents are confirmed per certification cycle and per file version.
 *
 * learning_doc_confirms — one row per "Confirm I have read this", append-only. A new cycle, a
 *   changed file or an instructor's "Ask everyone to confirm again" asks for a new row; the old
 *   ones stay (together with their course.video.completed event in the compliance chain).
 *   doc_version is the version of the file that was read (sha256 of the Nextcloud checksum, or
 *   of mtime and size), NULL for a confirmation taken over from before 5.14.0. The unique key
 *   (user_id, content_id, confirmed_at) turns a double click within one second into a no-op.
 *   Videos keep their watch state in learning_video_progress, which this step does not change.
 * learning_course_videos.document_id — the course_documents id of a document row (it was only
 *   in the TEXT video_ref), with UNIQUE (course_id, document_id): a document can be required
 *   once per course. Video rows keep NULL, and NULLs never collide (PostgreSQL and MariaDB).
 * learning_course_videos.confirm_after — set by "Ask everyone to confirm again": confirmations
 *   from before it no longer count. NULL = never asked.
 * learning_course_documents.file_id — the Nextcloud file id, so a scan keeps the row (and with
 *   it the required-content link) when the file is only renamed.
 * learning_courses.reconfirm_per_cycle — confirmations count only within the current
 *   certification cycle. Nullable like cert_enabled (Nextcloud rejects NOT NULL booleans).
 *
 * Data (postSchemaChange, upgrades only — a fresh install has nothing to take over; info.xml
 * stays unchanged in 5.14.0, so this is no repair step):
 *   1. every completed document row of learning_video_progress becomes a confirmation row
 *      (doc_version NULL), duplicates of a required document mapped onto the row that is kept;
 *   2. duplicate document rows (the same document required twice in one course, GitHub #75)
 *      are reduced to the oldest row; their progress rows go, the confirmations are in step 1;
 *   3. document_id is filled for every remaining document row.
 * Each step is idempotent: a confirmation that exists already is not copied again.
 *
 * Index names are unique across the app's tables and at most 27 characters.
 */
class Version011100Date20261116150000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_doc_confirms')) {
            $table = $schema->createTable('learning_doc_confirms');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('content_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('confirmed_at', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('doc_version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'content_id', 'confirmed_at'], 'learn_dconf_usr_cnt_at_uq');
            $table->addIndex(['content_id'], 'learn_dconf_content_idx');
            $table->addIndex(['course_id'], 'learn_dconf_course_idx');
            $changed = true;
        }

        if ($schema->hasTable('learning_course_videos')) {
            $table = $schema->getTable('learning_course_videos');
            if (!$table->hasColumn('document_id')) {
                $table->addColumn('document_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
            if (!$table->hasColumn('confirm_after')) {
                $table->addColumn('confirm_after', Types::BIGINT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
            if (!$table->hasIndex('learn_cv_course_doc_uq')) {
                $table->addUniqueIndex(['course_id', 'document_id'], 'learn_cv_course_doc_uq');
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_course_documents')) {
            $table = $schema->getTable('learning_course_documents');
            if (!$table->hasColumn('file_id')) {
                $table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
            if (!$table->hasIndex('learn_cdoc_crs_file_idx')) {
                $table->addIndex(['course_id', 'file_id'], 'learn_cdoc_crs_file_idx');
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('reconfirm_per_cycle')) {
                $table->addColumn('reconfirm_per_cycle', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $copied = self::migrateDocumentRows($this->db);
        if ($copied['confirmations'] > 0 || $copied['duplicates'] > 0) {
            $output->info(sprintf(
                'Required documents: %d confirmation(s) taken over, %d duplicate row(s) merged.',
                $copied['confirmations'],
                $copied['duplicates']
            ));
        }
    }

    /**
     * Steps 1–3 of the class comment. Public and static so the integration test runs exactly
     * this code against a database with existing confirmations.
     *
     * @return array{confirmations: int, duplicates: int}
     */
    public static function migrateDocumentRows(IDBConnection $db): array {
        // All document rows, oldest first; the oldest row per (course, document id) is kept.
        $qb = $db->getQueryBuilder();
        $qb->select('id', 'course_id', 'video_ref')
            ->from('learning_course_videos')
            ->where($qb->expr()->eq('source_type', $qb->createNamedParameter('document')))
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $keepFor = [];   // "course:document" => kept row id
        $target = [];    // row id => kept row id (itself for a kept row)
        $courseOf = [];  // row id => course id
        $documentOf = []; // kept row id => document id
        while ($row = $result->fetch()) {
            $id = (int)$row['id'];
            $courseOf[$id] = (int)$row['course_id'];
            $ref = trim((string)$row['video_ref']);
            if ($ref === '' || !ctype_digit($ref) || strlen($ref) > 18 || (int)$ref <= 0) {
                $target[$id] = $id; // a legacy path row: kept, no document id
                continue;
            }
            $key = $row['course_id'] . ':' . (int)$ref;
            if (!isset($keepFor[$key])) {
                $keepFor[$key] = $id;
                $documentOf[$id] = (int)$ref;
            }
            $target[$id] = $keepFor[$key];
        }
        $result->closeCursor();
        if ($target === []) {
            return ['confirmations' => 0, 'duplicates' => 0];
        }

        // 1. Completed progress rows → confirmations (idempotent per user, kept row and time).
        $confirmations = 0;
        foreach (array_chunk(array_keys($target), 500) as $chunk) {
            $qb = $db->getQueryBuilder();
            $qb->select('user_id', 'content_id', 'completed_at')
                ->from('learning_video_progress')
                ->where($qb->expr()->in('content_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('completed_at'));
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            foreach ($rows as $row) {
                $contentId = $target[(int)$row['content_id']];
                if (self::insertConfirmation($db, (string)$row['user_id'], $contentId, $courseOf[$contentId], (int)$row['completed_at'])) {
                    $confirmations++;
                }
            }
        }

        // 2. Duplicate rows go, with their progress rows (taken over in step 1).
        $duplicates = [];
        foreach ($target as $id => $keptId) {
            if ($id !== $keptId) {
                $duplicates[] = $id;
            }
        }
        foreach (array_chunk($duplicates, 500) as $chunk) {
            $qb = $db->getQueryBuilder();
            $qb->delete('learning_video_progress')
                ->where($qb->expr()->in('content_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
            $qb = $db->getQueryBuilder();
            $qb->delete('learning_course_videos')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }

        // 3. document_id on every kept document row that has none yet.
        foreach ($documentOf as $id => $documentId) {
            $qb = $db->getQueryBuilder();
            $qb->update('learning_course_videos')
                ->set('document_id', $qb->createNamedParameter($documentId, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNull('document_id'))
                ->executeStatement();
        }

        return ['confirmations' => $confirmations, 'duplicates' => count($duplicates)];
    }

    /** A confirmation row unless the same one exists; true when a row was written. */
    private static function insertConfirmation(IDBConnection $db, string $userId, int $contentId, int $courseId, int $confirmedAt): bool {
        $qb = $db->getQueryBuilder();
        $qb->select('id')
            ->from('learning_doc_confirms')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('confirmed_at', $qb->createNamedParameter($confirmedAt, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        if ($exists) {
            return false;
        }
        $qb = $db->getQueryBuilder();
        $qb->insert('learning_doc_confirms')
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'content_id' => $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT),
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'confirmed_at' => $qb->createNamedParameter($confirmedAt, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
        return true;
    }
}
