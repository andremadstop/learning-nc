<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\ImportVaultCommand;
use OCA\Learning\Db\RagChunkMapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #137: occ learning:import-vault always skipped the folders 'Eigene-Notizen',
 * 'Bilder' and 'tools' of one particular vault, so a course chapter "tools/" was counted as
 * "Skipped excluded" without notice. Only .obsidian/ and .venv/ stay hard excludes.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportVaultCommandExcludeTest extends TestCase {
    private string $vault = '';

    protected function setUp(): void {
        $this->vault = sys_get_temp_dir() . '/learning-vault-' . bin2hex(random_bytes(4));
        $note = str_repeat('Linux-Werkzeuge wie grep, sed und awk gehören zum Kurs. ', 3);
        foreach (['tools/grep.md', 'Bilder/beschreibung.md', 'Kapitel 1/netze.md', '.obsidian/workspace.md', 'Privat/tagebuch.md'] as $path) {
            @mkdir(dirname($this->vault . '/' . $path), 0777, true);
            file_put_contents($this->vault . '/' . $path, $note);
        }
    }

    protected function tearDown(): void {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->vault, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->vault);
    }

    /** @return array{0: int, 1: string} */
    private function runImport(string $exclude): array {
        $chunks = $this->createMock(RagChunkMapper::class);
        $chunks->method('findByCourseId')->willReturn([]);
        $command = new ImportVaultCommand($chunks, $this->createMock(LoggerInterface::class));

        $args = ['vault-path' => $this->vault, '--course-id' => '7', '--dry-run' => true, '--pattern' => '**/*.md', '--exclude' => $exclude];
        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        $input->method('getArgument')->willReturnCallback(static fn (string $n) => $args[$n] ?? null);
        $input->method('getOption')->willReturnCallback(static fn (string $n) => $args['--' . $n] ?? null);
        $lines = [];
        $output = $this->createMock(\Symfony\Component\Console\Output\OutputInterface::class);
        $output->method('writeln')->willReturnCallback(static function ($messages) use (&$lines): void {
            foreach ((array)$messages as $m) {
                $lines[] = (string)$m;
            }
        });
        $exit = $command->run($input, $output);
        return [$exit, implode("\n", $lines)];
    }

    public function testCourseFoldersNamedToolsOrBilderAreImported(): void {
        [$exit, $display] = $this->runImport('');

        $this->assertSame(0, $exit, $display);
        $this->assertStringContainsString('Would import 4 files', $display);
        $this->assertStringContainsString('Skipped excluded: 1 (.obsidian/, .venv/)', $display);
    }

    public function testFoldersToLeaveOutGoThroughTheExcludeOption(): void {
        [, $display] = $this->runImport('Privat/**,Bilder/**');

        $this->assertStringContainsString('Would import 2 files', $display);
        $this->assertStringContainsString('Skipped excluded: 3 (.obsidian/, .venv/, Privat/**, Bilder/**)', $display);
    }
}
