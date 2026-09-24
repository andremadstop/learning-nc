<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Practice exams drawn from required pools only (Codeberg #9, follow-up).
 *
 * learning_courses:
 *   practice_required_only — draw practice exam questions only from pools marked "Required",
 *                            so supplementary pools stay available for self-study without
 *                            entering the exam simulation.
 *
 * In changeSchema(), like Version010200: a fresh install runs only that (see Version010100).
 */
class Version010300Date20260924120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_courses')) {
            return null;
        }
        $table = $schema->getTable('learning_courses');
        if ($table->hasColumn('practice_required_only')) {
            return null;
        }
        // Nextcloud rejects NOT NULL booleans; same shape as practice_enabled.
        $table->addColumn('practice_required_only', Types::BOOLEAN, [
            'notnull' => false,
            'default' => false,
        ]);

        return $schema;
    }
}
