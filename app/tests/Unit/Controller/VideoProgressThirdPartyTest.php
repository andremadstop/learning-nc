<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PageController;
use OCA\Learning\Controller\VideoProgressController;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\VideoProgressService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-11 (H6): a Vimeo / YouTube video in a gated course could never be completed. The
 * learner's page got no provider video id (courseStatus left it out for every source), the app's
 * Content-Security-Policy blocked the provider iframe, and nothing sent heartbeats. These are the
 * server halves of the fix: the provider id reaches the learner, an instructor's file path still
 * does not, and the app page allows exactly the provider player frames.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoProgressThirdPartyTest extends TestCase {
    private function video(int $id, string $sourceType, string $ref): CourseVideo {
        $video = new CourseVideo();
        $video->setId($id);
        $video->setCourseId(7);
        $video->setSourceType($sourceType);
        $video->setVideoRef($ref);
        $video->setTitle('T' . $id);
        $video->setDurationSeconds(120);
        $video->setSortOrder($id);
        return $video;
    }

    /** @return array<int, array<string, mixed>> */
    private function courseStatusRows(): array {
        $videoMapper = $this->createMock(CourseVideoMapper::class);
        $videoMapper->method('findByCourse')->with(7)->willReturn([
            $this->video(1, 'nc-files', 'Videos/secret-folder/intro.mp4'),
            $this->video(2, 'youtube-nocookie', 'dQw4w9WgXcQ'),
            $this->video(3, 'vimeo', '76979871'),
            $this->video(4, 'document', 'Docs/handbook.pdf'),
        ]);
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn(null);

        $controller = new VideoProgressController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(VideoProgressService::class),
            $progress,
            $videoMapper,
            $this->createMock(CourseService::class),
            'student',
        );
        $data = $controller->courseStatus(7)->getData();
        $rows = [];
        foreach ($data['videos'] as $row) {
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    public function testTheLearnerGetsTheProviderVideoIdOfAThirdPartyVideo(): void {
        $rows = $this->courseStatusRows();

        $this->assertSame('dQw4w9WgXcQ', $rows[2]['embed_id']);
        $this->assertSame('76979871', $rows[3]['embed_id']);
    }

    public function testAnInstructorFilePathNeverReachesTheLearner(): void {
        $rows = $this->courseStatusRows();

        foreach ([1, 4] as $id) {
            $this->assertArrayNotHasKey('embed_id', $rows[$id]);
            $this->assertArrayNotHasKey('video_ref', $rows[$id]);
            $this->assertStringNotContainsString('secret-folder', json_encode($rows[$id]) ?: '');
        }
        $this->assertArrayNotHasKey('video_ref', $rows[2]);
    }

    public function testTheAppPageAllowsExactlyTheProviderPlayerFrames(): void {
        $page = new PageController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ConnectionStore::class),
        );

        $policy = $page->index()->getContentSecurityPolicy();

        // the YouTube player is only ever framed from the privacy-enhanced host, never www.youtube.com
        $this->assertSame(['https://www.youtube-nocookie.com', 'https://player.vimeo.com'], $policy->getAllowed('frame'));
        // the player SDKs are loaded from the providers after consent
        $scripts = $policy->getAllowed('script');
        $this->assertContains('https://www.youtube.com', $scripts);
        $this->assertContains('https://player.vimeo.com', $scripts);
        // nothing else is opened up
        $this->assertSame([], $policy->getAllowed('connect'));
        $this->assertSame([], $policy->getAllowed('child'));
    }
}
