<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PoolService::validateForImport() refuses exactly what create() refuses for the handbook and
 * chapter fields — the pool import runs it in its dry run (GitHub #34).
 */
#[AllowMockObjectsWithoutExpectations]
class PoolServiceValidateForImportTest extends TestCase {
    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?int, 5: ?string}> */
    public static function fields(): array {
        return [
            'handbook_key 65' => [str_repeat('h', 65), null, null, null, null, 'Field exceeds maximum length'],
            'handbook_title 256' => [null, str_repeat('h', 256), null, null, null, 'Field exceeds maximum length'],
            'chapter_key 65' => [null, null, str_repeat('k', 65), null, null, 'Field exceeds maximum length'],
            'chapter_title 256' => [null, null, null, str_repeat('t', 256), null, 'Field exceeds maximum length'],
            'chapter_order 0' => [null, null, null, null, 0, 'Chapter order must be between 1 and 9999'],
            'all at their limits' => [str_repeat('h', 64), str_repeat('h', 255), str_repeat('k', 64), str_repeat('t', 255), 9999, null],
            'all empty' => [null, '  ', null, null, null, null],
        ];
    }

    #[DataProvider('fields')]
    public function testValidateForImportRefusesWhatCreateRefuses(?string $hk, ?string $ht, ?string $ck, ?string $ct, ?int $co, ?string $error): void {
        $mapper = $this->createMock(PoolMapper::class);
        $mapper->method('createOrUpdate')->willReturnCallback(static fn (Pool $p): Pool => $p);
        $service = new PoolService($mapper, $this->createMock(PoolShareMapper::class), new FakeDbConnection());

        foreach ([
            'validateForImport' => static fn () => $service->validateForImport($hk, $ht, $ck, $ct, $co),
            'create' => static fn () => $service->create('Pool', null, 'teacher', $hk, $ht, $ck, $ct, $co),
        ] as $method => $call) {
            try {
                $call();
                $this->assertNull($error, $method . ' accepted what it should refuse');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($error, $e->getMessage(), $method);
            }
        }
    }
}
