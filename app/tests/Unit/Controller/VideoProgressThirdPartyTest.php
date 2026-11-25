<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PageController;
use OCA\Learning\Controller\VideoProgressController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\OperatorInfo;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
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

    private function document(int $id, int $courseId, string $path, string $name): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId($courseId);
        $doc->setFilePath($path);
        $doc->setFileName($name);
        $doc->setFileType(str_ends_with($name, '.md') ? 'markdown' : 'pdf');
        return $doc;
    }

    /** @return array<int, array<string, mixed>> */
    private function courseStatusRows(?\OCA\Learning\Service\RequiredDocumentService $required = null): array {
        $videoMapper = $this->createMock(CourseVideoMapper::class);
        $videoMapper->method('findByCourse')->with(7)->willReturn([
            $this->video(1, 'nc-files', 'Videos/secret-folder/intro.mp4'),
            $this->video(2, 'youtube-nocookie', 'dQw4w9WgXcQ'),
            $this->video(3, 'vimeo', '76979871'),
            $this->video(4, 'document', 'Docs/handbook.pdf'),
            $this->video(5, 'document', '31'),
            // another course's material document
            $this->video(6, 'document', '32'),
            // a material document of an earlier folder: download would not serve it
            $this->video(8, 'document', '33'),
            // GitHub #110: a Markdown material
            $this->video(9, 'document', '34'),
        ]);
        $docs = [
            31 => $this->document(31, 7, '/Kurs/policy.pdf', 'policy.pdf'),
            32 => $this->document(32, 9, '/Kurs/policy.pdf', 'policy.pdf'),
            33 => $this->document(33, 7, '/Alt/old.pdf', 'old.pdf'),
            34 => $this->document(34, 7, '/Kurs/guide.md', 'guide.md'),
        ];
        $documentMapper = $this->createMock(CourseDocumentMapper::class);
        $documentMapper->method('findById')->willReturnCallback(static function (int $id) use ($docs): CourseDocument {
            if (!isset($docs[$id])) {
                throw new DoesNotExistException('none');
            }
            return $docs[$id];
        });
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Kurs');
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('assertEnrolledInCourse')->willReturn($course);
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn(null);

        $controller = new VideoProgressController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(VideoProgressService::class),
            $progress,
            $videoMapper,
            $courseService,
            'student',
            $documentMapper,
            $required,
        );
        $data = $controller->courseStatus(7)->getData();
        $rows = [];
        foreach ($data['videos'] as $row) {
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    /**
     * GitHub #90: the file at the document's path was deleted (or may not be handed out). The
     * learner got a link anyway, and it ended on a "Not found" page; now there is no link and the
     * page says the document cannot be opened right now.
     */
    public function testARequiredDocumentWhoseFileCannotBeServedHasNoLink(): void {
        $required = $this->createMock(\OCA\Learning\Service\RequiredDocumentService::class);
        $required->method('statesForUser')->willReturn([]);
        $required->method('fileInfo')->willReturn(null);

        $rows = $this->courseStatusRows($required);

        $this->assertNull($rows[5]['document_id']);
    }

    /**
     * GitHub #90 (review): a required file that is in the folder but cannot be handed out (a
     * view-only share) lost its id with its link. The learner's optional list leaves required
     * documents out by that id, so the compliance document showed up there again as "Optional".
     * The entry's id is sent on its own now; the link still is not.
     */
    public function testARequiredDocumentWithoutLinkStillNamesItsMaterialEntry(): void {
        $required = $this->createMock(\OCA\Learning\Service\RequiredDocumentService::class);
        $required->method('statesForUser')->willReturn([]);
        $required->method('fileInfo')->willReturn(null);

        $rows = $this->courseStatusRows($required);

        $this->assertNull($rows[5]['document_id']);
        $this->assertSame(31, $rows[5]['material_document_id']);
        $this->assertNull($rows[4]['material_document_id'], 'a legacy path row is no material entry');
        $this->assertNull($rows[6]['material_document_id'], 'another course\'s document is not named');
        $this->assertNull($rows[8]['material_document_id'], 'a document outside the current folder is not named');
        $this->assertArrayNotHasKey('material_document_id', $rows[1]);
    }

    public function testARequiredDocumentWhoseFileIsThereKeepsItsLink(): void {
        $required = $this->createMock(\OCA\Learning\Service\RequiredDocumentService::class);
        $required->method('statesForUser')->willReturn([]);
        $required->method('fileInfo')->willReturn(['version' => str_repeat('a', 64), 'mtime' => 1]);

        $this->assertSame(31, $this->courseStatusRows($required)[5]['document_id']);
    }

    /** GitHub #110: the type decides between Learning's Markdown reader and the browser's PDF view. */
    public function testARequiredDocumentNamesItsTypeOnlyWhenItHasALink(): void {
        $rows = $this->courseStatusRows();
        $this->assertSame('pdf', $rows[5]['document_type']);
        $this->assertSame('markdown', $rows[9]['document_type']);
        $this->assertNull($rows[4]['document_type'], 'no document, no type');
        $this->assertNull($rows[6]['document_type'], 'another course\'s document says nothing about itself');
        $this->assertArrayNotHasKey('document_type', $rows[1]);

        $required = $this->createMock(\OCA\Learning\Service\RequiredDocumentService::class);
        $required->method('fileInfo')->willReturn(null);
        $this->assertNull($this->courseStatusRows($required)[9]['document_type'], 'a file that cannot be served has no link and no type');
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

    /**
     * GitHub #35 (B4): a learner could not open a required document at all (no reference was
     * sent). The material-folder document id is sent now, so the page links document#download,
     * and only when that download would serve it; a path never is.
     */
    public function testARequiredDocumentCarriesItsMaterialDocumentIdOnly(): void {
        $rows = $this->courseStatusRows();

        $this->assertSame(31, $rows[5]['document_id']);
        $this->assertNull($rows[4]['document_id'], 'a legacy path row has no document to open');
        $this->assertNull($rows[6]['document_id'], 'another course\'s document is not offered');
        $this->assertNull($rows[8]['document_id'], 'a document outside the current folder is not offered');
        $this->assertArrayNotHasKey('document_id', $rows[1]);
        foreach ([4, 5, 6, 8] as $id) {
            $this->assertArrayNotHasKey('video_ref', $rows[$id]);
            $this->assertStringNotContainsString('Kurs', json_encode($rows[$id]) ?: '');
            $this->assertStringNotContainsString('Docs', json_encode($rows[$id]) ?: '');
        }
    }

    public function testTheAppPageAllowsExactlyTheProviderPlayerFrames(): void {
        $page = new PageController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ConnectionStore::class),
            new OperatorInfo($this->createMock(IConfig::class)),
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
