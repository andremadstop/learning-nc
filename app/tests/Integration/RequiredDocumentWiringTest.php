<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\CourseVideoController;
use OCA\Learning\Controller\VideoProgressController;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Service\VideoProgressService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: the read-confirmation rule reaches every reader through Nextcloud's container.
 * The four classes take RequiredDocumentService as an optional dependency (older unit tests build
 * them without it); if the container did not inject it, the exam gate would fall back to the
 * pre-5.14 rule and "Confirm I have read this" would answer 400 — silently. This builds them the
 * way Nextcloud does and checks the dependency is there.
 */
class RequiredDocumentWiringTest extends TestCase {
    /** @return array<string, array{class-string, string}> */
    public static function readers(): array {
        return [
            'exam gate' => [VideoProgressService::class, 'requiredDocuments'],
            'learner status and confirmation' => [VideoProgressController::class, 'requiredDocuments'],
            'required column and ask again' => [CourseVideoController::class, 'requiredDocuments'],
            'coverage report' => [CertificateReportService::class, 'requiredDocuments'],
        ];
    }

    /**
     * @dataProvider readers
     * @param class-string $class
     */
    public function testTheContainerInjectsTheConfirmationRule(string $class, string $property): void {
        $instance = Server::get($class);
        $reflection = new \ReflectionProperty($class, $property);
        $this->assertInstanceOf(RequiredDocumentService::class, $reflection->getValue($instance));
    }

    public function testTheGateAlsoGetsTheCourseMapper(): void {
        $reflection = new \ReflectionProperty(VideoProgressService::class, 'courseMapper');
        $this->assertNotNull($reflection->getValue(Server::get(VideoProgressService::class)));
    }
}
