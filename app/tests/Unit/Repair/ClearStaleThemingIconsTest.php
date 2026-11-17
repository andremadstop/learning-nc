<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\ClearStaleThemingIcons;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #81: theming kept the favicon and touch icon it rendered from the old app icon, because its
 * cache names carry no app version. The repair step deletes this app's cached icons after an update.
 */
class ClearStaleThemingIconsTest extends TestCase {
    /** @var list<string> */
    private array $deleted = [];

    private function file(string $name, ?\Throwable $onDelete = null): ISimpleFile {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getName')->willReturn($name);
        $file->method('delete')->willReturnCallback(function () use ($name, $onDelete): void {
            if ($onDelete !== null) {
                throw $onDelete;
            }
            $this->deleted[] = $name;
        });
        return $file;
    }

    /** @param list<ISimpleFile> $files */
    private function step(array $files, int $cacheBuster = 7, ?string &$openedPath = null): ClearStaleThemingIcons {
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn($files);
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturnCallback(function (string $path) use ($folder, &$openedPath): ISimpleFolder {
            $openedPath = $path;
            return $folder;
        });
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->with('theming')->willReturn($appData);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->with('theming', 'cachebuster', 0)->willReturn($cacheBuster);
        return new ClearStaleThemingIcons($factory, $config);
    }

    public function testOnlyTheLearningIconsInTheCurrentCacheFolderAreDeleted(): void {
        $opened = null;
        $step = $this->step([
            $this->file('favIcon-learning#00679e'),
            $this->file('touchIcon-learning#00679e'),
            $this->file('icon-learning-#00679eapp.svg'),
            $this->file('favIcon-core#00679e'),
            $this->file('touchIcon-files#00679e'),
            $this->file('favIcon-learningsuite#00679e'),
            $this->file('icon-learning_extra-#00679eapp.svg'),
            $this->file('theming-7.css'),
        ], 7, $opened);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with($this->stringContains('Cleared 3 '));
        $step->run($output);

        self::assertSame('global/7', $opened);
        self::assertSame(['favIcon-learning#00679e', 'touchIcon-learning#00679e', 'icon-learning-#00679eapp.svg'], $this->deleted);
    }

    public function testAMissingCacheFolderDoesNotThrow(): void {
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willThrowException(new NotFoundException());
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->willReturn($appData);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willReturn(0);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info');
        (new ClearStaleThemingIcons($factory, $config))->run($output);
    }

    public function testAnUnreadableCacheBusterDoesNotFailTheUpgrade(): void {
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->expects($this->never())->method('get');
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willThrowException(new \RuntimeException('stored as string'));

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with($this->stringContains('stored as string'));
        (new ClearStaleThemingIcons($factory, $config))->run($output);
    }

    public function testARefusedDeleteIsReportedAndTheRestIsStillDeleted(): void {
        $step = $this->step([
            $this->file('favIcon-learning#00679e', new NotPermittedException('read-only')),
            $this->file('touchIcon-learning#00679e'),
        ]);

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning')->with($this->stringContains('read-only'));
        $output->expects($this->once())->method('info')->with($this->stringContains('Cleared 1 '));
        $step->run($output);

        self::assertSame(['touchIcon-learning#00679e'], $this->deleted);
    }

    public function testTheStepRunsAfterEveryUpdate(): void {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
        self::assertNotFalse($xml);
        $postMigration = array_map('strval', $xml->xpath('/info/repair-steps/post-migration/step') ?: []);
        self::assertContains(ClearStaleThemingIcons::class, $postMigration);
    }
}
