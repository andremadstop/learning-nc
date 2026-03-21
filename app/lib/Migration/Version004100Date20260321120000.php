<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Brettspiel-Backend — add board_state column to learning_gameshow_sessions.
 *
 * board_state stores a JSON blob with all game-board-specific data for the
 * turn-based modes 'lernwuerfel' (Mensch-ärgere-dich-nicht) and 'wissensturm'
 * (Trivial-Pursuit-style). Its schema differs per mode but always includes:
 *
 *   active_player_index  (int)   — slot of the player whose turn it is
 *   phase                (string) — 'roll' | 'question' | 'result'
 *   dice_result          (int|null) — last dice roll (1-6), null before first roll
 *
 * Lernwürfel-specific keys:
 *   positions            (object) — { "0": 0, "1": 0, ... }  slot → board field (0-30)
 *   special_effect       (string|null) — 'bonus_roll'|'shield'|'trap'|null
 *   skip_turns           (object) — { "0": 0, ... }  slot → remaining trapped turns
 *
 * Wissensturm-specific keys:
 *   towers               (object) — { "0": [], "1": [], ... }  slot → array of colour strings
 *   selected_category    (int|null) — pool_id of category player chose this turn
 *   steal_pending        (bool) — true when wrong answer triggers steal opportunity
 *   steal_from_slot      (int|null) — slot that triggered steal (lost block)
 */
class Version004100Date20260321120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_gameshow_sessions')) {
            return null;
        }

        $table = $schema->getTable('learning_gameshow_sessions');

        if ($table->hasColumn('board_state')) {
            return null;
        }

        $table->addColumn('board_state', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);

        return $schema;
    }
}
