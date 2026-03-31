<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006500Date20260331000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_user_badges')) {
            return;
        }

        $table = $schema->getTable('learning_user_badges');
        if (!$table->hasColumn('badge_id')) {
            return;
        }

        $legacyUsers = $this->fetchUsersByBadge('streak_30');
        if ($legacyUsers === []) {
            $this->markStreak14AsActive($table->hasColumn('is_legacy'));
            return;
        }

        $activeUsers = array_fill_keys($this->fetchUsersByBadge('streak_14'), true);
        $renameUsers = [];
        $duplicateUsers = [];

        foreach ($legacyUsers as $userId) {
            if (isset($activeUsers[$userId])) {
                $duplicateUsers[] = $userId;
                continue;
            }

            $renameUsers[] = $userId;
        }

        if ($renameUsers !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_user_badges')
                ->set('badge_id', $qb->createNamedParameter('streak_14'))
                ->where($qb->expr()->eq('badge_id', $qb->createNamedParameter('streak_30')))
                ->andWhere(
                    $qb->expr()->in(
                        'user_id',
                        $qb->createNamedParameter($renameUsers, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );

            if ($table->hasColumn('is_legacy')) {
                $qb->set('is_legacy', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL));
            }

            $qb->executeStatement();
        }

        if ($duplicateUsers !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_user_badges')
                ->where($qb->expr()->eq('badge_id', $qb->createNamedParameter('streak_30')))
                ->andWhere(
                    $qb->expr()->in(
                        'user_id',
                        $qb->createNamedParameter($duplicateUsers, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );
            $qb->executeStatement();
        }

        $this->markStreak14AsActive($table->hasColumn('is_legacy'));
    }

    /**
     * @return string[]
     */
    private function fetchUsersByBadge(string $badgeId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('learning_user_badges')
            ->where($qb->expr()->eq('badge_id', $qb->createNamedParameter($badgeId)));

        $result = $qb->executeQuery();
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return $users;
    }

    private function markStreak14AsActive(bool $hasLegacyColumn): void {
        if (!$hasLegacyColumn) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_badges')
            ->set('is_legacy', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('badge_id', $qb->createNamedParameter('streak_14')));
        $qb->executeStatement();
    }
}
