<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task B1, Version010500: consent bound to a recipient, the revocation epoch and the
 * budget table — on a fresh install, where only changeSchema() runs.
 */
class ConsentSchemaTest extends TestCase {
    private const UID = 'b1-consent-schema-probe';

    private IDBConnection $db;
    private string $prefix;
    private UserTelosMapper $mapper;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
        $this->mapper = Server::get(UserTelosMapper::class);
        $this->deleteProbeRow();
    }

    protected function tearDown(): void {
        $this->deleteProbeRow();
    }

    public function testUserTelosHasTheConsentColumns(): void {
        $table = $this->db->createSchema()->getTable($this->prefix . 'learning_user_telos');

        foreach (['ai_consent_fingerprint', 'ai_consent_source', 'ai_consent_at'] as $col) {
            $this->assertTrue($table->hasColumn($col), "missing column {$col}");
            $this->assertFalse($table->getColumn($col)->getNotnull(), "{$col} must be nullable (no backfill)");
        }
        $this->assertSame(64, $table->getColumn('ai_consent_fingerprint')->getLength());
        $this->assertSame(16, $table->getColumn('ai_consent_source')->getLength());
        $this->assertTrue($table->hasColumn('consent_epoch'));
        $this->assertTrue($table->getColumn('consent_epoch')->getNotnull());
    }

    public function testBudgetTableHasAUniqueBucketWindow(): void {
        $table = $this->db->createSchema()->getTable($this->prefix . 'learning_llm_budget');

        foreach (['id', 'bucket', 'window_start', 'count', 'inflight', 'updated_at'] as $col) {
            $this->assertTrue($table->hasColumn($col), "missing column {$col}");
        }
        $this->assertTrue($table->hasIndex('llm_budget_bucket_uq'));
        $index = $table->getIndex('llm_budget_bucket_uq');
        $this->assertTrue($index->isUnique());
        $this->assertSame(['bucket', 'window_start'], array_map(fn (string $c) => strtolower(trim($c, '`"')), $index->getColumns()));
    }

    /** A row written without the epoch gets the column default, and the increment is atomic SQL. */
    public function testConsentRoundTripAndEpochIncrement(): void {
        $row = new UserTelos();
        $row->setUserId(self::UID);
        $row->setCreatedAt(time());
        $row->setUpdatedAt(time());
        $row->setAiConsentVersion('3.0');
        $row->setAiConsentFingerprint(str_repeat('a', 64));
        $row->setAiConsentSource('onboarding');
        $row->setAiConsentAt(1_700_000_000);
        $this->mapper->insert($row);

        $read = $this->mapper->findByUserId(self::UID);
        $this->assertSame(str_repeat('a', 64), $read->getAiConsentFingerprint());
        $this->assertSame('onboarding', $read->getAiConsentSource());
        $this->assertSame(1_700_000_000, $read->getAiConsentAt());
        $this->assertSame(0, $read->getConsentEpoch());

        $this->mapper->incrementConsentEpoch(self::UID);
        $this->mapper->incrementConsentEpoch(self::UID);

        $this->assertSame(2, $this->mapper->findByUserId(self::UID)->getConsentEpoch());
    }

    private function deleteProbeRow(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_telos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->executeStatement();
    }
}
