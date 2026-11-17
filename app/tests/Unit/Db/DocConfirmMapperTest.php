<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\DocConfirmMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75 (review M1): a confirmation row of a document without version counts like one taken
 * over from before 5.14.0 (RequiredDocumentService::evaluate()). Only the migration may write
 * such a row; the mapper refuses it before touching the database.
 */
class DocConfirmMapperTest extends TestCase {
    public function testADocumentConfirmationWithoutVersionIsRefused(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $this->expectException(\InvalidArgumentException::class);
        (new DocConfirmMapper($db))->insert('anna', 31, 9, 5000, null, 12);
    }
}
