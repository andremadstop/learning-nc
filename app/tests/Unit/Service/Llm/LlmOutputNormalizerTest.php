<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\GeminiProvider;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A4: every provider adapter cleans model output through one normalizer. The
 * BOM/\uXXXX/mojibake logic is taken over from GeminiProvider; the parity test below pins that
 * it is the same logic (same bytes out for the same bytes in) while the old provider is still
 * in service. New on top: reasoning models (Ollama deepseek-r1/qwen3, some OpenAI-compatible
 * hosts) put their chain of thought into <think>…</think>, which must never reach a learner.
 */
class LlmOutputNormalizerTest extends TestCase {
    private LlmOutputNormalizer $normalizer;

    protected function setUp(): void {
        $this->normalizer = new LlmOutputNormalizer();
    }

    public function testStripsByteOrderMarkAndOuterWhitespace(): void {
        $this->assertSame('Hallo Welt', $this->normalizer->normalize("\xEF\xBB\xBFHallo Welt  \n"));
    }

    public function testDecodesLiteralUnicodeEscapes(): void {
        $this->assertSame('Prüfung ä', $this->normalizer->normalize('Prüfung ä'));
    }

    public function testKeepsCorrectUtf8Untouched(): void {
        $this->assertSame('Größe ä ö ü ß – €', $this->normalizer->normalize('Größe ä ö ü ß – €'));
    }

    public function testRepairsDoubleEncodedUtf8(): void {
        // "Größe äußern" encoded as UTF-8, misread as Windows-1252, re-encoded as UTF-8.
        $mojibake = mb_convert_encoding('Größe äußern', 'UTF-8', 'Windows-1252');
        $this->assertSame('GrÃ¶ÃŸe Ã¤uÃŸern', $mojibake);
        $this->assertSame('Größe äußern', $this->normalizer->normalize($mojibake));
    }

    public function testLeavesMojibakeLookalikeWhenRepairWouldBreakEncoding(): void {
        // Matches the mojibake pattern, but converting back yields invalid UTF-8: keep as is.
        $this->assertSame('Ãx Test', $this->normalizer->normalize('Ãx Test'));
    }

    public function testEmptyAndWhitespaceOnlyBecomeEmpty(): void {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize("  \n\t "));
        $this->assertSame('', $this->normalizer->normalize("\xEF\xBB\xBF"));
    }

    public function testRemovesThinkBlock(): void {
        $this->assertSame('Antwort', $this->normalizer->normalize('<think>a</think>Antwort'));
    }

    public function testRemovesMultilineAndMultipleThinkBlocksCaseInsensitive(): void {
        $in = "<think>\nSchritt 1\nSchritt 2\n</think>\n\nTeil A <THINK>zwischendurch</Think> Teil B";
        $this->assertSame('Teil A  Teil B', $this->normalizer->normalize($in));
    }

    public function testThinkRemovalStillAppliesEncodingRepair(): void {
        $in = "\xEF\xBB\xBF<think>ü</think> GrÃ¶ÃŸe";
        $this->assertSame('Größe', $this->normalizer->normalize($in));
    }

    public function testUnclosedLeadingThinkMeansNoAnswer(): void {
        // Output truncated inside the reasoning (max tokens): there is no answer to show.
        $this->assertSame('', $this->normalizer->normalize("<think>Ich überlege noch und dann"));
    }

    public function testOrphanClosingThinkDropsReasoningBeforeIt(): void {
        // Chat templates that open <think> in the prompt return only the closing tag.
        $this->assertSame('Antwort', $this->normalizer->normalize("Überlegung…\n</think>\nAntwort"));
    }

    public function testOtherTagsStartingWithThinkAreNotReasoning(): void {
        $in = '<think>x</think>Erklärung: das Tag </thinking> schließt.';
        $this->assertSame('Erklärung: das Tag </thinking> schließt.', $this->normalizer->normalize($in));
    }

    public function testTextMentioningThinkWordIsUntouched(): void {
        $this->assertSame('I think so.', $this->normalizer->normalize('I think so.'));
    }

    /**
     * Without <think> the output must be byte-identical to the live GeminiProvider, so moving
     * Gemini onto the shared normalizer (A5a) cannot change what learners see today.
     *
     * @return array<string, array{string}>
     */
    public static function geminiParityInputs(): array {
        return [
            'bom' => ["\xEF\xBB\xBFHallo"],
            'whitespace' => ["  Text mit Rand \n"],
            'unicode escape' => ['Prüfung ä €'],
            'invalid escape kept' => ['\u12 bleibt'],
            'real umlaut' => ['Größe ä'],
            'mojibake' => ['GrÃ¶ÃŸe Ã¤uÃŸern'],
            'mojibake lookalike' => ['Ãx Test'],
            'mixed escape and mojibake' => ['Ã¤ ü'],
            'empty' => [''],
            'whitespace only' => ["  \n "],
            'bom only' => ["\xEF\xBB\xBF"],
            'markdown json' => ["```json\n{\"a\": \"Ã¼\"}\n```"],
        ];
    }

    /** @dataProvider geminiParityInputs */
    public function testMatchesGeminiProviderWithoutThinkTags(string $input): void {
        $gemini = new GeminiProvider($this->createMock(IConfig::class), $this->createMock(LoggerInterface::class));
        $legacy = new \ReflectionMethod(GeminiProvider::class, 'normalizeModelTextOutput');
        $legacy->setAccessible(true);
        $this->assertSame($legacy->invoke($gemini, $input), $this->normalizer->normalize($input));
    }
}
