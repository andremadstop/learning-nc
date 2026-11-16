<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\ImportQuestionMediaCommand;
use OCA\Learning\Service\QuestionMediaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * GitHub #66: a write that failed during the import is listed in the report and makes the run
 * exit 1, so an operator (or a script) knows what to rerun.
 */
final class ImportQuestionMediaCommandTest extends TestCase {
    public function testFailedWritesAreListedAndExitOne(): void {
        $service = $this->createMock(QuestionMediaService::class);
        $service->expects($this->once())->method('importBundle')->willReturn([
            'entries' => 1, 'matched' => 1, 'unknown_keys' => [], 'ambiguous_keys' => [], 'invalid_entries' => [],
            'missing_files' => [], 'refused_files' => [],
            'write_failed' => ['demo-q1: track intro-en (NotPermittedException: quota exceeded)'],
            'tracks_written' => 0, 'tracks_unchanged' => 0, 'tracks_removed' => 0,
            'cues_written' => 0, 'cues_unchanged' => 0, 'cues_removed' => 0,
            'feedback_updated' => 0, 'feedback_unchanged' => 0, 'feedback_warnings' => [],
            'dropped_languages' => [], 'bytes_written' => 0, 'pool_questions' => 1,
        ]);
        $tester = new CommandTester(new ImportQuestionMediaCommand($service));
        $code = $tester->execute(['poolId' => '5', 'bundle-dir' => __DIR__ . '/../../fixtures/question-media/bundle']);
        $out = $tester->getDisplay();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('failed writes:      1', $out);
        $this->assertStringContainsString('demo-q1: track intro-en (NotPermittedException: quota exceeded)', $out);
    }
}
