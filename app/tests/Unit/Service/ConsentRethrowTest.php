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

    private function summaryService(): CourseSummaryService {
        $service = $this->getMockBuilder(CourseSummaryService::class)
            ->setConstructorArgs([new FakeDbConnection(), $this->createMock(CourseMemberMapper::class),
                $this->createMock(BadgeService::class), $this->createMock(StreakService::class),
                $this->createMock(RagChunkMapper::class), $this->gemini, $this->createMock(UserTelosMapper::class)])
            ->onlyMethods(['getSnapshot', 'getStudentSummary'])
            ->getMock();
        $service->method('getSnapshot')->willReturn(null);
        $service->method('getStudentSummary')->willReturn([]);
        return $service;
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
        $this->gemini->method('generateNote')->willThrowException(new LlmConsentException());

        $this->expectException(LlmConsentException::class);
        $this->summaryService()->generateAndCacheNarrative(7, 'alice');
    }

    public function testCourseNarrativeStaysNullOnOtherProviderErrors(): void {
        $this->gemini->method('generateNote')->willThrowException(new LlmProviderException('timeout'));

        $this->assertNull($this->summaryService()->generateAndCacheNarrative(7, 'alice'));
    }
}
