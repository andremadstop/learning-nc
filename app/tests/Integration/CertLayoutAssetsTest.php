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
        $this->backdateAssets();
        $course = $this->course();
        $course->setUpdatedAt(time() - 7200);
        $this->courses->update($course);
        $this->assertSame(1, $this->layouts->sweepUnreferencedAssets());
        $this->assertSame(1, $this->assetCount());
        $this->assertSame('data:image/png;base64,' . base64_encode($stampA), $this->layouts->presentationForCertificate($cert)['stamp']);
    }

    /**
     * GitHub #78 on a real Nextcloud: a library logo in the app data, chosen for the course, signed
     * into a certificate's layout. Deleting it from the library sets the course back to the Theming
     * logo; the certificate keeps its copy on the card and on the public verify page, and the
     * cleanup sweep leaves that copy alone.
     */
    public function testIssuedCertificateKeepsItsLibraryLogo(): void {
        $library = Server::get(\OCA\Learning\Service\CertLogoLibrary::class);
        $bytes = self::png(120, 40, 78);
        $logo = $library->add('Primary (wordmark)', $bytes);
        $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);
        try {
            $this->layouts->selectLogo($this->course(), $logo['id']);
            $this->assertSame($dataUrl, $this->layouts->presentationForCourse($this->course())['logo']);

            $issued = Server::get(IssuanceService::class)
                ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
            $this->assertNotNull($issued);
            $cert = $issued->certificate;

            $library->delete($logo['id']);
            $this->assertSame(1, $this->layouts->resetLogo($logo['id']));
            $this->assertNull(CertLayout::decode($this->course()->getCertLayout())['logo_sha'], 'the course is back on the Theming logo');
            $this->assertNull($this->layouts->presentationForCourse($this->course())['logo']);

            $this->assertSame($dataUrl, $this->layouts->presentationForCertificate($cert)['logo']);
            $verify = Server::get(\OCA\Learning\Service\CertificateVerifyService::class)
                ->verifyByVerificationId((string)$cert->getVerificationId());
            $this->assertSame('valid', $verify['status']);
            $this->assertSame($dataUrl, $verify['issuer_logo']);

            $this->backdateAssets();
            $course = $this->course();
            $course->setUpdatedAt(time() - 7200);
            $this->courses->update($course);
            $this->assertSame(0, $this->layouts->sweepUnreferencedAssets(), 'the certificate still uses the logo');
            $this->assertSame($dataUrl, $this->layouts->presentationForCertificate($cert)['logo']);
        } finally {
            if ($library->find($logo['id']) !== null) {
                $library->delete($logo['id']);
            }
        }
    }

    /**
     * GitHub #93 on a real Nextcloud, both engines: a large library background (a noisy photo far
     * over the 64 KB of a MySQL TEXT column, re-encoded on upload) chosen for the course and frozen
     * into a certificate. Deleting it from the library leaves the course without background; the
     * certificate keeps its copy, the cleanup sweep leaves that copy alone, and the public verify
     * page shows no background (ZC6: not before the decision on it).
     */
    public function testIssuedCertificateKeepsItsLibraryBackground(): void {
        $library = Server::get(\OCA\Learning\Service\CertBackgroundLibrary::class);
        mt_srand(93);
        $image = imagecreatetruecolor(1500, 1000);
        for ($y = 0; $y < 1000; $y++) {
            for ($x = 0; $x < 1500; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 95);
        $upload = (string)ob_get_clean();
        $this->assertLessThan(\OCA\Learning\Service\CertBackgroundLibrary::MAX_BYTES, strlen($upload));

        $background = $library->add('Northwind photo', $upload);
        try {
            $stored = $library->content($background['id'])['bytes'];
            $this->assertGreaterThan(\OCA\Learning\Service\CertLogoLibrary::MAX_BYTES, strlen($stored), 'near the limit, over the logo limit');
            $dataUrl = 'data:image/jpeg;base64,' . base64_encode($stored);

            $this->layouts->selectBackground($this->course(), $background['id'], 60);
            $this->assertSame(1, $this->layouts->countCoursesUsingBackground($background['id']));
            $preview = $this->layouts->presentationForCourse($this->course());
            $this->assertSame($dataUrl, $preview['background'], 'the whole image came back from the database');
            $this->assertSame(60, $preview['background_intensity']);

            $issued = Server::get(IssuanceService::class)
                ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
            $this->assertNotNull($issued);
            $cert = $issued->certificate;

            $library->delete($background['id']);
            $this->assertSame(1, $this->layouts->resetBackground($background['id']));
            $this->assertSame(0, $this->layouts->countCoursesUsingBackground($background['id']));
            $this->assertNull($this->layouts->presentationForCourse($this->course())['background']);
            $this->assertNull($this->course()->getCertLayout(), 'without background the course is on the standard layout again');

            $presentation = $this->layouts->presentationForCertificate($cert);
            $this->assertSame($dataUrl, $presentation['background'] ?? null);
            $this->assertSame(60, $presentation['background_intensity'] ?? null);

            $verify = Server::get(\OCA\Learning\Service\CertificateVerifyService::class)
                ->verifyByVerificationId((string)$cert->getVerificationId());
            $this->assertSame('valid', $verify['status']);
            $this->assertStringNotContainsString(base64_encode(substr($stored, 0, 300)), (string)json_encode($verify), 'no background on the public verify page');

            $this->backdateAssets();
            $course = $this->course();
            $course->setUpdatedAt(time() - 7200);
            $this->courses->update($course);
            $this->layouts->sweepUnreferencedAssets();
            $this->assertSame($dataUrl, $this->layouts->presentationForCertificate($cert)['background'] ?? null, 'the certificate still uses the background');
        } finally {
            if ($library->find($background['id']) !== null) {
                $library->delete($background['id']);
            }
        }
    }

    /** GitHub #93: a built-in background is only named in the layout and frozen like the rest. */
    public function testIssuedCertificateKeepsItsBuiltInBackground(): void {
        $this->layouts->selectBackground($this->course(), 'builtin:frame', 80);
        $issued = Server::get(IssuanceService::class)
            ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
        $this->assertNotNull($issued);
        $this->layouts->selectBackground($this->course(), 'builtin:lines', 30);

        $presentation = $this->layouts->presentationForCertificate($issued->certificate);
        $this->assertSame('frame', $presentation['background_builtin'] ?? null);
        $this->assertSame(80, $presentation['background_intensity'] ?? null);
        $this->assertSame('lines', $this->layouts->presentationForCourse($this->course())['background_builtin']);
    }

    /**
     * GitHub #78 (review) on a real Nextcloud: a course on the Theming logo. The certificate keeps
     * the Theming logo of its issuance on the card and the public verify page after an admin
     * replaces the Theming logo; the cleanup sweep leaves that copy alone.
     */
    public function testIssuedCertificateKeepsTheThemingLogoOfItsIssuance(): void {
        $config = Server::get(\OCP\IConfig::class);
        $images = $this->themingImages();
        $hadMime = $config->getAppValue('theming', 'logoMime', '');
        $hadLogo = $images->fileExists('logo') ? $images->getFile('logo')->getContent() : null;
        $old = self::png(90, 30, 780);
        $new = self::png(90, 30, 781);
        $oldUrl = 'data:image/png;base64,' . base64_encode($old);
        try {
            $this->putThemingLogo($old);
            $issued = Server::get(IssuanceService::class)
                ->issueIfPassedResult(self::LEARNER, $this->courseId, new PassResult(true, 90, 80, true, time()));
            $this->assertNotNull($issued);
            $cert = $issued->certificate;

            // The admin replaces the Theming logo (theming's own upload path).
            $this->putThemingLogo($new);

            $presentation = $this->layouts->presentationForCertificate($cert);
            $this->assertSame($oldUrl, $presentation['logo'] ?? null, 'the card keeps the logo of its issuance');
            $this->assertFalse($presentation['logo_library'], 'not a library logo: no white plate');
            $verify = Server::get(\OCA\Learning\Service\CertificateVerifyService::class)
                ->verifyByVerificationId((string)$cert->getVerificationId());
            $this->assertSame('valid', $verify['status']);
            $this->assertSame($oldUrl, $verify['issuer_logo']);

            // The next certificate of the course gets the new logo.
            $this->assertSame(hash('sha256', $new), CertLayout::decode($this->layouts->snapshotForIssuance($this->course()))['theming_logo_sha']);

            // an hour later: the copy the certificate uses stays
            $this->backdateAssets();
            $course = $this->course();
            $course->setUpdatedAt(time() - 7200);
            $this->courses->update($course);
            $this->assertSame(1, $this->layouts->sweepUnreferencedAssets(), 'only the unused copy of the new logo goes');
            $this->assertSame($oldUrl, $this->layouts->presentationForCertificate($cert)['logo'] ?? null);
        } finally {
            if ($hadLogo !== null) {
                $this->putThemingLogo($hadLogo);
            } else {
                Server::get(\OCA\Theming\ImageManager::class)->delete('logo');
            }
            if ($hadMime === '') {
                $config->deleteAppValue('theming', 'logoMime');
            } else {
                $config->setAppValue('theming', 'logoMime', $hadMime);
            }
        }
    }

    /** The course's images were stored more than the sweep's grace period ago. */
    private function backdateAssets(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_cert_assets')->set('created_at', $qb->createNamedParameter(time() - 7200, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function themingImages(): \OCP\Files\SimpleFS\ISimpleFolder {
        $appData = Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('theming');
        try {
            $global = $appData->getFolder('global');
        } catch (NotFoundException $e) {
            $global = $appData->newFolder('global');
        }
        try {
            return $global->getFolder('images');
        } catch (NotFoundException $e) {
            return $global->newFolder('images');
        }
    }

    /**
     * Upload a Theming logo the way the theming app's own upload does (ThemingController::
     * uploadImage: ImageManager::updateImage, then ThemingDefaults::set('logoMime')), so the test
     * reads what Theming really stores, not what this test wrote.
     */
    private function putThemingLogo(string $bytes): void {
        $tmp = tempnam(sys_get_temp_dir(), 'gh78-logo');
        file_put_contents($tmp, $bytes);
        try {
            $mime = Server::get(\OCA\Theming\ImageManager::class)->updateImage('logo', $tmp);
            Server::get(\OCA\Theming\ThemingDefaults::class)->set('logoMime', $mime);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * GitHub #78: the verify service takes the layout service as an optional dependency; a wiring
     * gap would show every certificate's library logo as the Theming logo on the public page.
     */
    public function testTheAppContainerWiresTheLayoutIntoTheVerifyService(): void {
        $service = Server::get(\OCA\Learning\Service\CertificateVerifyService::class);
        $property = new \ReflectionProperty($service, 'layoutService');
        $this->assertInstanceOf(CertLayoutService::class, $property->getValue($service));
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
            \OCA\Learning\Controller\CertLogoController::class,
            \OCA\Learning\Controller\CertBackgroundController::class,
        ] as $class) {
            $controller = $container->get($class);
            $property = new \ReflectionProperty($controller, 'layoutService');
            $this->assertInstanceOf(CertLayoutService::class, $property->getValue($controller), $class);
        }
    }

    /**
     * GitHub #77 (review): the course settings record a changed organisation name in the
     * compliance chain only with both optional dependencies; without them the change goes
     * through unrecorded and nothing says so.
     */
    public function testTheAppContainerWiresTheIssuerRecordIntoTheCourseController(): void {
        $controller = Server::get(\OCA\Learning\AppInfo\Application::class)->getContainer()->get(\OCA\Learning\Controller\CourseController::class);
        $this->assertInstanceOf(\OCA\Learning\Service\AuditService::class, (new \ReflectionProperty($controller, 'auditService'))->getValue($controller));
        $this->assertInstanceOf(IDBConnection::class, (new \ReflectionProperty($controller, 'db'))->getValue($controller));
    }

    /**
     * Nextcloud builds the cleanup job from its info.xml class name alone, which neither PHPStan
     * nor the call graph sees: a wiring gap would only show as an image table that never shrinks.
     */
    public function testTheAppContainerBuildsTheImageCleanupJob(): void {
        $job = Server::get(\OCA\Learning\BackgroundJob\CertAssetCleanupJob::class);
        $property = new \ReflectionProperty($job, 'service');
        $this->assertInstanceOf(CertLayoutService::class, $property->getValue($job));
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
