<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #43: the course decides how its certificates look (introduction text with placeholders,
 * which elements are shown, an optional stamp and signature image with the signer's name).
 *
 * learning_courses.cert_layout          JSON of the course's current layout, NULL = the standard card
 * learning_certificates.layout_json     the layout as it was when the certificate was issued, NULL =
 *                                       the standard card (every certificate issued before 5.13.0)
 * learning_cert_assets                  stamp / signature PNGs, content-addressed per course
 *                                       (course_id + sha256), never overwritten, so an issued
 *                                       certificate keeps the image it was issued with
 *
 * The signed credential is not touched: the layout is presentation, not a claim.
 * Nullable columns and a new table without seed data, so changeSchema() alone covers fresh installs
 * and upgrades.
 */
class Version010800Date20261115120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('cert_layout')) {
                $table->addColumn('cert_layout', Types::TEXT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_certificates')) {
            $table = $schema->getTable('learning_certificates');
            if (!$table->hasColumn('layout_json')) {
                $table->addColumn('layout_json', Types::TEXT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('learning_cert_assets')) {
            $table = $schema->createTable('learning_cert_assets');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('byte_size', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            // base64 of the PNG; TEXT is LONGTEXT on MySQL/MariaDB, so the size cap is the service's.
            $table->addColumn('data_b64', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'sha256'], 'lrn_certasset_course_sha');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
