<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Db\CampaignStateMapper;
use OCA\Learning\Db\StoryProgressMapper;
use OCA\Learning\Repair\RenameCampaignIds;
use OCA\Learning\Tests\Integration\Support\RecordingOutput;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 5.16.0: two campaigns got new ids. The upgrade moves saved games, coop sessions and course
 * campaign lists to them, on the real database of both engines (nc-integration.sh pgsql/mysql):
 *
 * - every learner's row is renamed and read back through the app's own mappers under the new id;
 * - a learner who already has a row under the new id keeps it, the old row stays (no unique-key
 *   violation on learning_campaign_state, which on PostgreSQL would poison the transaction);
 * - course lists are rewritten without duplicates, a list stored as a JSON object stays one,
 *   other courses keep their exact JSON;
 * - a second run changes nothing.
 */
final class RenameCampaignIdsTest extends TestCase {
    private const MARK = 'rename-campaign-probe';

    private IDBConnection $db;
    /** @var array<string, list<int>> table => ids this test inserted */
    private array $ids = [];

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    private function old(int $n): string {
        return array_keys(RenameCampaignIds::RENAMED)[$n];
    }

    private function new(int $n): string {
        return array_values(RenameCampaignIds::RENAMED)[$n];
    }

    private function user(string $name): string {
        return self::MARK . '-' . $name;
    }

    public function testSavedGamesCoopSessionsAndCourseListsMoveToTheNewIds(): void {
        $now = time();
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        foreach ([[$alice, $this->old(0), 7], [$alice, $this->old(1), 3], [$bob, 'wannacry', 5]] as [$uid, $campaign, $score]) {
            $this->insert('learning_story_progress', ['user_id' => $uid, 'campaign_id' => $campaign, 'current_scene_id' => 's2', 'score' => $score, 'created_at' => $now, 'updated_at' => $now]);
            $this->insert('learning_campaign_state', ['user_id' => $uid, 'campaign_id' => $campaign, 'graph_position' => 'n4', 'state_bag' => '{"flags":{"seen":true}}', 'score' => $score, 'created_at' => $now, 'updated_at' => $now]);
        }
        $coopOld = $this->insert('learning_coop_sessions', $this->coop($this->old(0), 'RCP001'));
        $coopOther = $this->insert('learning_coop_sessions', $this->coop('log4shell', 'RCP002'));
        $courseOld = $this->insert('learning_courses', $this->course(json_encode(['wannacry', $this->old(0), $this->old(1), $this->new(1)])));
        $courseOther = $this->insert('learning_courses', $this->course('["wannacry", "log4shell"]'));
        $courseAll = $this->insert('learning_courses', $this->course(null));
        $courseObject = $this->insert('learning_courses', $this->course('{"1":"' . $this->old(0) . '","3":"wannacry"}'));

        $output = new RecordingOutput();
        Server::get(RenameCampaignIds::class)->run($output);

        $progress = Server::get(StoryProgressMapper::class);
        $state = Server::get(CampaignStateMapper::class);
        foreach ([0 => 7, 1 => 3] as $n => $score) {
            $this->assertNull($progress->findByUserAndCampaign($alice, $this->old($n)));
            $this->assertSame($score, (int)$progress->findByUserAndCampaign($alice, $this->new($n))?->getScore());
            $this->assertSame('s2', $progress->findByUserAndCampaign($alice, $this->new($n))?->getCurrentSceneId());
            $this->assertNull($state->findByUserAndCampaign($alice, $this->old($n)));
            $moved = $state->findByUserAndCampaign($alice, $this->new($n));
            $this->assertSame('n4', $moved?->getGraphPosition());
            $this->assertSame('{"flags":{"seen":true}}', $moved?->getStateBag());
        }
        $this->assertNotNull($progress->findByUserAndCampaign($bob, 'wannacry'));

        $this->assertSame($this->new(0), $this->value('learning_coop_sessions', 'campaign_id', $coopOld));
        $this->assertSame('log4shell', $this->value('learning_coop_sessions', 'campaign_id', $coopOther));
        $this->assertSame(['wannacry', $this->new(0), $this->new(1)], json_decode((string)$this->value('learning_courses', 'allowed_campaigns', $courseOld), true));
        $this->assertSame('["wannacry", "log4shell"]', $this->value('learning_courses', 'allowed_campaigns', $courseOther));
        $this->assertNull($this->value('learning_courses', 'allowed_campaigns', $courseAll));
        // a list stored as an object (the frontend reads it as "all campaigns") stays an object
        $this->assertSame('{"1":"' . $this->new(0) . '","3":"wannacry"}', $this->value('learning_courses', 'allowed_campaigns', $courseObject));

        // second run: nothing left to move, nothing said, nothing changed
        $before = $this->snapshot();
        $again = new RecordingOutput();
        Server::get(RenameCampaignIds::class)->run($again);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], array_filter($again->infos, fn (string $m) => str_contains($m, 'moved') || str_contains($m, 'updated')));
    }

    public function testALearnerWithARowUnderTheNewIdKeepsBothRowsAndTheStepDoesNotFail(): void {
        $now = time();
        $carol = $this->user('carol');
        $dave = $this->user('dave');
        $oldState = $this->insert('learning_campaign_state', ['user_id' => $carol, 'campaign_id' => $this->old(0), 'graph_position' => 'old', 'state_bag' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $newState = $this->insert('learning_campaign_state', ['user_id' => $carol, 'campaign_id' => $this->new(0), 'graph_position' => 'new', 'state_bag' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $daveState = $this->insert('learning_campaign_state', ['user_id' => $dave, 'campaign_id' => $this->old(0), 'graph_position' => 'd', 'state_bag' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $oldProgress = $this->insert('learning_story_progress', ['user_id' => $carol, 'campaign_id' => $this->old(0), 'current_scene_id' => 'old', 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('learning_story_progress', ['user_id' => $carol, 'campaign_id' => $this->new(0), 'current_scene_id' => 'new', 'created_at' => $now, 'updated_at' => $now]);

        $output = new RecordingOutput();
        Server::get(RenameCampaignIds::class)->run($output);

        $this->assertSame($this->old(0), $this->value('learning_campaign_state', 'campaign_id', $oldState));
        $this->assertSame($this->new(0), $this->value('learning_campaign_state', 'campaign_id', $newState));
        $this->assertSame($this->new(0), $this->value('learning_campaign_state', 'campaign_id', $daveState));
        $this->assertSame($this->old(0), $this->value('learning_story_progress', 'campaign_id', $oldProgress));
        $this->assertSame('new', Server::get(CampaignStateMapper::class)->findByUserAndCampaign($carol, $this->new(0))?->getGraphPosition());
        $this->assertSame('new', Server::get(StoryProgressMapper::class)->findByUserAndCampaign($carol, $this->new(0))?->getCurrentSceneId());
        $this->assertNotEmpty(array_filter($output->infos, fn (string $m) => str_contains($m, 'left unchanged')));

        // the connection is still usable (a failed UPDATE would have poisoned it on PostgreSQL)
        $this->assertSame('d', $this->value('learning_campaign_state', 'graph_position', $daveState));
    }

    /** @return array<string, mixed> */
    private function coop(string $campaign, string $code): array {
        return ['campaign_id' => $campaign, 'host_user_id' => $this->user('host'), 'session_code' => $code, 'status' => 'lobby', 'current_node_id' => 'start', 'state_bag' => '{}', 'created_at' => time(), 'last_heartbeat' => time(), 'max_players' => 4];
    }

    /** @return array<string, mixed> */
    private function course(?string $allowed): array {
        $values = ['title' => self::MARK . ' course', 'instructor_id' => $this->user('teacher'), 'created_at' => time(), 'updated_at' => time()];
        if ($allowed !== null) {
            $values['allowed_campaigns'] = $allowed;
        }
        return $values;
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values): int {
        $qb = $this->db->getQueryBuilder();
        $params = [];
        foreach ($values as $column => $value) {
            $params[$column] = $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
        }
        $qb->insert($table)->values($params)->executeStatement();
        $id = $qb->getLastInsertId();
        $this->ids[$table][] = $id;
        return $id;
    }

    private function value(string $table, string $column, int $id): mixed {
        $qb = $this->db->getQueryBuilder();
        $qb->select($column)->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $value = $result->fetchOne();
        $result->closeCursor();
        return $value === false ? null : $value;
    }

    /** @return array<string, array<int, mixed>> */
    private function snapshot(): array {
        $out = [];
        foreach ($this->ids as $table => $ids) {
            $column = $table === 'learning_courses' ? 'allowed_campaigns' : 'campaign_id';
            foreach ($ids as $id) {
                $out[$table][$id] = $this->value($table, $column, $id);
            }
        }
        return $out;
    }

    private function cleanUp(): void {
        foreach (['learning_story_progress', 'learning_campaign_state'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->like('user_id', $qb->createNamedParameter($this->db->escapeLikeParameter(self::MARK) . '%')))->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_coop_sessions')->where($qb->expr()->like('host_user_id', $qb->createNamedParameter($this->db->escapeLikeParameter(self::MARK) . '%')))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_courses')->where($qb->expr()->like('instructor_id', $qb->createNamedParameter($this->db->escapeLikeParameter(self::MARK) . '%')))->executeStatement();
        $this->ids = [];
    }
}
