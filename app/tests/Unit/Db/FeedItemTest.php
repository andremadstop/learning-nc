<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\FeedItem;
use PHPUnit\Framework\TestCase;

/**
 * The feed contract the frontend reads (CourseTabKommunikation via the feed API): key names in
 * snake_case, meta decoded from its JSON column. Replaces a Vitest file that rebuilt this array
 * in JavaScript and never ran FeedItem::jsonSerialize() (audit #249).
 */
class FeedItemTest extends TestCase {

    public function testJsonSerializeExposesTheFeedContractInSnakeCase(): void {
        $item = FeedItem::fromRow([
            'id' => 7,
            'course_id' => 10,
            'type' => 'announcement',
            'title' => 'Exam moved',
            'body' => 'Now on Friday',
            'actor_id' => 'instructor1',
            'meta' => '{"pool_id":3,"tags":["a","b"]}',
            'created_at' => 1711612800,
        ]);

        $json = $item->jsonSerialize();

        $this->assertSame(
            ['id', 'course_id', 'type', 'title', 'body', 'actor_id', 'meta', 'created_at'],
            array_keys($json),
        );
        $this->assertSame(7, $json['id']);
        $this->assertSame(10, $json['course_id']);
        $this->assertSame('announcement', $json['type']);
        $this->assertSame('Exam moved', $json['title']);
        $this->assertSame('Now on Friday', $json['body']);
        $this->assertSame('instructor1', $json['actor_id']);
        $this->assertSame(['pool_id' => 3, 'tags' => ['a', 'b']], $json['meta']);
        $this->assertSame(1711612800, $json['created_at']);
    }

    public function testMissingMetaAndBodyStayNull(): void {
        $item = new FeedItem();
        $item->setCourseId(10);
        $item->setType('member_joined');
        $item->setTitle('Alex joined');
        $item->setCreatedAt(1711612800);

        $json = $item->jsonSerialize();

        $this->assertNull($json['meta']);
        $this->assertNull($json['body']);
        $this->assertNull($json['actor_id']);
        $this->assertNull($json['id']);
    }
}
