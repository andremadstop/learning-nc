<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\DocumentService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #72: pdftotext ran with 2>&1, so its "Syntax Error (…)" warnings, printed for many
 * imperfect PDFs with exit code 0, ended up in extracted_text, the RAG chunks and the tutor's answers.
 * A stub pdftotext in PATH writes one line to each stream.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentPdfStderrTest extends TestCase {
    private string $bin = '';
    private string|false $path = false;

    protected function setUp(): void {
        $this->bin = sys_get_temp_dir() . '/learning-pdftotext-stub-' . bin2hex(random_bytes(4));
        mkdir($this->bin);
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void {
        putenv('PATH=' . ($this->path === false ? '' : $this->path));
        @unlink($this->bin . '/pdftotext');
        @rmdir($this->bin);
    }

    private function stub(int $exitCode): void {
        file_put_contents($this->bin . '/pdftotext', "#!/bin/sh\n"
            . "echo 'Syntax Error (1234): Illegal character' >&2\n"
            . ($exitCode === 0 ? "echo 'Das OSI-Modell hat sieben Schichten.'\n" : '')
            . "exit $exitCode\n");
        chmod($this->bin . '/pdftotext', 0755);
        putenv('PATH=' . $this->bin . ':' . ($this->path === false ? '/usr/bin:/bin' : $this->path));
    }

    private function extract(string $pdf): string {
        $service = new class(
            $this->createMock(CourseDocumentMapper::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(LoggerInterface::class),
        ) extends DocumentService {
            public function isPdfTextExtractionAvailable(): bool {
                return true;
            }
        };
        $method = new \ReflectionMethod(DocumentService::class, 'extractPdfText');
        return $method->invoke($service, $pdf);
    }

    public function testWarningsOnStderrStayOutOfTheExtractedText(): void {
        $this->stub(0);

        $text = $this->extract('%PDF-1.4 stub');

        $this->assertSame('Das OSI-Modell hat sieben Schichten.', trim($text));
        $this->assertStringNotContainsString('Syntax Error', $text);
    }

    public function testAFailedRunStillReportsWhatPdftotextSaid(): void {
        $this->stub(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pdftotext failed with code 1: Syntax Error (1234): Illegal character');
        $this->extract('%PDF-1.4 stub');
    }
}
