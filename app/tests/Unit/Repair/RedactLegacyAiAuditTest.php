<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\RedactLegacyAiAudit;
use PHPUnit\Framework\TestCase;

/**
 * Which audit contexts the C3 redaction rewrites, and into what. The database side (allowlist,
 * chain abort, paging, marker) is covered on both engines by
 * tests/Integration/Migration/RedactLegacyAiAuditTest.
 */
class RedactLegacyAiAuditTest extends TestCase {
    private const REDACTED_GEMINI = '{"input":"[redacted]","output":"[redacted]","model":"gemini"}';

    public function testLegacyContentIsReplacedAndTheModelKept(): void {
        $this->assertSame(self::REDACTED_GEMINI, RedactLegacyAiAudit::redactedContext('{"input":"Was ist ein Subnetz?","output":"Ein Teilnetz.","model":"gemini"}'));
    }

    public function testOnlyOneContentFieldIsEnough(): void {
        $this->assertSame(
            '{"input":"[redacted]","output":"[redacted]","model":null}',
            RedactLegacyAiAudit::redactedContext('{"output":"[api_error: secret detail]"}'),
        );
    }

    public function testAnAlreadyRedactedRowIsLeftAlone(): void {
        $this->assertNull(RedactLegacyAiAudit::redactedContext(self::REDACTED_GEMINI));
    }

    public function testMetadataOnlyContextIsLeftAlone(): void {
        $this->assertNull(RedactLegacyAiAudit::redactedContext('{"purpose":"chat","outcome":"output_blocked","provider":"gemini"}'));
    }

    public function testEmptyContextIsLeftAlone(): void {
        $this->assertNull(RedactLegacyAiAudit::redactedContext(null));
        $this->assertNull(RedactLegacyAiAudit::redactedContext(''));
    }

    public function testUndecodableContextIsRedactedWithoutModel(): void {
        $this->assertSame(
            '{"input":"[redacted]","output":"[redacted]","model":null}',
            RedactLegacyAiAudit::redactedContext('{"input":"truncated'),
        );
    }

    public function testANonStringModelIsNotCarriedOver(): void {
        $this->assertSame(
            '{"input":"[redacted]","output":"[redacted]","model":null}',
            RedactLegacyAiAudit::redactedContext('{"input":"x","model":{"leak":"y"}}'),
        );
    }

    public function testTheAllowlistIsExactlyWhatGeminiServiceWroteIn551(): void {
        $this->assertSame(
            ['ai_chat', 'ai_structured', 'ai_telos_extract', 'telos_interview_turn', 'note_generation', 'ticket_triage'],
            RedactLegacyAiAudit::EVENT_KEYS,
        );
    }
}
