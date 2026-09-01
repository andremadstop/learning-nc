<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Migration;

use OCA\Learning\Migration\Version010000Date20260901000000;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #5: 5.4.2 moves the "intro was seen" state from localStorage to the server. A fresh
 * flag defaults to empty, which would push every existing user back into the wizard on update —
 * reproducing the reported complaint for an entire instance at once, and for Andrii that means
 * 20-30 people on the same morning. Anyone with a trace of prior use counts as done.
 *
 * @group onboarding-acknowledge
 */
class OnboardingAcknowledgeMigrationTest extends TestCase {
    /** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
    private $dbMock;
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $configMock;
    /** @var IOutput&\PHPUnit\Framework\MockObject\MockObject */
    private $outputMock;

    protected function setUp(): void {
        $this->dbMock = $this->createMock(IDBConnection::class);
        $this->configMock = $this->createMock(IConfig::class);
        $this->outputMock = $this->createMock(IOutput::class);
    }

    public function testExistingUsersAreMarkedAcknowledged(): void {
        $seen = [];
        $this->configMock->method('getUserValue')->willReturn('');
        $this->configMock->expects($this->exactly(2))
            ->method('setUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $value) use (&$seen): void {
                $this->assertSame('learning', $app);
                $this->assertSame('onboarding_acknowledged', $key);
                $this->assertSame('yes', $value);
                $seen[] = $uid;
            });

        $migration = new Version010000Date20260901000000($this->dbMock, $this->configMock);
        $migration->markUsersAcknowledged(['alice', 'bob'], $this->outputMock);

        $this->assertSame(['alice', 'bob'], $seen);
    }

    public function testUsersWhoAlreadyDecidedAreNotOverwritten(): void {
        $this->configMock->method('getUserValue')->willReturn('yes');
        $this->configMock->expects($this->never())->method('setUserValue');

        $migration = new Version010000Date20260901000000($this->dbMock, $this->configMock);
        $migration->markUsersAcknowledged(['alice'], $this->outputMock);
    }

    public function testDuplicatesAreWrittenOnce(): void {
        $this->configMock->method('getUserValue')->willReturn('');
        $this->configMock->expects($this->once())->method('setUserValue');

        $migration = new Version010000Date20260901000000($this->dbMock, $this->configMock);
        $migration->markUsersAcknowledged(['alice', 'alice'], $this->outputMock);
    }

    public function testEmptyInstanceIsHarmless(): void {
        $this->configMock->expects($this->never())->method('setUserValue');

        $migration = new Version010000Date20260901000000($this->dbMock, $this->configMock);
        $migration->markUsersAcknowledged([], $this->outputMock);
    }

    /**
     * Spec 5.2 names three traces of prior use: a telos profile, an answered question, a course
     * membership. The first draft of this migration only looked at two of them, which would have
     * sent every self-learner with their own pools — no course, no telos profile — back into the
     * wizard on upgrade. That is the reported bug, reproduced by its own fix.
     */
    public function testEveryTraceOfPriorUseIsConsidered(): void {
        $this->assertSame(
            ['learning_user_telos', 'learning_course_members', 'learning_leitner_items'],
            Version010000Date20260901000000::SOURCE_TABLES
        );
    }

    public function testBlankUserIdsAreIgnored(): void {
        $this->configMock->method('getUserValue')->willReturn('');
        $this->configMock->expects($this->never())->method('setUserValue');

        $migration = new Version010000Date20260901000000($this->dbMock, $this->configMock);
        $migration->markUsersAcknowledged([''], $this->outputMock);
    }
}
