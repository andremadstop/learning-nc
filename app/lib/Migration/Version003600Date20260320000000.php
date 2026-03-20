<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * DB Audit Fix — 2026-03-20
 *
 * Corrects issues found during schema audit:
 *
 * 1. learning_duel_invites: add index on pool_id (missing, used in JOIN queries)
 * 2. learning_league_results: add indices on player_a_uid and player_b_uid
 *    (needed for per-player result lookups without full table scan)
 * 3. learning_support_tickets: add index on routing_course_id
 *    (column added in v003500 without index)
 * 4. learning_duel_answers: fix answered_at type from integer → bigint
 *    (Version002600 silently failed because it used the prefixed table name
 *    'oc_learning_duel_answers' in ISchemaWrapper which does not use prefixes)
 * 5. learning_league_challenges: add composite index on (season_id, challenger_uid, opponent_uid)
 *    for queries that need to find all challenges involving a specific user in a season
 *    without scanning two separate indices.
 */
class Version003600Date20260320000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // Fix 1: Index on duel_invites.pool_id
        if ($schema->hasTable('learning_duel_invites')) {
            $table = $schema->getTable('learning_duel_invites');
            if (!$table->hasIndex('learn_duel_invites_pool_idx')) {
                $table->addIndex(['pool_id'], 'learn_duel_invites_pool_idx');
                $output->info('Added index learn_duel_invites_pool_idx');
                $changed = true;
            }
        }

        // Fix 2: Indices on league_results player columns
        if ($schema->hasTable('learning_league_results')) {
            $table = $schema->getTable('learning_league_results');
            if (!$table->hasIndex('llr_player_a_idx')) {
                $table->addIndex(['season_id', 'player_a_uid'], 'llr_player_a_idx');
                $output->info('Added index llr_player_a_idx');
                $changed = true;
            }
            if (!$table->hasIndex('llr_player_b_idx')) {
                $table->addIndex(['season_id', 'player_b_uid'], 'llr_player_b_idx');
                $output->info('Added index llr_player_b_idx');
                $changed = true;
            }
        }

        // Fix 3: Index on support_tickets.routing_course_id
        if ($schema->hasTable('learning_support_tickets')) {
            $table = $schema->getTable('learning_support_tickets');
            if (!$table->hasIndex('learn_ticket_route_crs_idx')) {
                $table->addIndex(['routing_course_id'], 'learn_ticket_route_crs_idx');
                $output->info('Added index learn_ticket_route_crs_idx');
                $changed = true;
            }
        }

        // Fix 4: Correct duel_answers.answered_at to bigint
        // Version002600 silently failed because it used the prefixed name in ISchemaWrapper.
        // ISchemaWrapper table names must NOT include the db prefix.
        if ($schema->hasTable('learning_duel_answers')) {
            $table = $schema->getTable('learning_duel_answers');
            if ($table->hasColumn('answered_at')) {
                $col = $table->getColumn('answered_at');
                // Only change if it's still an integer (not already bigint)
                if ($col->getType()->getName() !== Types::BIGINT) {
                    $col->setType(\Doctrine\DBAL\Types\Type::getType(Types::BIGINT));
                    $col->setOptions(['notnull' => false, 'unsigned' => false]);
                    $output->info('Fixed duel_answers.answered_at type to bigint');
                    $changed = true;
                }
            }
        }

        // Fix 5: Composite index for league_challenges by user across a season
        if ($schema->hasTable('learning_league_challenges')) {
            $table = $schema->getTable('learning_league_challenges');
            if (!$table->hasIndex('llc_season_player_idx')) {
                $table->addIndex(['season_id', 'challenger_uid', 'opponent_uid'], 'llc_season_player_idx');
                $output->info('Added index llc_season_player_idx');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
