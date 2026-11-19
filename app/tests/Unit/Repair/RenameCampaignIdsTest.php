<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\RenameCampaignIds;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * 5.16.0: two campaigns got new ids; saved games, coop sessions and course campaign lists move
 * with them. The fake database keeps the four tables as rows, applies the step's UPDATEs and
 * enforces the unique key of learning_campaign_state (user_id + campaign_id), so an UPDATE into an
 * existing row fails here as it would on the server. The real engines: tests/Integration/
 * Migration/RenameCampaignIdsTest.php.
 */
#[AllowMockObjectsWithoutExpectations]
class RenameCampaignIdsTest extends TestCase {
    private const APP = __DIR__ . '/../../..';

    /** @var array<string, array<int, array<string, mixed>>> table => id => row */
    private array $tables = [];

    /** @var array<string, list<int>> table => row ids whose UPDATE fails like a database error */
    private array $failing = [];

    private function oldId(int $n): string {
        return array_keys(RenameCampaignIds::RENAMED)[$n];
    }

    private function newId(int $n): string {
        return array_values(RenameCampaignIds::RENAMED)[$n];
    }

    private function step(): RenameCampaignIds {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
        return new RenameCampaignIds($db);
    }

    private function queryBuilder(): IQueryBuilder {
        $q = ['op' => null, 'table' => null, 'set' => [], 'where' => null];
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn ($col, $val) => ['eq', $col, $val]);
        $expr->method('in')->willReturnCallback(fn ($col, $val) => ['in', $col, $val]);
        $expr->method('isNotNull')->willReturnCallback(fn ($col) => ['notnull', $col, null]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('select')->willReturnCallback(function () use ($qb, &$q) { $q['op'] = 'select'; return $qb; });
        $qb->method('from')->willReturnCallback(function ($t) use ($qb, &$q) { $q['table'] = $t; return $qb; });
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('update')->willReturnCallback(function ($t) use ($qb, &$q) { $q['op'] = 'update'; $q['table'] = $t; return $qb; });
        $qb->method('set')->willReturnCallback(function ($c, $v) use ($qb, &$q) { $q['set'][$c] = $v; return $qb; });
        $qb->method('where')->willReturnCallback(function ($w) use ($qb, &$q) { $q['where'] = $w; return $qb; });

        $qb->method('executeQuery')->willReturnCallback(function () use (&$q) {
            $this->assertSame('select', $q['op']);
            $rows = array_values(array_filter($this->tables[$q['table']] ?? [], fn (array $row) => $this->rowMatches($row, $q['where'])));
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnCallback(function () use (&$rows) {
                return array_shift($rows) ?? false;
            });
            return $result;
        });

        $qb->method('executeStatement')->willReturnCallback(function () use (&$q) {
            $this->assertSame('update', $q['op']);
            $table = $q['table'];
            $count = 0;
            foreach ($this->tables[$table] ?? [] as $id => $row) {
                if (!$this->rowMatches($row, $q['where'])) {
                    continue;
                }
                if (in_array($id, $this->failing[$table] ?? [], true)) {
                    throw new \OCP\DB\Exception(0, 'simulated database error');
                }
                $new = array_merge($row, $q['set']);
                if ($table === 'learning_campaign_state') {
                    foreach ($this->tables[$table] as $otherId => $other) {
                        if ($otherId !== $id && $other['user_id'] === $new['user_id'] && $other['campaign_id'] === $new['campaign_id']) {
                            throw new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key');
                        }
                    }
                }
                $this->tables[$table][$id] = $new;
                $count++;
            }
            return $count;
        });
        return $qb;
    }

    /** @param array<string, mixed> $row */
    private function rowMatches(array $row, ?array $where): bool {
        if ($where === null) {
            return true;
        }
        [$op, $col, $val] = $where;
        return match ($op) {
            'eq' => $row[$col] === $val,
            'in' => in_array($row[$col], $val, true),
            'notnull' => $row[$col] !== null,
        };
    }

    /** @param list<array{0: int, 1: string, 2: string}> $rows id, user, campaign */
    private function perLearner(string $table, array $rows): void {
        foreach ($rows as [$id, $user, $campaign]) {
            $this->tables[$table][$id] = ['id' => $id, 'user_id' => $user, 'campaign_id' => $campaign, 'score' => $id * 10];
        }
    }

    /** @return array<int, string> id => user:campaign */
    private function keys(string $table): array {
        return array_map(fn (array $r) => $r['user_id'] . ':' . $r['campaign_id'], $this->tables[$table] ?? []);
    }

    public function testSavedGamesUnderAnOldIdMoveToTheNewIdAndKeepTheirProgress(): void {
        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            $this->perLearner($table, [[1, 'alice', $this->oldId(0)], [2, 'alice', $this->oldId(1)], [3, 'bob', 'wannacry']]);
        }

        $this->step()->run($this->createMock(IOutput::class));

        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            $this->assertSame([1 => 'alice:' . $this->newId(0), 2 => 'alice:' . $this->newId(1), 3 => 'bob:wannacry'], $this->keys($table), $table);
            $this->assertSame(10, $this->tables[$table][1]['score'], "$table: the row keeps its progress");
        }
    }

    public function testALearnerWithARowUnderTheNewIdKeepsItAndTheOldRowStaysUntouched(): void {
        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            $this->perLearner($table, [[1, 'alice', $this->oldId(0)], [2, 'alice', $this->newId(0)], [3, 'bob', $this->oldId(0)]]);
        }

        $output = $this->createMock(IOutput::class);
        $messages = [];
        $output->method('info')->willReturnCallback(function (string $m) use (&$messages) { $messages[] = $m; });
        $this->step()->run($output);

        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            // no unique-key violation, nothing deleted; bob has no new row, so his is moved
            $this->assertSame([1 => 'alice:' . $this->oldId(0), 2 => 'alice:' . $this->newId(0), 3 => 'bob:' . $this->newId(0)], $this->keys($table), $table);
        }
        $this->assertCount(2, array_filter($messages, fn ($m) => str_contains($m, '1 row(s) under an old campaign id left unchanged')));
    }

    public function testCoopSessionsMoveToTheNewId(): void {
        foreach ([[1, $this->oldId(0)], [2, $this->oldId(1)], [3, 'log4shell']] as [$id, $campaign]) {
            $this->tables['learning_coop_sessions'][$id] = ['id' => $id, 'campaign_id' => $campaign];
        }

        $this->step()->run($this->createMock(IOutput::class));

        $this->assertSame([1 => $this->newId(0), 2 => $this->newId(1), 3 => 'log4shell'], array_column($this->tables['learning_coop_sessions'], 'campaign_id', 'id'));
    }

    public function testCourseCampaignListsAreRewrittenWithoutDuplicatesAndOthersAreLeftAlone(): void {
        $this->tables['learning_courses'] = [
            1 => ['id' => 1, 'allowed_campaigns' => json_encode(['wannacry', $this->oldId(0), 'log4shell'])],
            2 => ['id' => 2, 'allowed_campaigns' => json_encode([$this->oldId(1), $this->newId(1)])],
            3 => ['id' => 3, 'allowed_campaigns' => null],
            4 => ['id' => 4, 'allowed_campaigns' => json_encode(['wannacry'])],
            5 => ['id' => 5, 'allowed_campaigns' => 'not json'],
        ];

        $this->step()->run($this->createMock(IOutput::class));

        $this->assertSame(['wannacry', $this->newId(0), 'log4shell'], json_decode($this->tables['learning_courses'][1]['allowed_campaigns'], true));
        $this->assertSame([$this->newId(1)], json_decode($this->tables['learning_courses'][2]['allowed_campaigns'], true));
        $this->assertNull($this->tables['learning_courses'][3]['allowed_campaigns']);
        $this->assertSame('["wannacry"]', $this->tables['learning_courses'][4]['allowed_campaigns']);
        $this->assertSame('not json', $this->tables['learning_courses'][5]['allowed_campaigns']);
    }

    public function testASecondRunChangesNothingAndSaysNothing(): void {
        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            $this->perLearner($table, [[1, 'alice', $this->oldId(0)], [2, 'bob', $this->oldId(1)]]);
        }
        $this->tables['learning_coop_sessions'][1] = ['id' => 1, 'campaign_id' => $this->oldId(0)];
        $this->tables['learning_courses'][1] = ['id' => 1, 'allowed_campaigns' => json_encode([$this->oldId(0)])];
        $this->step()->run($this->createMock(IOutput::class));
        $after = $this->tables;

        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('info');
        $this->step()->run($output);

        $this->assertSame($after, $this->tables);
    }

    /** @return array{0: IOutput, 1: \ArrayObject<int, string>} output and the warnings it received */
    private function warningOutput(): array {
        $warnings = new \ArrayObject();
        $output = $this->createMock(IOutput::class);
        $output->method('warning')->willReturnCallback(function (string $m) use ($warnings) { $warnings[] = $m; });
        return [$output, $warnings];
    }

    public function testACourseListStoredAsAJsonObjectStaysAnObject(): void {
        // The app writes a list; an object (hand-edited, or an old export) reaches the frontend
        // as a non-array and means "all campaigns". Rewriting it as a list would restrict the course.
        $this->tables['learning_courses'] = [
            1 => ['id' => 1, 'allowed_campaigns' => json_encode(['1' => $this->oldId(0), '3' => 'wannacry'])],
            2 => ['id' => 2, 'allowed_campaigns' => '{"0":"' . $this->oldId(1) . '","1":"log4shell"}'],
        ];

        $this->step()->run($this->createMock(IOutput::class));

        $first = $this->tables['learning_courses'][1]['allowed_campaigns'];
        $this->assertStringStartsWith('{', $first);
        $this->assertEquals((object)['1' => $this->newId(0), '3' => 'wannacry'], json_decode($first));
        $second = $this->tables['learning_courses'][2]['allowed_campaigns'];
        $this->assertStringStartsWith('{', $second);
        $this->assertEquals((object)['0' => $this->newId(1), '1' => 'log4shell'], json_decode($second));
    }

    public function testAFailingRowIsReportedAndEverythingElseStillMoves(): void {
        foreach (RenameCampaignIds::PER_LEARNER_TABLES as $table) {
            $this->perLearner($table, [[1, 'alice', $this->oldId(0)], [2, 'bob', $this->oldId(0)]]);
        }
        $this->tables['learning_coop_sessions'][1] = ['id' => 1, 'campaign_id' => $this->oldId(0)];
        $this->tables['learning_courses'][1] = ['id' => 1, 'allowed_campaigns' => json_encode([$this->oldId(0)])];
        $this->failing = ['learning_story_progress' => [1]];
        [$output, $warnings] = $this->warningOutput();

        $this->step()->run($output);

        // the broken row keeps its old id (nothing lost), the step goes on with the next row and table
        $this->assertSame([1 => 'alice:' . $this->oldId(0), 2 => 'bob:' . $this->newId(0)], $this->keys('learning_story_progress'));
        $this->assertSame([1 => 'alice:' . $this->newId(0), 2 => 'bob:' . $this->newId(0)], $this->keys('learning_campaign_state'));
        $this->assertSame($this->newId(0), $this->tables['learning_coop_sessions'][1]['campaign_id']);
        $this->assertSame([$this->newId(0)], json_decode($this->tables['learning_courses'][1]['allowed_campaigns'], true));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('learning_story_progress', $warnings[0]);
        $this->assertStringContainsString('id 1', $warnings[0]);
    }

    public function testAFailingCoopOrCourseUpdateIsReportedAndTheStepGoesOn(): void {
        $this->tables['learning_coop_sessions'][1] = ['id' => 1, 'campaign_id' => $this->oldId(0)];
        $this->tables['learning_coop_sessions'][2] = ['id' => 2, 'campaign_id' => $this->oldId(1)];
        $this->tables['learning_courses'][1] = ['id' => 1, 'allowed_campaigns' => json_encode([$this->oldId(0)])];
        $this->tables['learning_courses'][2] = ['id' => 2, 'allowed_campaigns' => json_encode([$this->oldId(1)])];
        $this->failing = ['learning_coop_sessions' => [1], 'learning_courses' => [1]];
        [$output, $warnings] = $this->warningOutput();

        $this->step()->run($output);

        $this->assertSame([1 => $this->oldId(0), 2 => $this->newId(1)], array_column($this->tables['learning_coop_sessions'], 'campaign_id', 'id'));
        $this->assertSame([$this->oldId(0)], json_decode($this->tables['learning_courses'][1]['allowed_campaigns'], true));
        $this->assertSame([$this->newId(1)], json_decode($this->tables['learning_courses'][2]['allowed_campaigns'], true));
        $this->assertCount(2, $warnings);
    }

    public function testAnUnreadableCourseListNamingAnOldIdIsLeftAloneButReported(): void {
        $this->tables['learning_courses'] = [
            1 => ['id' => 1, 'allowed_campaigns' => '["wannacry", "' . $this->oldId(0) . '"'],
            2 => ['id' => 2, 'allowed_campaigns' => 'not json'],
        ];
        [$output, $warnings] = $this->warningOutput();

        $this->step()->run($output);

        $this->assertSame('["wannacry", "' . $this->oldId(0) . '"', $this->tables['learning_courses'][1]['allowed_campaigns']);
        $this->assertSame('not json', $this->tables['learning_courses'][2]['allowed_campaigns']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('id 1', $warnings[0]);
    }

    public function testEveryNewIdIsAShippedCampaignAndNoOldIdIsOne(): void {
        foreach (RenameCampaignIds::RENAMED as $old => $new) {
            $this->assertFileDoesNotExist(self::APP . "/data/campaigns/$old.json");
            $file = self::APP . "/data/campaigns/$new.json";
            $this->assertFileExists($file);
            $this->assertSame($new, json_decode((string)file_get_contents($file), true)['campaign_id']);
            $this->assertNotSame($old, $new);
        }
        // the campaign intro picks the icon by id
        $intro = (string)file_get_contents(self::APP . '/src/components/CampaignIntro.vue');
        foreach (RenameCampaignIds::RENAMED as $old => $new) {
            $this->assertStringContainsString("\t$new: '", $intro);
            $this->assertStringNotContainsString($old, $intro);
        }
    }

    public function testTheStepRunsAfterEveryUpgrade(): void {
        $info = simplexml_load_file(self::APP . '/appinfo/info.xml');
        $this->assertNotFalse($info);
        $steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));
        $this->assertContains(RenameCampaignIds::class, $steps);
    }
}
