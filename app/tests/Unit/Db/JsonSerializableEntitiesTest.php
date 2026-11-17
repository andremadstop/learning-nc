<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #65 (C5 P11), after #75: an entity with a jsonSerialize() method that does not implement
 * \JsonSerializable is sent as {"id": N} when it lands in a DataResponse, because Nextcloud's
 * Entity does not implement the interface and json_encode() then sees only the public $id. The
 * method looks like it works, and it does whenever it is called by hand, so nobody notices.
 *
 * Every class in lib/Db that declares jsonSerialize() must implement \JsonSerializable, unless it
 * is listed below. A listed entity keeps its method for explicit, audience-checked calls, but a
 * raw response of it must fail closed ({"id": N}) instead of sending fields that some audience is
 * deliberately not given.
 */
class JsonSerializableEntitiesTest extends TestCase {
    /** class short name => why it must NOT implement \JsonSerializable */
    private const NOT_SERIALIZABLE = [
        'CourseDocument' => 'extracted_text, file_path and error_message go into no response; '
            . 'DocumentService::projectDocuments() builds the per-role view',
        'RagChunk' => 'chunk text and contributor user_id go to course managers and to a learner only '
            . 'for their own contributions',
        'SupportTicket' => 'AI triage, draft answer, context_json and answered_by: SupportTicketService '
            . 'shapes the row per path (serializeTicket / serializeForLearner)',
        'VideoProgress' => 'intervals_json is DSGVO-transient server state and never crosses to the client',
    ];

    /** @return array<string, \ReflectionClass<object>> short name => class, for each lib/Db class that declares jsonSerialize() */
    private static function entitiesWithJsonSerialize(): array {
        $dir = dirname(__DIR__, 3) . '/lib/Db';
        $files = glob($dir . '/*.php');
        self::assertNotEmpty($files, 'no PHP files found in ' . $dir);

        $found = [];
        foreach ($files as $file) {
            $short = basename($file, '.php');
            // Only files that mention the method are loaded: the mappers need far more of the
            // OCP stubs than this test is about.
            if (!str_contains((string)file_get_contents($file), 'function jsonSerialize(')) {
                continue;
            }
            $class = 'OCA\\Learning\\Db\\' . $short;
            self::assertTrue(class_exists($class), "$file declares jsonSerialize() but $class does not load");
            $ref = new \ReflectionClass($class);
            if ($ref->hasMethod('jsonSerialize') && $ref->getMethod('jsonSerialize')->getDeclaringClass()->getName() === $class) {
                $found[$short] = $ref;
            }
        }
        return $found;
    }

    public function testEveryEntityWithJsonSerializeImplementsTheInterface(): void {
        $entities = self::entitiesWithJsonSerialize();
        // Guards against a glob or filter that silently finds nothing.
        $this->assertGreaterThan(20, count($entities));

        $missing = [];
        foreach ($entities as $short => $ref) {
            if (!isset(self::NOT_SERIALIZABLE[$short]) && !$ref->implementsInterface(\JsonSerializable::class)) {
                $missing[] = $short;
            }
        }
        $this->assertSame([], $missing,
            'These entities have jsonSerialize() but are sent as {"id": N} in a DataResponse. Add '
            . '"implements \\JsonSerializable", or list them in NOT_SERIALIZABLE with the reason.');
    }

    public function testTheExceptionListIsCurrent(): void {
        $entities = self::entitiesWithJsonSerialize();
        foreach (self::NOT_SERIALIZABLE as $short => $reason) {
            $this->assertNotSame('', $reason);
            $this->assertArrayHasKey($short, $entities, "$short is listed but no longer declares jsonSerialize()");
            $this->assertFalse($entities[$short]->implementsInterface(\JsonSerializable::class),
                "$short is listed as deliberately not serializable but implements \\JsonSerializable");
        }
    }
}
