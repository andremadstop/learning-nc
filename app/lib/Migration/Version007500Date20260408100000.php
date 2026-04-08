<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 147: Course Archive & Merge.
 * Table learning_course_snapshots already exists from Phase 104 (Version005400).
 * This migration is intentionally a no-op — kept for version tracking only.
 */
class Version007500Date20260408100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }
}
