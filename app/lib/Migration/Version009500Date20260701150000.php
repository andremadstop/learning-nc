<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 162 — Video-/Material-Gating schema (VIDEO-02/06/07/09).
 *
 * Three schema changes:
 *
 * learning_course_videos (VIDEO-09) — the gated-content registry. One row per video/document
 *   attached to a course. duration_seconds is admin-entered (ffprobe VERIFIED ABSENT on relay,
 *   OQ3 locked = manual entry); NULL is legitimate for source_type='document', but a NULL/0
 *   duration on a VIDEO source type is a setup error that blocks the gate (enforced in
 *   VideoProgressService, not the schema).
 *
 * learning_video_progress (VIDEO-02/06) — per-(user,content) watch state. intervals_json +
 *   covered_pct are DSGVO-transient working state, nulled at the completed_at write. last_ping_ts
 *   is the monotonic clock for <5s heartbeat plausibility (NULL = no ping yet, always plausible)
 *   AND doubles as the CAS token that serializes one user's concurrent tabs (NC IQueryBuilder has
 *   no forUpdate() — see Version009300 — so recordHeartbeat serializes via
 *   UPDATE ... WHERE last_ping_ts = :expected, mirroring AuditService's last_seq CAS).
 *   UNIQUE(user_id, content_id): exactly one row per (user, content). This key is period-AGNOSTIC
 *   — NO active_period_key / period column here. Phase 164 re-cert period-scopes by RESETTING this
 *   row, not by a schema constraint (recert seam).
 *
 * learning_courses.video_gate_enabled — instructor toggle; when true the quiz/next-step gate
 *   enforces course-video completion server-side (TrainingService in 162-03).
 *
 * Index name constraints: all names <= 27 chars (MariaDB InnoDB limit).
 * No oc_ prefix in table names — NC auto-prepends the prefix.
 * All columns use Types::* constants — NC resolves per platform (PG16 + MariaDB 11.4).
 */
class Version009500Date20260701150000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // 1. learning_course_videos — gated-content registry (VIDEO-09)
        if (!$schema->hasTable('learning_course_videos')) {
            $table = $schema->createTable('learning_course_videos');
            $table->addColumn('id',               Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id',        Types::BIGINT, ['notnull' => true]);
            $table->addColumn('source_type',      Types::STRING, ['notnull' => true, 'length' => 20]); // nc-files|vimeo|youtube-nocookie|document
            $table->addColumn('video_ref',        Types::TEXT,   ['notnull' => true]);
            $table->addColumn('title',            Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('duration_seconds', Types::BIGINT, ['notnull' => false]); // admin-entered; NULL legit for documents
            $table->addColumn('subtitle_ref',     Types::TEXT,   ['notnull' => false]); // optional WebVTT path
            $table->addColumn('sort_order',       Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at',       Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['course_id'], 'learn_cv_course_idx'); // 19 chars OK
            $changed = true;
        }

        // 2. learning_video_progress — transient watch state + permanent completion proof (VIDEO-02/06)
        if (!$schema->hasTable('learning_video_progress')) {
            $table = $schema->createTable('learning_video_progress');
            $table->addColumn('id',            Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id',       Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('content_id',    Types::BIGINT, ['notnull' => true]); // -> learning_course_videos.id
            $table->addColumn('intervals_json', Types::TEXT,  ['notnull' => false]); // DSGVO-transient, cleared on completion
            $table->addColumn('covered_pct',   Types::FLOAT,  ['notnull' => false]); // working state, cleared on completion
            $table->addColumn('last_ping_ts',  Types::BIGINT, ['notnull' => false]); // <5s plausibility + CAS token; NULL = no ping yet
            $table->addColumn('completed_at',  Types::BIGINT, ['notnull' => false]); // permanent proof — the only long-term field
            $table->setPrimaryKey(['id']);
            // PLAIN unique — ONE row per (user, content). NO period column (recert seam intact).
            $table->addUniqueIndex(['user_id', 'content_id'], 'learn_vp_usr_cnt_uq'); // 19 chars OK
            $changed = true;
        }

        // 3. learning_courses.video_gate_enabled — instructor gate toggle
        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('video_gate_enabled')) {
                $table->addColumn('video_gate_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
