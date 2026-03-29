<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/**
 * Data migration: encrypts existing bio and telos_json values in learning_user_telos.
 *
 * Runs in postSchemaChange (no schema changes needed).
 * Uses ICrypto directly via server container since migrations cannot use DI.
 */
class Version006100Date20260330000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_user_telos')) {
            return;
        }

        /** @var ICrypto $crypto */
        $crypto = \OC::$server->get(ICrypto::class);

        // Select all rows with non-null bio or telos_json
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'bio', 'telos_json')
            ->from('learning_user_telos')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->isNotNull('bio'),
                    $qb->expr()->isNotNull('telos_json')
                )
            );
        $result = $qb->executeQuery();

        $migrated = 0;
        while ($row = $result->fetch()) {
            $id = (int)$row['id'];
            $bio = $row['bio'];
            $telosJson = $row['telos_json'];

            // Skip if both are null or empty
            if (($bio === null || $bio === '') && ($telosJson === null || $telosJson === '')) {
                continue;
            }

            // Skip if already encrypted (ICrypto encrypted strings contain '|')
            // This makes the migration idempotent
            $bioAlreadyEncrypted = $bio !== null && $bio !== '' && str_contains($bio, '|');
            $telosAlreadyEncrypted = $telosJson !== null && $telosJson !== '' && str_contains($telosJson, '|');

            $needsUpdate = false;
            $updateQb = $this->db->getQueryBuilder();
            $updateQb->update('learning_user_telos')
                ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($id)));

            if ($bio !== null && $bio !== '' && !$bioAlreadyEncrypted) {
                $updateQb->set('bio', $updateQb->createNamedParameter($crypto->encrypt($bio)));
                $needsUpdate = true;
            }

            if ($telosJson !== null && $telosJson !== '' && !$telosAlreadyEncrypted) {
                $updateQb->set('telos_json', $updateQb->createNamedParameter($crypto->encrypt($telosJson)));
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updateQb->executeStatement();
                $migrated++;
            }
        }
        $result->closeCursor();

        $output->info("Encrypted bio/telos_json for {$migrated} user_telos rows.");
    }
}
