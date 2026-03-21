<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Chat-Memory — create learning_ai_chat_memory table.
 *
 * Stores per-user VirtuProf conversation history so that context persists
 * across browser sessions. Max 50 entries per user enforced in application layer
 * (AiChatMemoryService) — oldest entries are compressed into a summary row
 * before the cap is exceeded.
 *
 * Columns:
 *   id          — auto-increment PK
 *   user_id     — Nextcloud user ID (VARCHAR 64)
 *   role        — 'user' | 'assistant' | 'summary'
 *   message     — message text (TEXT)
 *   created_at  — Unix timestamp (BIGINT)
 *
 * Index: (user_id, created_at) — supports ORDER BY created_at DESC per user.
 */
class Version004000Date20260321000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_ai_chat_memory')) {
            return null;
        }

        $table = $schema->createTable('learning_ai_chat_memory');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->addColumn('user_id', Types::STRING, [
            'length' => 64,
            'notnull' => true,
        ]);

        $table->addColumn('role', Types::STRING, [
            'length' => 16,
            'notnull' => true,
        ]);

        $table->addColumn('message', Types::TEXT, [
            'notnull' => true,
        ]);

        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'created_at'], 'learning_ai_chat_mem_uid_ts');

        return $schema;
    }
}
