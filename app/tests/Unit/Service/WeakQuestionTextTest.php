<?php

declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CourseService;
use PHPUnit\Framework\TestCase;

/**
 * Persona sweep C9b (#96, pre-existing): Participants › Weak questions showed a long question cut
 * at 120 characters without a mark ("… at https://intranet.example."), which reads as the whole
 * question. A cut text now ends with "…" and stays within 120 characters.
 */
class WeakQuestionTextTest extends TestCase {
    public function testAShortTextStaysAsItIs(): void {
        $this->assertSame('Fire doors must be kept…', CourseService::shortQuestionText('Fire doors must be kept…'));
        $this->assertSame(str_repeat('a', 120), CourseService::shortQuestionText(str_repeat('a', 120)));
    }

    public function testACutTextEndsWithAnEllipsisWithinTheLimit(): void {
        $long = 'How often is the fire alarm tested? See https://intranet.example.org/facilities/fire-safety/alarm-testing-schedule-and-evacuation-drills';
        $short = CourseService::shortQuestionText($long);
        $this->assertSame(120, mb_strlen($short));
        $this->assertStringEndsWith('…', $short);
        $this->assertStringStartsWith('How often is the fire alarm tested? See https://', $short);
    }

    public function testMultibyteTextIsCutByCharacters(): void {
        $short = CourseService::shortQuestionText(str_repeat('ä', 130));
        $this->assertSame(str_repeat('ä', 119) . '…', $short);
    }

    public function testNoSpaceBeforeTheEllipsis(): void {
        $text = str_repeat('word ', 30);
        $this->assertStringEndsWith('word…', CourseService::shortQuestionText($text));
    }
}
