<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2: the weekly job sent every active learner's weakest topic and wrong answers to the
 * AI provider without checking consent. Users without valid consent must be skipped before
 * any learning data is read.
 *
 * 5.7.0 review finding (MED): the job begins the weekly_note session before it reads the learning
 * data and hands it to the note generator, so the snapshot and the consent epoch are those of the
 * moment the note's assembly started.
 */
class WeeklyLernplanJobConsentTest extends TestCase {
    private static function session(string $uid): LlmSession {
        return new LlmSession(ConnectionStore::legacyGemini(true), $uid, LlmPurpose::WeeklyNote, 0);
    }

    /** A gateway whose begin() grants or refuses consent. */
    private function gateway(bool $consent): LlmGateway {
        $gw = $this->createMock(LlmGateway::class);
        if ($consent) {
            $gw->method('begin')->willReturnCallback(fn (?string $uid, LlmPurpose $p) => self::session((string)$uid));
        } else {
            $gw->method('begin')->willThrowException(new LlmConsentException());
        }
        return $gw;
    }

    private function makeJob(NoteGeneratorService $notes, LernprofilService $profile, LlmGateway $gateway): WeeklyLernplanJob {
        return new WeeklyLernplanJob(
            $this->createMock(ITimeFactory::class),
            new FakeDbConnection(),
            $notes,
            $profile,
            $this->createMock(LoggerInterface::class),
            $gateway
        );
    }

    public function testUserWithoutConsentIsSkippedBeforeAnyDataIsRead(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->never())->method('generateSummary');
        $profile = $this->createMock(LernprofilService::class);
        $profile->expects($this->never())->method('getWeakestTopics');
        $gateway = $this->createMock(LlmGateway::class);
        $gateway->method('begin')->with('alice', LlmPurpose::WeeklyNote)->willThrowException(new LlmConsentException());

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('skipped_consent', $method->invoke($this->makeJob($notes, $profile, $gateway), 'alice'));
    }

    public function testUserWithConsentAndWeakTopicGetsANote(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->once())->method('generateSummary')
            ->with('bob', 12, null, LlmPurpose::WeeklyNote, $this->callback(
                fn (?LlmSession $s): bool => $s !== null && $s->subjectUserId === 'bob' && $s->purpose === LlmPurpose::WeeklyNote));
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 55.0]]);
        $gateway = $this->gateway(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('generated', $method->invoke($this->makeJob($notes, $profile, $gateway), 'bob'));
    }

    public function testUserWithConsentButNoWeakTopicIsSkipped(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->never())->method('generateSummary');
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 5.0]]);
        $gateway = $this->gateway(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('skipped', $method->invoke($this->makeJob($notes, $profile, $gateway), 'carol'));
    }

    /**
     * 5.7.0 Task B5: consent passes the job's early check, then the user revokes (or the admin
     * changes the recipient) before the note is sent. The gateway refuses — that is a consent
     * skip, not an error.
     */
    public function testALateConsentRefusalCountsAsSkippedConsent(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->method('generateSummary')->willThrowException(new LlmConsentException());
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 55.0]]);
        $gateway = $this->gateway(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('skipped_consent', $method->invoke($this->makeJob($notes, $profile, $gateway), 'dave'));
    }

    /** Any other provider failure is still an error for run() to count. */
    public function testAnotherProviderFailureStillThrows(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->method('generateSummary')->willThrowException(new LlmProviderException('timeout'));
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturn([['pool_id' => 12, 'error_rate' => 55.0]]);
        $gateway = $this->gateway(true);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->expectException(LlmProviderException::class);
        $method->invoke($this->makeJob($notes, $profile, $gateway), 'erin');
    }

    /** The session is begun before the weak topics are read. */
    public function testTheSessionIsBegunBeforeTheLearningDataIsRead(): void {
        $order = [];
        $gw = $this->createMock(LlmGateway::class);
        $gw->method('begin')->willReturnCallback(function (?string $uid) use (&$order): LlmSession {
            $order[] = 'begin';
            return self::session((string)$uid);
        });
        $profile = $this->createMock(LernprofilService::class);
        $profile->method('getWeakestTopics')->willReturnCallback(function () use (&$order): array {
            $order[] = 'weak_topics';
            return [['pool_id' => 12, 'error_rate' => 55.0]];
        });
        $notes = $this->createMock(NoteGeneratorService::class);

        $method = new \ReflectionMethod(WeeklyLernplanJob::class, 'processUser');
        $this->assertSame('generated', $method->invoke($this->makeJob($notes, $profile, $gw), 'frank'));
        $this->assertSame(['begin', 'weak_topics'], $order);
    }
}
