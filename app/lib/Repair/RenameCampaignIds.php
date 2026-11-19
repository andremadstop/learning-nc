<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\Exception as DbException;
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
 *
 * One row that cannot be changed does not stop the others: its UPDATE error is caught, the row
 * keeps its old value (nothing is lost) and a warning names table and id; the next
 * `occ maintenance:repair` or upgrade tries it again. Nextcloud runs repair steps without a
 * transaction (OC_App::executeRepairSteps, Repair::run), so a caught failure cannot poison later
 * statements on PostgreSQL. A course list that is not readable JSON but names an old id is left
 * as it is and reported. A list stored as a JSON object (the app writes a list; the frontend
 * reads an object as "all campaigns") stays an object with its keys.
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
            [$renamed, $kept] = $this->renamePerLearner($table, $output);
            if ($renamed > 0) {
                $output->info(sprintf('%s: %d row(s) moved to the new campaign id.', $table, $renamed));
            }
            if ($kept > 0) {
                $output->info(sprintf('%s: %d row(s) under an old campaign id left unchanged, the learner already has a row under the new id.', $table, $kept));
            }
        }

        $coop = $this->renameCoopSessions($output);
        if ($coop > 0) {
            $output->info(sprintf('%s: %d session(s) moved to the new campaign id.', self::COOP_TABLE, $coop));
        }

        $courses = $this->renameInCourseSelections($output);
        if ($courses > 0) {
            $output->info(sprintf('%s: campaign selection of %d course(s) updated.', self::COURSE_TABLE, $courses));
        }
    }

    /**
     * @return array{0: int, 1: int} renamed, left unchanged because of an existing new row
     */
    private function renamePerLearner(string $table, IOutput $output): array {
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
            if (!$this->execute($update, $output, $table, 'id ' . (int)$row['id'])) {
                continue;
            }
            $present[$user][$new] = true;
            $renamed++;
        }

        return [$renamed, $kept];
    }

    private function renameCoopSessions(IOutput $output): int {
        $select = $this->db->getQueryBuilder();
        $select->select('id', 'campaign_id')
            ->from(self::COOP_TABLE)
            ->where($select->expr()->in('campaign_id', $select->createNamedParameter(array_keys(self::RENAMED), IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('id', 'ASC');
        $result = $select->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $row;
        }
        $result->closeCursor();

        $changed = 0;
        foreach ($rows as $row) {
            $new = self::RENAMED[(string)$row['campaign_id']] ?? null;
            if ($new === null) {
                continue;
            }
            $update = $this->db->getQueryBuilder();
            $update->update(self::COOP_TABLE)
                ->set('campaign_id', $update->createNamedParameter($new))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            if ($this->execute($update, $output, self::COOP_TABLE, 'id ' . (int)$row['id'])) {
                $changed++;
            }
        }
        return $changed;
    }

    private function renameInCourseSelections(IOutput $output): int {
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
            $raw = (string)$row['allowed_campaigns'];
            // objects stay objects: decoded without $associative, an object is a stdClass
            $decoded = json_decode($raw);
            $isObject = $decoded instanceof \stdClass;
            $list = $isObject ? get_object_vars($decoded) : (is_array($decoded) ? json_decode($raw, true) : null);
            if (!is_array($list)) {
                foreach (array_keys(self::RENAMED) as $old) {
                    if (str_contains($raw, $old)) {
                        $output->warning(sprintf('%s: campaign selection of course id %d is not a readable JSON list and names an old campaign id; left unchanged.', self::COURSE_TABLE, (int)$row['id']));
                        break;
                    }
                }
                continue;
            }
            if (!array_intersect(array_keys(self::RENAMED), array_filter($list, 'is_string'))) {
                continue;
            }
            $mapped = [];
            foreach ($list as $key => $id) {
                $id = is_string($id) ? (self::RENAMED[$id] ?? $id) : $id;
                if (in_array($id, $mapped, true)) {
                    continue;
                }
                if ($isObject) {
                    $mapped[$key] = $id;
                } else {
                    $mapped[] = $id;
                }
            }
            $json = json_encode($isObject ? (object)$mapped : $mapped);
            if ($json === false) {
                $output->warning(sprintf('%s: campaign selection of course id %d could not be written back; left unchanged.', self::COURSE_TABLE, (int)$row['id']));
                continue;
            }
            $update = $this->db->getQueryBuilder();
            $update->update(self::COURSE_TABLE)
                ->set('allowed_campaigns', $update->createNamedParameter($json))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            if ($this->execute($update, $output, self::COURSE_TABLE, 'course id ' . (int)$row['id'])) {
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Runs one UPDATE; a database error is reported and the row keeps its old value.
     */
    private function execute(IQueryBuilder $update, IOutput $output, string $table, string $row): bool {
        try {
            $update->executeStatement();
            return true;
        } catch (DbException $e) {
            $output->warning(sprintf('%s: %s could not be moved to the new campaign id and keeps the old one (%s). The next "occ maintenance:repair" tries again.', $table, $row, $e->getMessage()));
            return false;
        }
    }
}
