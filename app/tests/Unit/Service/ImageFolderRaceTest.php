<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ImageService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * (Pre-existing) A person's first two question images uploaded at the same moment (two tabs, a
 * bulk edit): both requests see no Learning/images folder and both create it. The second
 * newFolder() fails although the folder now exists, and that upload failed with it. Found in the
 * review of the PHPStan baseline clean-up (27.11.2026).
 */
#[AllowMockObjectsWithoutExpectations]
final class ImageFolderRaceTest extends TestCase {
    private string $png;

    protected function setUp(): void {
        // a real 1x1 PNG, so finfo detects image/png
        $this->png = (string)tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($this->png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void {
        @unlink($this->png);
    }

    private function upload(Folder $userFolder): string {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);
        return (new ImageService($root))->upload('lena', ['tmp_name' => $this->png, 'size' => filesize($this->png)]);
    }

    public function testAFolderCreatedByAParallelUploadIsUsed(): void {
        $imageDir = $this->createMock(Folder::class);
        $imageDir->expects($this->once())->method('newFile');

        $userFolder = $this->createMock(Folder::class);
        // missing at the first look; the parallel request has created it since
        $userFolder->method('nodeExists')->willReturn(false);
        $userFolder->method('newFolder')->willThrowException(new NotPermittedException('Could not create folder'));
        $userFolder->method('get')->willReturn($imageDir);

        $this->assertStringStartsWith('lena/Learning/images/q_', $this->upload($userFolder));
    }

    public function testAFolderThatCannotBeCreatedIsStillAnError(): void {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('nodeExists')->willReturn(false);
        $userFolder->method('newFolder')->willThrowException(new NotPermittedException('Could not create folder'));
        $userFolder->method('get')->willThrowException(new \OCP\Files\NotFoundException('Learning/images'));

        $this->expectException(NotPermittedException::class);
        $this->upload($userFolder);
    }
}
