<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #43 against a real Nextcloud on both engines (scripts/nc-integration.sh): the new columns
 * and learning_cert_assets exist after install, a PNG is read from the instructor's own files and
 * stored, the certificate issued afterwards keeps that stamp after the course switches to another
 * one, and an image no certificate uses is removed by the cleanup sweep once the course has been
 * unchanged for the grace period.
 */
final class CertLayoutAssetsTest extends TestCase {
    private const TEACHER = 'gh43-teacher';
    private const OTHER = 'gh43-other';
    private const LEARNER = 'gh43-learner';

    private IDBConnection $db;
    private CertLayoutService $layouts;
    private CourseMapper $courses;
    private int $courseId = 0;

    public static function png(int $width, int $height, int $seed): string {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('tEXt', 'seed' . "\0" . $seed)
            . $chunk('IDAT', (string)gzcompress(str_repeat("\0", 1 + $width * 4)))
            . $chunk('IEND', '');
    }

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->layouts = Server::get(CertLayoutService::class);
        $this->courses = Server::get(CourseMapper::class);
        // One issuer key per instance: the first test that needs it creates it (GitHub #43 added a
        // second certificate test to the same run, so init() must not run twice).
        $keys = Server::get(KeyService::class);
        if (!$keys->hasActiveKey()) {
            $keys->init();
        }
        $users = Server::get(IUserManager::class);
        foreach ([self::TEACHER, self::OTHER] as $uid) {
            if (!$users->userExists($uid)) {
                $users->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
            }
        }
        $course = new Course();
        $course->setTitle('Arbeitsschutz 2026');
        $course->setInstructorId(self::TEACHER);
        $course->setStatus('active');
        $course->setCreatedAt(time());
        $course->setUpdatedAt(time());
        $course->setCertEnabled(true);
        $this->courseId = (int)$this->courses->insert($course)->getId();
    }

    protected function tearDown(): void {
        foreach (['learning_certificates', 'learning_cert_assets', 'learning_courses'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($table === 'learning_courses' ? 'id' : 'course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        }
        foreach ([self::TEACHER, self::OTHER] as $uid) {
            Server::get(IUserManager::class)->get($uid)?->delete();
        }
    }

    private function course(): Course {
        return $this->courses->findById($this->courseId);
    }

    private function put(string $uid, string $name, string $bytes): void {
        $folder = Server::get(IRootFolder::class)->getUserFolder($uid);
        if ($folder->nodeExists($name)) {
            $folder->get($name)->delete();
        }
        $folder->newFile($name, $bytes);
    }

    private function assetCount(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from('learning_cert_assets')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    public function testIssuedCertificateKeepsItsStampAndUnusedImagesGo(): void {
        $stampA = self::png(60, 40, 1);
        $stampB = self::png(60, 40, 2);
        $this->put(self::TEACHER, 'stamp-a.png', $stampA);
        $this->put(self::TEACHER, 'stamp-b.png', $stampB);

        $this->layouts->importImage($this->course(), 'stamp', self::TEACHER, '/stamp-a.png');
        $course = $this->course();
        $layout = CertLayout::decode($course->getCertLayout());
        $layout['signer_name'] = 'Maria Muster';
        $course->setCertLayout(CertLayout::encode($layout));
        $this->courses->update($course);

        $issued = Server::get(IssuanceService::class)
            ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
        $this->assertNotNull($issued);
        $cert = $issued->certificate;

        // The course switches to stamp B: the certificate still shows A, so A stays stored.
        $this->layouts->importImage($this->course(), 'stamp', self::TEACHER, '/stamp-b.png');
        $this->assertSame(2, $this->assetCount());
        $presentation = $this->layouts->presentationForCertificate($cert);
        $this->assertSame('data:image/png;base64,' . base64_encode($stampA), $presentation['stamp']);
        $this->assertSame('Maria Muster', $presentation['signer_name']);
        $this->assertSame('data:image/png;base64,' . base64_encode($stampB), $this->layouts->presentationForCourse($this->course())['stamp']);

        // Removing B (used by no certificate) keeps its bytes for the grace period: an issuance
        // that read the layout with B just before could still be inserting its certificate.
        $this->layouts->removeImage($this->course(), 'stamp');
        $this->assertSame(2, $this->assetCount());
        $this->assertSame(0, $this->layouts->sweepUnreferencedAssets(), 'the course changed just now');
        $this->assertSame(2, $this->assetCount());

        // An hour later the cleanup job deletes B; A stays with its certificate.
        $course = $this->course();
        $course->setUpdatedAt(time() - 7200);
        $this->courses->update($course);
        $this->assertSame(1, $this->layouts->sweepUnreferencedAssets());
        $this->assertSame(1, $this->assetCount());
        $this->assertSame('data:image/png;base64,' . base64_encode($stampA), $this->layouts->presentationForCertificate($cert)['stamp']);
    }

    /**
     * The app container builds every controller that hands out or changes a layout. The show
     * endpoints only draw the layout through the injected service, so a wiring gap would show
     * every certificate as the standard card without any error.
     */
    public function testTheAppContainerWiresTheLayoutIntoTheControllers(): void {
        $container = Server::get(\OCA\Learning\AppInfo\Application::class)->getContainer();
        foreach ([
            \OCA\Learning\Controller\CertificateController::class,
            \OCA\Learning\Controller\CourseCertificateController::class,
            \OCA\Learning\Controller\CertLayoutController::class,
        ] as $class) {
            $controller = $container->get($class);
            $property = new \ReflectionProperty($controller, 'layoutService');
            $this->assertInstanceOf(CertLayoutService::class, $property->getValue($controller), $class);
        }
    }

    public function testAnotherUsersFileIsNotReachable(): void {
        $this->put(self::OTHER, 'fremd.png', self::png(10, 10, 3));

        $this->expectException(NotFoundException::class);
        $this->layouts->importImage($this->course(), 'signature', self::TEACHER, '/fremd.png');
    }

    public function testCourseWithoutLayoutIssuesTheOldRowShape(): void {
        $issued = Server::get(IssuanceService::class)
            ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));

        $this->assertNotNull($issued);
        $this->assertNull($this->layouts->presentationForCertificate($issued->certificate));
    }
}
