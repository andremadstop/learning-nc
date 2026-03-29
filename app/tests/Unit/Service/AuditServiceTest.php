<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditServiceTest extends TestCase {
    public function testLogEventInsertsCorrectValues(): void {
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $db = new FakeDbConnection([$insertBuilder]);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new AuditService($db, $logger);
        $service->logEvent('moderation_action', 'user1', ['action' => 'approve']);

        $this->assertSame('insert', $insertBuilder->operation);
        $this->assertSame('learning_audit_events', $insertBuilder->table);

        $values = $insertBuilder->insertValues;
        $this->assertSame('moderation_action', $values['event_key']['value']);
        $this->assertSame('user1', $values['user_id']['value']);
        $this->assertSame('{"action":"approve"}', $values['context_json']['value']);
        $this->assertIsInt($values['created_at']['value']);
    }

    public function testLogEventDoesNotThrowOnDbError(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error');

        // Use a mock that throws on getQueryBuilder
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB down'));

        $service = new AuditService($db, $logger);
        // Should not throw
        $service->logEvent('test', 'user1', []);
    }

    public function testLogEventWithEmptyContext(): void {
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $db = new FakeDbConnection([$insertBuilder]);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new AuditService($db, $logger);
        $service->logEvent('some_event', 'user2');

        $values = $insertBuilder->insertValues;
        $this->assertSame('[]', $values['context_json']['value']);
    }
}
