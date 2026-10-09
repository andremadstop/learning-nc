<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\TelosService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.7.0 review finding (HIGH, spec 3.6): the swarm filter covered only RAG chunks retrieved
 * fresh. An answer VirtuProf gave from another learner's contribution while the recipient was
 * local stayed in chat memory and went to an external recipient later — as memory in the next
 * chat and through memory compression.
 *
 * Every assistant answer and summary now records whether it is swarm-sourced (1), clean (0) or
 * unknown (NULL: written before 5.7.0, when no filter existed). The learner's own messages are
 * always clean.
 *   - loadMemory() gives an external recipient (and a caller without a session) only clean
 *     answers and summaries; a local recipient gets everything.
 *   - Compression builds new summaries only from the learner's own messages and clean answers
 *     (spec 3.6), whatever the recipient, so a summary is always clean.
 */
class AiChatMemorySwarmTest extends TestCase {
    private AiChatMemoryMapper&MockObject $mapper;
    private GeminiService&MockObject $gemini;
    private TelosService&MockObject $telos;
    /** @var list<AiChatMemory> */
    private array $inserted = [];

    protected function setUp(): void {
        $this->mapper = $this->createMock(AiChatMemoryMapper::class);
        $this->gemini = $this->createMock(GeminiService::class);
        $this->telos = $this->createMock(TelosService::class);
        $this->mapper->method('insert')->willReturnCallback(function (AiChatMemory $e): AiChatMemory {
            $this->inserted[] = $e;
            return $e;
        });
    }

    private function service(): AiChatMemoryService {
        return new AiChatMemoryService($this->mapper, $this->gemini, $this->createMock(LoggerInterface::class), $this->telos);
    }

    private static function entry(int $id, string $role, string $message, ?int $swarm): AiChatMemory {
        $e = new AiChatMemory();
        $e->setId($id);
        $e->setUserId('alice');
        $e->setRole($role);
        $e->setMessage($message);
        $e->setCreatedAt(1000 + $id);
        $e->setSwarmSourced($swarm);
        return $e;
    }

    /** @return list<AiChatMemory> */
    private static function history(): array {
        return [
            self::entry(1, 'summary', 'ALTE-ZUSAMMENFASSUNG', null),      // before 5.7.0: unknown
            self::entry(2, 'user', 'EIGENE-FRAGE-ALT', null),             // own message, written before 5.7.0
            self::entry(3, 'assistant', 'ALTE-ANTWORT', null),            // before 5.7.0: unknown
            self::entry(4, 'user', 'EIGENE-FRAGE', 0),
            self::entry(5, 'assistant', 'BOB-ZITAT', 1),                  // built from Bob's contribution
            self::entry(6, 'user', 'NOCH-EINE-FRAGE', 0),
            self::entry(7, 'assistant', 'SAUBERE-ANTWORT', 0),
            self::entry(8, 'summary', 'SAUBERE-ZUSAMMENFASSUNG', 0),
        ];
    }

    private static function session(LlmConnection $c): LlmSession {
        return new LlmSession($c, 'alice', LlmPurpose::Chat, 0);
    }

    private static function localOllama(): LlmConnection {
        return LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama.lan:11434',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => true]);
    }

    public function testAnExternalRecipientGetsNoSwarmSourcedOrUnknownAnswerFromMemory(): void {
        $this->mapper->method('findRecentByUser')->willReturn(self::history());

        $memory = $this->service()->loadMemory('alice', self::session(ConnectionStore::legacyGemini(true)));

        $this->assertSame(['EIGENE-FRAGE-ALT', 'EIGENE-FRAGE', 'NOCH-EINE-FRAGE', 'SAUBERE-ANTWORT', 'SAUBERE-ZUSAMMENFASSUNG'],
            array_column($memory, 'message'));
        $this->assertSame([false, false, false, false, false], array_column($memory, 'swarm'));
    }

    public function testWithoutASessionMemoryIsLoadedAsForAnExternalRecipient(): void {
        $this->mapper->method('findRecentByUser')->willReturn(self::history());

        $messages = array_column($this->service()->loadMemory('alice'), 'message');

        $this->assertNotContains('BOB-ZITAT', $messages);
        $this->assertNotContains('ALTE-ANTWORT', $messages);
    }

    public function testALocalRecipientGetsTheWholeMemoryWithItsSwarmMarks(): void {
        $this->mapper->method('findRecentByUser')->willReturn(self::history());

        $memory = $this->service()->loadMemory('alice', self::session(self::localOllama()));

        $this->assertCount(8, $memory);
        // unknown counts as swarm-sourced: an answer built on it is marked as well
        $this->assertSame([true, false, true, false, true, false, false, false], array_column($memory, 'swarm'));
    }

    public function testSaveExchangeRecordsTheSwarmMarkOnTheAnswerOnly(): void {
        $this->mapper->method('countByUser')->willReturn(2);

        $this->service()->saveExchange('alice', 'Frage', 'Antwort mit Bobs Beitrag', true);
        $this->service()->saveExchange('alice', 'Frage', 'Antwort ohne Schwarm', false);

        $this->assertSame([['user', 0], ['assistant', 1], ['user', 0], ['assistant', 0]],
            array_map(static fn (AiChatMemory $e): array => [$e->getRole(), $e->getSwarmSourced()], $this->inserted));
    }

    public function testCompressionSendsOnlyOwnMessagesAndCleanAnswersAndStoresACleanSummary(): void {
        $this->mapper->method('countByUser')->willReturn(52);
        $this->mapper->method('findOldestByUser')->willReturn(self::history());
        $this->telos->method('hasAiConsent')->willReturn(true);
        $prompt = '';
        $this->gemini->expects($this->once())->method('generateNote')
            ->willReturnCallback(function (string $system, string $user) use (&$prompt): string {
                $prompt = $user;
                return 'Kurz: VLANs.';
            });
        $this->mapper->expects($this->once())->method('deleteByIds')->with(range(1, 8));

        $this->service()->saveExchange('alice', 'Frage', 'Antwort', false);

        foreach (['EIGENE-FRAGE-ALT', 'EIGENE-FRAGE', 'NOCH-EINE-FRAGE', 'SAUBERE-ANTWORT', 'SAUBERE-ZUSAMMENFASSUNG'] as $kept) {
            $this->assertStringContainsString($kept, $prompt);
        }
        foreach (['BOB-ZITAT', 'ALTE-ANTWORT', 'ALTE-ZUSAMMENFASSUNG'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $prompt);
        }
        $summary = end($this->inserted);
        $this->assertSame('summary', $summary->getRole());
        $this->assertSame(0, $summary->getSwarmSourced());
    }

    /** Only swarm-sourced or unknown entries among the oldest: nothing to summarise, nothing is sent. */
    public function testCompressionWithNothingCleanToSummariseSendsNothing(): void {
        $this->mapper->method('countByUser')->willReturn(52);
        $this->mapper->method('findOldestByUser')->willReturn([
            self::entry(1, 'assistant', 'BOB-ZITAT', 1),
            self::entry(2, 'summary', 'ALTE-ZUSAMMENFASSUNG', null),
        ]);
        $this->telos->method('hasAiConsent')->willReturn(true);
        $this->gemini->expects($this->never())->method('generateNote');
        $this->mapper->expects($this->once())->method('deleteByIds')->with([1, 2]);

        $this->service()->saveExchange('alice', 'Frage', 'Antwort', false);

        $this->assertSame(['user', 'assistant'], array_map(static fn (AiChatMemory $e): string => $e->getRole(), $this->inserted),
            'no summary of nothing');
    }
}
