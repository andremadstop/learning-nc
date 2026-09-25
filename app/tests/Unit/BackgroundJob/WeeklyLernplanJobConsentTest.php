<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2: the weekly job sent every active learner's weakest topic and wrong answers to the
 * AI provider without checking consent. Users without valid consent must be skipped before
 * any learning data is read.
 */
class WeeklyLernplanJobConsentTest extends TestCase {
    private function makeJob(NoteGeneratorService $notes, LernprofilService $profile, TelosService $telos): WeeklyLernplanJob {
        return new WeeklyLernplanJob(
            $this->createMock(ITimeFactory::class),
            new FakeDbConnection(),
            $notes,
            $profile,
            $this->createMock(LoggerInterface::class),
            $telos
        );
    }

    public function testUserWithoutConsentIsSkippedBeforeAnyDataIsRead(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->never())->method('generateSummary');
        $profile = $this->createMock(LernprofilService::class);
        $profile->expects($this->never())->method('getWeakestTopics');
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->with('alice')->willReturn(false);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('skipped_consent', $method->invoke($this->makeJob($notes, $profile, $telos), 'alice'));
    }

    public function testUserWithConsentAndWeakTopicGetsANote(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->once())->method('generateSummary')->with('bob', 12, null);
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 55.0]]);
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->willReturn(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('generated', $method->invoke($this->makeJob($notes, $profile, $telos), 'bob'));
    }

    public function testUserWithConsentButNoWeakTopicIsSkipped(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->never())->method('generateSummary');
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 5.0]]);
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->willReturn(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('skipped', $method->invoke($this->makeJob($notes, $profile, $telos), 'carol'));
    }
}
