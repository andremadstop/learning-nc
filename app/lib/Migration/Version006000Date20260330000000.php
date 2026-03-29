<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds is_legacy to learning_user_badges and backfills it without deleting legacy rows.
 *
 * Note on repo reality vs handoff text:
 * - The real table is learning_user_badges, not learning_badges / oc_learning_badges.
 * - The real badge column is badge_id, not badge_key.
 * - The handoff/Gemini plan active badge keys diverge from the current BadgeService.
 *   To avoid falsely flagging still-active badges on existing learning-dev data, the
 *   migration keeps the union of both active key sets non-legacy and marks all others
 *   as legacy.
 */
class Version006000Date20260330000000 extends SimpleMigrationStep {
	private IDBConnection $db;

	private const CURRENT_ACTIVE_BADGE_IDS = [
		'streak_7',
		'pioneer',
		'streak_14',
		'mastermind',
		'exam_ready',
		'simulator',
		'weekend',
		'swarm',
		'trouble_fixer',
	];

	private const HANDOFF_ACTIVE_BADGE_IDS = [
		'streak_7',
		'pioneer',
		'simulator',
		'weekend',
		'swarm',
		'duel_king',
		'exam_ready',
		'trouble_fixer',
		'quick_thinker',
	];

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('learning_user_badges')) {
			return $schema;
		}

		$table = $schema->getTable('learning_user_badges');
		if (!$table->hasColumn('is_legacy')) {
			$table->addColumn('is_legacy', Types::BOOLEAN, [
				'notnull' => false,
				'default' => 0,
			]);
		}

		if (!$table->hasIndex('learn_badge_legacy_idx')) {
			$table->addIndex(['is_legacy'], 'learn_badge_legacy_idx');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('learning_user_badges')) {
			return;
		}

		$table = $schema->getTable('learning_user_badges');
		if (!$table->hasColumn('is_legacy')) {
			return;
		}

		$activeBadgeIds = array_values(array_unique(array_merge(
			self::CURRENT_ACTIVE_BADGE_IDS,
			self::HANDOFF_ACTIVE_BADGE_IDS
		)));

		$qbActive = $this->db->getQueryBuilder();
		$qbActive->update('learning_user_badges')
			->set('is_legacy', $qbActive->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where(
				$qbActive->expr()->in(
					'badge_id',
					$qbActive->createNamedParameter($activeBadgeIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		$qbActive->executeStatement();

		$qbLegacy = $this->db->getQueryBuilder();
		$qbLegacy->update('learning_user_badges')
			->set('is_legacy', $qbLegacy->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where(
				$qbLegacy->expr()->notIn(
					'badge_id',
					$qbLegacy->createNamedParameter($activeBadgeIds, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		$qbLegacy->executeStatement();
	}
}
