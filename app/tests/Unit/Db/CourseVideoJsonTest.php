<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CourseVideo;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: the instructor list of required content showed every row as an untyped
 * "Required video", and the same document could be added again and again. The rows reached the
 * browser as {"id": N} only: CourseVideo had a jsonSerialize() method but did not implement
 * \JsonSerializable, so json_encode() saw nothing but the public $id of the Entity. Without
 * source_type and video_ref the list could neither label a row nor leave an added document out
 * of the selection.
 */
class CourseVideoJsonTest extends TestCase {
    public function testRegistryRowIsSerialisedWithAllFields(): void {
        $video = new CourseVideo();
        $video->setId(7);
        $video->setCourseId(3);
        $video->setSourceType('document');
        $video->setVideoRef('42');
        $video->setTitle('Chapter 1.pdf');
        $video->setSortOrder(2);
        $video->setCreatedAt(1700000000);

        // DataResponse renders its data with json_encode(), exactly like this.
        $decoded = json_decode((string)json_encode((new DataResponse([$video]))->getData()), true);

        $this->assertSame('document', $decoded[0]['source_type'] ?? null);
        $this->assertSame('42', $decoded[0]['video_ref'] ?? null);
        $this->assertSame('Chapter 1.pdf', $decoded[0]['title'] ?? null);
        $this->assertSame(3, $decoded[0]['course_id'] ?? null);
        $this->assertSame(7, $decoded[0]['id'] ?? null);
    }
}
