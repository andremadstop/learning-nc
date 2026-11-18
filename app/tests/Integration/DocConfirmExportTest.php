<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Service\DataExportService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Pre-existing gap (5.16.0, found in the C9 round of #86): the read confirmations of required
 * documents (learning_doc_confirms) were not part of the user's own-data export. Against the real
 * schema: every row of the user, newest first, a legacy path row with a null document id, nobody
 * else's rows.
 */
final class DocConfirmExportTest extends TestCase {
    private const COURSE = 990087;
    private const USER = 'i86_confirms';
    private const OTHER = 'i86_confirms_other';

    private IDBConnection $db;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    public function testTheConfirmationsOfTheUserAreExported(): void {
        $mapper = Server::get(DocConfirmMapper::class);
        $v1 = str_repeat('a', 64);
        $v2 = str_repeat('b', 64);
        $mapper->insert(self::USER, 501, self::COURSE, 1000, $v1, 31);
        $mapper->insert(self::USER, 501, self::COURSE, 2000, $v2, 31);   // a later version, append-only
        $mapper->insert(self::USER, 502, self::COURSE, 1500, null);       // legacy path row
        $mapper->insert(self::OTHER, 501, self::COURSE, 1000, $v1, 31);

        $export = Server::get(DataExportService::class)->exportForUser(self::USER)['document_confirmations'];

        $this->assertSame([
            ['course_id' => self::COURSE, 'document_id' => 31, 'content_id' => 501, 'document_version' => $v2, 'confirmed_at' => 2000],
            ['course_id' => self::COURSE, 'document_id' => null, 'content_id' => 502, 'document_version' => null, 'confirmed_at' => 1500],
            ['course_id' => self::COURSE, 'document_id' => 31, 'content_id' => 501, 'document_version' => $v1, 'confirmed_at' => 1000],
        ], $export);
    }

    private function cleanUp(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(DocConfirmMapper::TABLE)
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter([self::USER, self::OTHER], IQueryBuilder::PARAM_STR_ARRAY)));
        $qb->executeStatement();
    }
}
