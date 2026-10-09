<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Service\ChunkSearchService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\RagContextService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.7.0 Task B5 / spec 3.6 (review finding, HIGH): contributions of other learners (swarm memory,
 * source_type student) are not sent to an external recipient — only when the session's recipient
 * processes locally. Course material from instructors stays, the learner's own contributions
 * stay, and author metadata never enters the context. Without a session the context is built as
 * for an external recipient (fail closed).
 */
class RagContextServiceRecipientTest extends TestCase {
    private const COURSE = 42;

    private static function chunk(string $text, string $sourceType, ?string $userId): RagChunk {
        $c = new RagChunk();
        $c->setCourseId(self::COURSE);
        $c->setText($text);
        $c->setSourceFile($sourceType . '.md');
        $c->setChapter(null);
        $c->setSourceType($sourceType);
        $c->setUserId($userId);
        $c->setStatus('approved');
        return $c;
    }

    /** @return list<string> chunk texts in the context */
    private function contextTexts(?LlmSession $session): array {
        $search = $this->createMock(ChunkSearchService::class);
        $search->method('search')->willReturn([
            self::chunk('Material vom Dozenten', 'web', null),
            self::chunk('Material aus dem Vault', 'vault', null),
            self::chunk('Material aus einem Dokument', 'document', null),
            self::chunk('Beitrag von Bob', 'student', 'bob'),
            self::chunk('Eigener Beitrag von Alice', 'student', 'alice'),
        ]);
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchOne('Netzwerke'))]);
        $service = new RagContextService($db, $this->createMock(LoggerInterface::class), $search);

        $context = $service->buildContext('alice', null, self::COURSE, null, 'subnetting', false, $session);

        foreach ($context['chunks'] as $chunk) {
            $this->assertSame(['text', 'source_file', 'chapter'], array_keys($chunk), 'no author metadata in the context');
        }
        return array_column($context['chunks'], 'text');
    }

    private static function session(LlmConnection $c): LlmSession {
        return new LlmSession($c, 'alice', LlmPurpose::Chat, 0);
    }

    private static function ollama(bool $processingLocal): LlmConnection {
        return LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama.lan:11434',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => $processingLocal]);
    }

    public function testAnExternalRecipientGetsNoContributionOfAnotherLearner(): void {
        $texts = $this->contextTexts(self::session(ConnectionStore::legacyGemini(true)));

        $this->assertNotContains('Beitrag von Bob', $texts);
        $this->assertSame(['Material vom Dozenten', 'Material aus dem Vault', 'Material aus einem Dokument', 'Eigener Beitrag von Alice'], $texts);
    }

    public function testAnOllamaThatIsNotConfirmedLocalCountsAsExternal(): void {
        $this->assertNotContains('Beitrag von Bob', $this->contextTexts(self::session(self::ollama(false))));
    }

    public function testALocalRecipientGetsTheWholeSwarm(): void {
        $this->assertContains('Beitrag von Bob', $this->contextTexts(self::session(self::ollama(true))));
    }

    public function testWithoutASessionTheContextIsBuiltAsForAnExternalRecipient(): void {
        $texts = $this->contextTexts(null);

        $this->assertNotContains('Beitrag von Bob', $texts);
        $this->assertContains('Eigener Beitrag von Alice', $texts);
    }
}
