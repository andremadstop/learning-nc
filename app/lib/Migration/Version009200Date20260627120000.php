<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 157 — Public-Verify: revocation tombstone column (VERIFY-05).
 *
 * Adds the nullable `revoked_at` column to the EXISTING learning_certificates table
 * (created by Version009100). This is the foundation the entire 157 tombstone + revoke
 * capability depends on: the public verify path reads `revoked_at` in the WITHDRAWN branch
 * ("…am [revoked_at] vom Aussteller widerrufen"), and the instructor-revoke write sets it.
 *
 * Why a separate migration (not folded into Version009100): Version009100 is already applied
 * LIVE on devcloud (PG16) and its schema has NO revoked_at — RESEARCH Pitfall 1 (SPEC ⊥ CODE).
 *
 * Constraints (mirror Version009100 style):
 *   - Table name WITHOUT oc_ prefix — NC auto-prepends.
 *   - This step ALTERS the table (getTable + addColumn) — it does NOT createTable.
 *   - revoked_at is a NULLABLE BIGINT (unix seconds), exactly like expires_at: existing rows
 *     stay valid (a SELECT * on a pre-upgrade row simply leaves revoked_at null), no default.
 *   - Touches NO other table or column.
 *
 * NOT applied live in this plan, and info.xml is NOT bumped here. Bumping info.xml would put
 * devcloud into needsDbUpgrade and a `--php-only` deploy (which rsyncs info.xml but does NOT run
 * `occ upgrade`) would show the maintenance page to live users. The bump + `occ upgrade` apply
 * travel together to the authorized provisioning pass (155-01 → 155-07 LIVE pattern).
 */
class Version009200Date20260627120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_certificates')) {
            $table = $schema->getTable('learning_certificates');
            if (!$table->hasColumn('revoked_at')) {
                $table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
