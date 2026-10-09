<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\EncryptionService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.7.0 review finding: services between a facade and a controller must not swallow
 * LlmConsentException in their generic catch (Throwable / RuntimeException — it is a
 * RuntimeException). Other provider errors keep their previous outcome.
 */
class ConsentRethrowTest extends TestCase {
    private GeminiService&MockObject $gemini;

    protected function setUp(): void {
        $this->gemini = $this->createMock(GeminiService::class);
    }

    private function telosService(): TelosService {
        return new TelosService($this->createMock(UserTelosMapper::class), $this->gemini,
            $this->createMock(LoggerInterface::class), new FakeDbConnection(), $this->createMock(IUserManager::class),
            $this->createMock(EncryptionService::class), $this->createMock(ConsentService::class),
            $this->createMock(ConnectionStore::class));
    }

    /** @param list<string> $order receives 'summary' when the learning data is read */
    private function summaryService(array &$order = []): CourseSummaryService {
        $service = $this->getMockBuilder(CourseSummaryService::class)
            ->setConstructorArgs([new FakeDbConnection(), $this->createMock(CourseMemberMapper::class),
                $this->createMock(BadgeService::class), $this->createMock(StreakService::class),
                $this->createMock(RagChunkMapper::class), $this->gemini, $this->createMock(UserTelosMapper::class)])
            ->onlyMethods(['getSnapshot', 'getStudentSummary'])
            ->getMock();
        $service->method('getSnapshot')->willReturn(null);
        $service->method('getStudentSummary')->willReturnCallback(function () use (&$order): array {
            $order[] = 'summary';
            return [];
        });
        return $service;
    }

    private static function narrativeSession(): LlmSession {
        return new LlmSession(ConnectionStore::legacyGemini(true), 'alice', LlmPurpose::CourseNarrative, 0);
    }

    public function testTelosInterviewLetsConsentThrough(): void {
        $this->gemini->method('generateStructured')->willThrowException(new LlmConsentException());

        $this->expectException(LlmConsentException::class);
        $this->telosService()->processInterview('alice', 'Ich lerne für Security+.');
    }

    public function testTelosInterviewStillReportsOtherErrors(): void {
        $this->gemini->method('generateStructured')->willThrowException(new \RuntimeException('boom'));

        $result = $this->telosService()->processInterview('alice', 'Ich lerne für Security+.');

        $this->assertFalse($result['saved']);
    }

    public function testCourseNarrativeLetsConsentThrough(): void {
        $this->gemini->method('begin')->willReturn(self::narrativeSession());
        $this->gemini->method('generateNote')->willThrowException(new LlmConsentException());

        $this->expectException(LlmConsentException::class);
        $this->summaryService()->generateAndCacheNarrative(7, 'alice');
    }

    public function testCourseNarrativeStaysNullOnOtherProviderErrors(): void {
        $this->gemini->method('begin')->willReturn(self::narrativeSession());
        $this->gemini->method('generateNote')->willThrowException(new LlmProviderException('timeout'));

        $this->assertNull($this->summaryService()->generateAndCacheNarrative(7, 'alice'));
    }

    /**
     * 5.7.0 review finding (MED): the course narrative begins its session before the learner's
     * course data is assembled and sends with exactly that session.
     */
    public function testCourseNarrativeIsBegunBeforeTheDataIsReadAndSentWithThatSession(): void {
        $order = [];
        $session = self::narrativeSession();
        $this->gemini->method('begin')->willReturnCallback(function (string $uid, LlmPurpose $p) use (&$order, $session): LlmSession {
            $order[] = 'begin:' . $p->value;
            return $session;
        });
        $this->gemini->expects($this->once())->method('generateNote')
            ->with($this->anything(), $this->anything(), 'alice', LlmPurpose::CourseNarrative, $this->identicalTo($session))
            ->willReturn('Narrativ');

        $this->assertSame('Narrativ', $this->summaryService($order)->generateAndCacheNarrative(7, 'alice'));
        $this->assertSame(['begin:course_narrative', 'summary'], $order);
    }

    public function testCourseNarrativeWithoutConsentReadsNoDataAndLetsConsentThrough(): void {
        $order = [];
        $this->gemini->method('begin')->willThrowException(new LlmConsentException());
        $this->gemini->expects($this->never())->method('generateNote');

        try {
            $this->summaryService($order)->generateAndCacheNarrative(7, 'alice');
            $this->fail('expected LlmConsentException');
        } catch (LlmConsentException) {
        }
        $this->assertSame([], $order);
    }

    /** AI switched off at begin(): no narrative, as before — not a 500. */
    public function testCourseNarrativeIsNullWhenAiIsOffAtBegin(): void {
        $this->gemini->method('begin')->willThrowException(new LlmProviderException('disabled'));

        $this->assertNull($this->summaryService()->generateAndCacheNarrative(7, 'alice'));
    }
}
