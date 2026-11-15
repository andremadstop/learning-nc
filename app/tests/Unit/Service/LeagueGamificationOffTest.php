<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Db\LeagueChallengeMapper;
use OCA\Learning\Db\LeagueResultMapper;
use OCA\Learning\Db\LeagueSeason;
use OCA\Learning\Db\LeagueSeasonMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\LeagueService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #70: with gamification off the competition area is gone for every role, and the league
 * reads (overview, table, Champions League) hand out no standings either — before, a direct call
 * still returned the season table of a course. The access check still runs first.
 */
#[AllowMockObjectsWithoutExpectations]
final class LeagueGamificationOffTest extends TestCase {
    private LeagueSeasonMapper $seasons;
    private CourseService $courses;

    private function league(?bool $gamification, bool $member = true): LeagueService {
        $this->seasons = $this->createMock(LeagueSeasonMapper::class);
        $this->courses = $this->createMock(CourseService::class);
        if ($member) {
            $this->courses->method('findById')->willReturn(['id' => 7, 'is_instructor' => true]);
        } else {
            $this->courses->method('findById')->willThrowException(new ForbiddenException('No access'));
        }
        $xp = null;
        if ($gamification !== null) {
            $xp = $this->createMock(XpService::class);
            $xp->method('isGamificationEnabled')->willReturn($gamification);
        }
        return new LeagueService(
            $this->seasons,
            $this->createMock(LeagueChallengeMapper::class),
            $this->createMock(LeagueResultMapper::class),
            $this->courses,
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(DuelSessionMapper::class),
            new FakeDbConnection(),
            $this->createMock(IUserManager::class),
            $xp
        );
    }

    public function testOverviewHasNoSeasonOrStandingsWithGamificationOff(): void {
        $service = $this->league(false);
        $this->seasons->expects($this->never())->method('findByCourse');
        $overview = $service->getOverview(7, 'teacher');
        $this->assertNull($overview['season']);
        $this->assertSame([], $overview['standings']);
        $this->assertNull($overview['cl']);
        $this->assertFalse($overview['can_manage'], 'no season can be managed or created while gamification is off');
        $this->assertFalse($overview['can_create_season']);
    }

    public function testOverviewUnchangedWithGamificationOn(): void {
        foreach ([true, null] as $gamification) {
            $service = $this->league($gamification);
            $this->seasons->expects($this->once())->method('findByCourse')->willReturn([]);
            $overview = $service->getOverview(7, 'teacher');
            $this->assertTrue($overview['can_manage']);
            $this->assertTrue($overview['can_create_season']);
        }
    }

    public function testTableAndChampionsLeagueAreEmptyWithGamificationOff(): void {
        $service = $this->league(false);
        $this->seasons->expects($this->never())->method('findById');
        $this->assertSame([], $service->getTable(7, 3, 'teacher'));
        $this->assertSame(['status' => 'not_available'], $service->getChampionsLeague(7, 3, 'teacher'));
    }

    public function testTableReadsTheSeasonWithGamificationOn(): void {
        $service = $this->league(true);
        $season = new LeagueSeason();
        $season->setId(3);
        $season->setCourseId(8);
        $this->seasons->expects($this->once())->method('findById')->willReturn($season);
        $this->expectExceptionMessage('Season does not belong to this course');
        $service->getTable(7, 3, 'teacher');
    }

    public function testNonMembersStillGetTheAccessErrorWithGamificationOff(): void {
        $service = $this->league(false, false);
        $this->expectException(ForbiddenException::class);
        $service->getTable(7, 3, 'stranger');
    }

    /**
     * Each league write, with gamification off, refuses before it reads a season or writes
     * anything — before the fix a direct call created, started or finished a season and set up
     * challenges and duels while the competition area was hidden.
     *
     * @return array<string, array{0: callable(LeagueService): mixed}>
     */
    public static function leagueWrites(): array {
        return [
            'create season' => [static fn (LeagueService $s) => $s->createSeason(7, 'teacher', 1, 2, 'S')],
            'start season' => [static fn (LeagueService $s) => $s->startSeason(7, 3, 'teacher')],
            'finish season' => [static fn (LeagueService $s) => $s->finishSeason(7, 3, 'teacher')],
            'challenge' => [static fn (LeagueService $s) => $s->challengeOpponent(7, 3, 'bob', 'alice')],
            'accept challenge' => [static fn (LeagueService $s) => $s->acceptChallenge(7, 3, 9, 'bob')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leagueWrites')]
    public function testLeagueWritesAreRefusedWithGamificationOff(callable $write): void {
        $service = $this->league(false);
        $this->seasons->expects($this->never())->method('findById');
        $this->seasons->expects($this->never())->method('findByCourse');
        $this->seasons->expects($this->never())->method('insert');
        $this->seasons->expects($this->never())->method('update');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('League is not available');
        $write($service);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leagueWrites')]
    public function testLeagueWritesStillCheckAccessFirstWithGamificationOff(callable $write): void {
        $service = $this->league(false, false);
        $this->expectException(ForbiddenException::class);
        $write($service);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leagueWrites')]
    public function testLeagueWritesGoOnWithGamificationOn(callable $write): void {
        $service = $this->league(true);
        $season = new LeagueSeason();
        $season->setId(3);
        $season->setCourseId(8);
        $season->setStatus('open');
        $this->seasons->method('findById')->willReturn($season);
        $this->seasons->method('findByCourse')->willReturn([]);
        try {
            $write($service);
        } catch (\Throwable $e) {
            $this->assertNotSame('League is not available', $e->getMessage());
            return;
        }
        $this->addToAssertionCount(1);
    }
}
