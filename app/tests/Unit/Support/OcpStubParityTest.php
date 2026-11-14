<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Support;

use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Audit #199: the unit-test stubs in tests/Support/PhpUnitStubs.php had other constant values and
 * another class hierarchy than Nextcloud 33, so a test relying on them tested the stub. The
 * expected values here were read from the gate image (nextcloud:33, lib/public + Doctrine DBAL).
 */
class OcpStubParityTest extends TestCase {

    public function testQueryBuilderParameterTypesAreTheRealValues(): void {
        self::assertSame(0, IQueryBuilder::PARAM_NULL);
        self::assertSame(1, IQueryBuilder::PARAM_INT);
        self::assertSame(2, IQueryBuilder::PARAM_STR);
        self::assertSame('boolean', IQueryBuilder::PARAM_BOOL);
        self::assertSame(101, IQueryBuilder::PARAM_INT_ARRAY);
        self::assertSame(102, IQueryBuilder::PARAM_STR_ARRAY);
    }

    public function testDbExceptionReasonsAreTheRealValues(): void {
        self::assertSame(4, DbException::REASON_DATABASE_OBJECT_NOT_FOUND);
        self::assertSame(14, DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
    }

    public function testClassHierarchyMatchesNextcloud(): void {
        self::assertInstanceOf(Response::class, new DataResponse(['a' => 1], 201));
        self::assertSame(201, (new DataResponse([], 201))->getStatus());
        self::assertInstanceOf(TemplateResponse::class, new PublicTemplateResponse('learning', 'verify', ['x' => 1]));
        self::assertSame('public', (new PublicTemplateResponse('learning', 'verify'))->getRenderAs());
        self::assertInstanceOf(\InvalidArgumentException::class, new UnknownNotificationException());
    }

    public function testDataDownloadResponseTakesOnlyAString(): void {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore argument.type */
        new DataDownloadResponse(false, 'x.json', 'application/json');
    }

    public function testCommandTesterReportsTheRealStatusAndOutput(): void {
        $command = new class extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int {
                $output->writeln('something went wrong');
                return self::FAILURE;
            }
        };
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("something went wrong\n", $tester->getDisplay());
    }
}
