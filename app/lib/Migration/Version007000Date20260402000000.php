<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007000Date20260402000000 extends SimpleMigrationStep {
    private const BOX_DEFAULTS = [
        1 => ['stability' => 0.5, 'difficulty' => 0.8],
        2 => ['stability' => 1.0, 'difficulty' => 0.6],
        3 => ['stability' => 3.0, 'difficulty' => 0.5],
        4 => ['stability' => 7.0, 'difficulty' => 0.4],
        5 => ['stability' => 21.0, 'difficulty' => 0.3],
    ];

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_leitner_items')) {
            return null;
        }

        $table = $schema->getTable('learning_leitner_items');
        $changed = false;

        if (!$table->hasColumn('stability')) {
            $table->addColumn('stability', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('difficulty')) {
            $table->addColumn('difficulty', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('last_rating')) {
            $table->addColumn('last_rating', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_leitner_items')) {
            return;
        }

        $table = $schema->getTable('learning_leitner_items');
        if (!$table->hasColumn('stability') || !$table->hasColumn('difficulty') || !$table->hasColumn('last_rating')) {
            return;
        }

        foreach (self::BOX_DEFAULTS as $box => $values) {
            $intervalSeconds = (int) round($values['stability'] * 86400);
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_leitner_items')
                ->set('stability', $qb->createNamedParameter($values['stability']))
                ->set('difficulty', $qb->createNamedParameter($values['difficulty']))
                ->set('last_rating', $qb->createNamedParameter(null))
                ->set(
                    'next_review',
                    $qb->createFunction(
                        'CASE WHEN last_reviewed IS NULL THEN next_review ELSE last_reviewed + ' . $intervalSeconds . ' END'
                    )
                )
                ->where($qb->expr()->eq('box', $qb->createNamedParameter($box)))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull('stability'),
                        $qb->expr()->isNull('difficulty')
                    )
                );
            $qb->executeStatement();
        }
    }
}
