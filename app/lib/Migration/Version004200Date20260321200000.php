<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Story-Engine Backend — create learning_story_progress table.
 *
 * Stores per-user, per-campaign RPG progress:
 *   user_id          — Nextcloud user ID
 *   campaign_id      — string key matching campaign JSON filename (e.g. "grosser_ausfall")
 *   current_scene_id — ID of the current scene in the campaign (e.g. "s1_ankunft")
 *   character_class  — chosen class: architect|security|sysadmin|helpdesk
 *   choices_json     — JSON array of {scene_id, choice_id, skill_result} objects
 *   score            — accumulated skill-check score (correct answers)
 *   status           — in_progress|completed|abandoned
 *   coop_user_ids    — JSON array of additional user IDs in coop sessions (nullable)
 *   created_at       — Unix timestamp of campaign start
 *   updated_at       — Unix timestamp of last action
 */
class Version004200Date20260321200000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_story_progress')) {
            return null;
        }

        $table = $schema->createTable('learning_story_progress');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => false,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'length' => 64,
            'notnull' => false,
        ]);
        $table->addColumn('campaign_id', Types::STRING, [
            'length' => 128,
            'notnull' => false,
        ]);
        $table->addColumn('current_scene_id', Types::STRING, [
            'length' => 128,
            'notnull' => false,
        ]);
        $table->addColumn('character_class', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'default' => 'helpdesk',
        ]);
        $table->addColumn('choices_json', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('score', Types::INTEGER, [
            'notnull' => false,
            'default' => 0,
        ]);
        $table->addColumn('status', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'default' => 'in_progress',
        ]);
        $table->addColumn('coop_user_ids', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::INTEGER, [
            'notnull' => false,
            'default' => 0,
        ]);
        $table->addColumn('updated_at', Types::INTEGER, [
            'notnull' => false,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'campaign_id'], 'lrn_story_user_camp_idx');
        $table->addIndex(['user_id', 'status'], 'lrn_story_user_stat_idx');

        return $schema;
    }
}
