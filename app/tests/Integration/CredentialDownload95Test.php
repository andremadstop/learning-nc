<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #95 against a real Nextcloud on both engines (scripts/nc-integration.sh): the course
 * switch "credential_download" only hides the "Technical details" section in the learner's view.
 * It is stored through the real mode-config save, show() reports it, and the download route still
 * answers 200 with the very same file as with the switch on (the issue keeps the download, the
 * verification page and the API unchanged).
 */
final class CredentialDownload95Test extends TestCase {
    private const TEACHER = 'gh95-teacher';
    private const LEARNER = 'gh95-learner';

    private IDBConnection $db;
    private int $courseId = 0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $keys = Server::get(KeyService::class);
        if (!$keys->hasActiveKey()) {
            $keys->init();
        }
        $course = new Course();
        $course->setTitle('Erste Hilfe 2026');
        $course->setInstructorId(self::TEACHER);
        $course->setStatus('active');
        $course->setCreatedAt(time());
        $course->setUpdatedAt(time());
        $course->setCertEnabled(true);
        $this->courseId = (int)Server::get(CourseMapper::class)->insert($course)->getId();
    }

    protected function tearDown(): void {
        foreach (['learning_certificates', 'learning_cert_assets', 'learning_courses'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($table === 'learning_courses' ? 'id' : 'course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }
    }

    private function controller(): CertificateController {
        return new CertificateController(
            'learning',
            Server::get(IRequest::class),
            Server::get(CertificateMapper::class),
            Server::get(CourseService::class),
            Server::get(ITimeFactory::class),
            self::LEARNER,
            Server::get(AuditService::class),
            $this->db,
            Server::get(CertLayoutService::class)
        );
    }

    private function downloadBytes(string $verificationId): string {
        $response = $this->controller()->download($verificationId);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        return $response->render();
    }

    public function testHidingTheSectionKeepsTheDownloadAndItsFile(): void {
        $issued = Server::get(IssuanceService::class)
            ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
        $this->assertNotNull($issued);
        $vid = (string)$issued->certificate->getVerificationId();

        $on = $this->controller()->show($vid)->getData();
        $this->assertTrue($on['credential_download_allowed'], 'a course saved before the switch shows the section');
        $before = $this->downloadBytes($vid);
        $this->assertNotSame('', $before);

        $saved = Server::get(CourseService::class)->updateModeConfig($this->courseId, self::TEACHER, ['credential_download' => false]);
        $this->assertFalse($saved['credential_download']);
        $this->assertFalse(Server::get(CourseService::class)->isCredentialDownloadEnabled($this->courseId), 'stored, not only answered');

        $off = $this->controller()->show($vid)->getData();
        $this->assertFalse($off['credential_download_allowed']);
        $this->assertSame($on['credential_json'], $off['credential_json'], 'the card still gets its credential');
        $this->assertSame($before, $this->downloadBytes($vid), 'same file with the section hidden');

        // A later save of another switch without the key would turn it back on; the client always
        // sends it (Vitest), and an explicit false survives a save that names it.
        $again = Server::get(CourseService::class)->updateModeConfig($this->courseId, self::TEACHER, ['linkedin' => false, 'credential_download' => false]);
        $this->assertFalse($again['credential_download']);
        $this->assertFalse($again['linkedin']);
    }
}
