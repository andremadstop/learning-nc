<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ticket-Triage — add AI triage columns to learning_support_tickets.
 *
 * ai_label:              classification label (FAQ/Bug/Feature/Unclear)
 * ai_confidence:         classification confidence score (0.0–1.0)
 * ai_draft_answer:       AI-generated draft answer for FAQ tickets
 * ai_followup_question:  follow-up question to user when confidence < 0.7
 * needs_review:          true for Bug/Feature tickets (priority flag in admin inbox)
 */
class Version003900Date20260321000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_support_tickets')) {
            return null;
        }

        $table = $schema->getTable('learning_support_tickets');
        $changed = false;

        if (!$table->hasColumn('ai_label')) {
            $table->addColumn('ai_label', Types::STRING, [
                'length' => 32,
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('ai_confidence')) {
            $table->addColumn('ai_confidence', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('ai_draft_answer')) {
            $table->addColumn('ai_draft_answer', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('ai_followup_question')) {
            $table->addColumn('ai_followup_question', Types::STRING, [
                'length' => 1000,
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('needs_review')) {
            $table->addColumn('needs_review', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
