<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * 5.16.0: two bundled adventure campaigns got new ids, because the old ones named a third-party
 * certification product. A campaign's id is its file name (StoryEngineService::loadCampaign loads
 * `<id>.json`) and the key every saved game is stored under, so without this step a learner
 * would lose the progress of both campaigns and a course would lose them from its campaign list.
 *
 * The old ids below are the one place in the shipped package that still names them (documented
 * exception of the release check); they have to stay readable here to find the old rows.
 *
 * Every column that stores a campaign id (found from the write paths, nothing else stores one):
 * - learning_story_progress.campaign_id (StoryEngineService::startCampaign), one row per learner
 *   and campaign (StoryProgressMapper::findByUserAndCampaign reads one);
 * - learning_campaign_state.campaign_id (CampaignGraphService), unique per learner and campaign;
 * - learning_coop_sessions.campaign_id (CoopService::createSession), no per-learner key;
 * - learning_courses.allowed_campaigns, a JSON list of ids (CourseController::updateCampaignSelection).
 * state_bag, choices_json and the coop players/votes hold flags, scene/node ids and question ids,
 * never a campaign id. Audit and activity rows store none either.
 *
 * Per learner table: a row under an old id is renamed, unless the same learner already has a row
 * under the new id. That row is the one the app reads, so the old row is left as it is (nothing
 * reads it, the data export still lists it, deleting the account removes it) and counted; the
 * step never deletes progress and never writes a second row for one learner and campaign (the
 * unique key of learning_campaign_state). The check runs in PHP before the UPDATE: a failed
 * UPDATE inside PostgreSQL's transaction would poison it (25P02).
 *
 * Rows are changed by primary key, values are bound parameters, no quoting of identifiers:
 * the same on PostgreSQL and MySQL/MariaDB. Running the step again changes nothing.
 * Post-migration only: a fresh install has no saved games and no course selections.
 */
class RenameCampaignIds implements IRepairStep {
    /** old id => new id */
    public const RENAMED = [
        'cysa_zero_day' => 'unsichtbare_bedrohung',
        'a_plus_erster_tag' => 'erster_tag',
    ];

    /** tables with one row per learner and campaign */
    public const PER_LEARNER_TABLES = ['learning_story_progress', 'learning_campaign_state'];

    public const COOP_TABLE = 'learning_coop_sessions';
    public const COURSE_TABLE = 'learning_courses';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function getName(): string {
        return 'Move saved adventure games and course campaign lists to the renamed campaigns';
    }

    public function run(IOutput $output): void {
        foreach (self::PER_LEARNER_TABLES as $table) {
            [$renamed, $kept] = $this->renamePerLearner($table);
            if ($renamed > 0) {
                $output->info(sprintf('%s: %d row(s) moved to the new campaign id.', $table, $renamed));
            }
            if ($kept > 0) {
                $output->info(sprintf('%s: %d row(s) under an old campaign id left unchanged, the learner already has a row under the new id.', $table, $kept));
            }
        }

        $coop = $this->renameCoopSessions();
        if ($coop > 0) {
            $output->info(sprintf('%s: %d session(s) moved to the new campaign id.', self::COOP_TABLE, $coop));
        }

        $courses = $this->renameInCourseSelections();
        if ($courses > 0) {
            $output->info(sprintf('%s: campaign selection of %d course(s) updated.', self::COURSE_TABLE, $courses));
        }
    }

    /**
     * @return array{0: int, 1: int} renamed, left unchanged because of an existing new row
     */
    private function renamePerLearner(string $table): array {
        $ids = array_merge(array_keys(self::RENAMED), array_values(self::RENAMED));
        $select = $this->db->getQueryBuilder();
        $select->select('id', 'user_id', 'campaign_id')
            ->from($table)
            ->where($select->expr()->in('campaign_id', $select->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('id', 'ASC');
        $result = $select->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $row;
        }
        $result->closeCursor();

        // campaign ids each learner has a row under (exact comparison, in PHP)
        $present = [];
        foreach ($rows as $row) {
            $present[(string)$row['user_id']][(string)$row['campaign_id']] = true;
        }

        $renamed = 0;
        $kept = 0;
        foreach ($rows as $row) {
            $old = (string)$row['campaign_id'];
            $new = self::RENAMED[$old] ?? null;
            if ($new === null) {
                continue;
            }
            $user = (string)$row['user_id'];
            if (isset($present[$user][$new])) {
                $kept++;
                continue;
            }
            $update = $this->db->getQueryBuilder();
            $update->update($table)
                ->set('campaign_id', $update->createNamedParameter($new))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            $update->executeStatement();
            $present[$user][$new] = true;
            $renamed++;
        }

        return [$renamed, $kept];
    }

    private function renameCoopSessions(): int {
        $changed = 0;
        foreach (self::RENAMED as $old => $new) {
            $update = $this->db->getQueryBuilder();
            $update->update(self::COOP_TABLE)
                ->set('campaign_id', $update->createNamedParameter($new))
                ->where($update->expr()->eq('campaign_id', $update->createNamedParameter($old)));
            $changed += $update->executeStatement();
        }
        return $changed;
    }

    private function renameInCourseSelections(): int {
        $select = $this->db->getQueryBuilder();
        $select->select('id', 'allowed_campaigns')
            ->from(self::COURSE_TABLE)
            ->where($select->expr()->isNotNull('allowed_campaigns'))
            ->orderBy('id', 'ASC');
        $result = $select->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $row;
        }
        $result->closeCursor();

        $changed = 0;
        foreach ($rows as $row) {
            $list = json_decode((string)$row['allowed_campaigns'], true);
            if (!is_array($list) || !array_intersect(array_keys(self::RENAMED), array_filter($list, 'is_string'))) {
                continue;
            }
            $mapped = [];
            foreach ($list as $id) {
                $id = is_string($id) ? (self::RENAMED[$id] ?? $id) : $id;
                if (!in_array($id, $mapped, true)) {
                    $mapped[] = $id;
                }
            }
            $update = $this->db->getQueryBuilder();
            $update->update(self::COURSE_TABLE)
                ->set('allowed_campaigns', $update->createNamedParameter(json_encode($mapped)))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            $update->executeStatement();
            $changed++;
        }
        return $changed;
    }
}
