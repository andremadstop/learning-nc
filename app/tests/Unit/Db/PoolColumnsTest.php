<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\Pool;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit #148: Pool declared a property 'visibility' that no migration ever added to
 * learning_pools. The first setVisibility() on a pool would have made insert/update fail with
 * "column visibility does not exist". Every entity field must be a column some migration creates.
 */
class PoolColumnsTest extends TestCase {
    /** @return list<string> columns the migrations add to learning_pools */
    private static function poolColumnsFromMigrations(): array {
        $columns = [];
        foreach (glob(__DIR__ . '/../../../lib/Migration/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            // Split at each "$table = $schema->getTable('…')" / createTable, keep the table name.
            $parts = preg_split('/\$table\s*=\s*\$schema->(?:get|create)Table\(\s*\'([a-z_]+)\'\s*\)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            for ($i = 1; $i < count($parts); $i += 2) {
                if ($parts[$i] !== 'learning_pools') {
                    continue;
                }
                preg_match_all('/->addColumn\(\s*\'([a-z_]+)\'/', $parts[$i + 1] ?? '', $m);
                $columns = array_merge($columns, $m[1]);
            }
        }
        return array_values(array_unique($columns));
    }

    public function testEveryPoolFieldIsAColumnOfLearningPools(): void {
        $columns = self::poolColumnsFromMigrations();
        $this->assertContains('name', $columns, 'the migration scan found the pools table');

        $fields = array_map(
            static fn(string $field): string => strtolower((string)preg_replace('/[A-Z]/', '_$0', $field)),
            array_map(
                static fn(\ReflectionProperty $p): string => $p->getName(),
                array_filter(
                    (new \ReflectionClass(Pool::class))->getProperties(\ReflectionProperty::IS_PROTECTED),
                    static fn(\ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === Pool::class
                )
            )
        );

        $this->assertSame([], array_values(array_diff($fields, $columns)));
    }
}
